<?php

declare(strict_types=1);

final class UploadManager
{
    private array $blockedExtensions;
    private string $pendingRoot;
    private string $manifestFile;
    private string $manifestLockFile;

    public function __construct(
        private readonly PathGuard $pathGuard,
        Config $config,
    ) {
        $this->blockedExtensions = array_map(
            static fn (mixed $value): string => strtolower((string) $value),
            (array) $config->get('blocked_upload_extensions', [])
        );

        $dataRoot = rtrim($config->requireString('data_root'), '/');
        $this->pendingRoot = $dataRoot . '/pending-uploads';
        $this->manifestFile = $this->pendingRoot . '/manifest.json';
        $this->manifestLockFile = $this->pendingRoot . '/manifest.lock';
    }

    public function uploadMany(string $targetRelativePath, array $files): array
    {
        $result = $this->uploadManyWithRelativePaths($targetRelativePath, $files, []);

        return $result['uploaded'];
    }

    public function uploadManyWithRelativePaths(string $targetRelativePath, array $files, array $relativePaths): array
    {
        $targetRelativePath = $this->pathGuard->normalizeRelativePath($targetRelativePath);
        $targetDirectory = $this->pathGuard->resolve($targetRelativePath, true);

        if (!is_dir($targetDirectory)) {
            throw new RuntimeException('Upload target is not a directory.');
        }

        $normalizedFiles = $this->normalizeFilesArray($files);

        if ($normalizedFiles === []) {
            throw new RuntimeException('No files were uploaded.');
        }

        if ($relativePaths !== [] && count($relativePaths) !== count($normalizedFiles)) {
            throw new RuntimeException('Dropped file metadata did not match uploaded files.');
        }

        $uploaded = [];
        $conflicts = [];
        $batchId = null;

        foreach ($normalizedFiles as $index => $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload failed with error code ' . (int) ($file['error'] ?? -1) . '.');
            }

            $originalName = (string) ($file['name'] ?? '');
            $fullPath = (string) ($file['full_path'] ?? '');
            $tmpName = (string) ($file['tmp_name'] ?? '');

            if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                throw new RuntimeException('Invalid uploaded file.');
            }

            $relativeUploadPath = $relativePaths[$index] ?? ($fullPath !== '' ? $fullPath : basename($originalName));
            $safeUploadPath = $this->pathGuard->validateUploadRelativePath($relativeUploadPath);
            $destinationRelativePath = $targetRelativePath === ''
                ? $safeUploadPath
                : $targetRelativePath . '/' . $safeUploadPath;

            $this->assertAllowedExtension($destinationRelativePath);

            $destinationPath = $this->pathGuard->resolve($destinationRelativePath);
            $destinationDir = dirname($destinationPath);

            if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
                throw new RuntimeException('Unable to create upload directory.');
            }

            if (file_exists($destinationPath)) {
                $batchId ??= bin2hex(random_bytes(8));
                $staged = $this->stagePendingUpload($batchId, $targetRelativePath, $destinationRelativePath, $safeUploadPath, $tmpName);
                $conflicts[] = $staged;
                continue;
            }

            if (!move_uploaded_file($tmpName, $destinationPath)) {
                throw new RuntimeException('Unable to move uploaded file.');
            }

            $uploaded[] = $destinationRelativePath;
        }

        return [
            'uploaded' => $uploaded,
            'conflicts' => $conflicts,
            'batch_id' => $batchId,
        ];
    }

    public function listPendingConflicts(string $targetRelativePath): array
    {
        $targetRelativePath = $this->pathGuard->normalizeRelativePath($targetRelativePath);
        $manifest = $this->loadManifest();
        $items = [];

        foreach ((array) ($manifest['batches'] ?? []) as $batchId => $batch) {
            if (!is_array($batch)) {
                continue;
            }

            foreach ((array) ($batch['items'] ?? []) as $itemId => $item) {
                if (!is_array($item)) {
                    continue;
                }

                if ((string) ($item['upload_root_relative_path'] ?? '') !== $targetRelativePath) {
                    continue;
                }

                $items[] = [
                    'batch_id' => (string) $batchId,
                    'item_id' => (string) $itemId,
                    'destination_relative_path' => (string) ($item['destination_relative_path'] ?? ''),
                    'relative_upload_path' => (string) ($item['relative_upload_path'] ?? ''),
                    'created_at' => (string) ($item['created_at'] ?? ''),
                ];
            }
        }

        usort($items, static function (array $left, array $right): int {
            return strcasecmp((string) $left['destination_relative_path'], (string) $right['destination_relative_path']);
        });

        return $items;
    }

    public function replacePendingConflict(string $batchId, string $itemId): string
    {
        $replacedPath = null;

        $this->withManifestLock(function (array &$manifest) use ($batchId, $itemId, &$replacedPath): void {
            $item = $this->getPendingItemFromManifest($manifest, $batchId, $itemId);
            $destinationRelativePath = (string) ($item['destination_relative_path'] ?? '');
            $destinationPath = $this->pathGuard->resolve($destinationRelativePath);
            $stagedPath = $this->pendingRoot . '/' . ltrim((string) ($item['staged_relative_path'] ?? ''), '/');
            $destinationDir = dirname($destinationPath);

            if (!is_file($stagedPath)) {
                $this->removePendingItem($manifest, $batchId, $itemId);
                throw new RuntimeException('Pending upload file is missing.');
            }

            if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
                throw new RuntimeException('Unable to prepare replacement directory.');
            }

            if (file_exists($destinationPath) && is_dir($destinationPath)) {
                throw new RuntimeException('Cannot replace a folder with a file upload.');
            }

            if (file_exists($destinationPath) && !unlink($destinationPath)) {
                throw new RuntimeException('Unable to replace existing file.');
            }

            if (!rename($stagedPath, $destinationPath)) {
                throw new RuntimeException('Unable to move replacement file into place.');
            }

            $this->removePendingItem($manifest, $batchId, $itemId);
            $replacedPath = $destinationRelativePath;
        });

        if (!is_string($replacedPath)) {
            throw new RuntimeException('Unable to replace pending upload.');
        }

        return $replacedPath;
    }

    public function cancelPendingConflict(string $batchId, string $itemId): string
    {
        $cancelledPath = null;

        $this->withManifestLock(function (array &$manifest) use ($batchId, $itemId, &$cancelledPath): void {
            $item = $this->getPendingItemFromManifest($manifest, $batchId, $itemId);
            $cancelledPath = (string) ($item['destination_relative_path'] ?? '');
            $stagedPath = $this->pendingRoot . '/' . ltrim((string) ($item['staged_relative_path'] ?? ''), '/');

            if (is_file($stagedPath)) {
                @unlink($stagedPath);
            }

            $this->removePendingItem($manifest, $batchId, $itemId);
        });

        if (!is_string($cancelledPath)) {
            throw new RuntimeException('Unable to cancel pending upload.');
        }

        return $cancelledPath;
    }

    private function stagePendingUpload(
        string $batchId,
        string $targetRelativePath,
        string $destinationRelativePath,
        string $safeUploadPath,
        string $tmpName,
    ): array {
        $staged = null;

        $this->withManifestLock(function (array &$manifest) use (
            $batchId,
            $targetRelativePath,
            $destinationRelativePath,
            $safeUploadPath,
            $tmpName,
            &$staged,
        ): void {
            $this->ensurePendingRoot();
            $itemId = bin2hex(random_bytes(8));
            $batchDirectory = $this->pendingRoot . '/' . $batchId;

            if (!is_dir($batchDirectory) && !mkdir($batchDirectory, 0775, true) && !is_dir($batchDirectory)) {
                throw new RuntimeException('Unable to create pending upload directory.');
            }

            $stagedRelativePath = $batchId . '/' . $itemId . '.upload';
            $stagedPath = $this->pendingRoot . '/' . $stagedRelativePath;

            if (!move_uploaded_file($tmpName, $stagedPath)) {
                throw new RuntimeException('Unable to stage conflicting upload.');
            }

            $manifest['batches'][$batchId]['items'][$itemId] = [
                'destination_relative_path' => $destinationRelativePath,
                'relative_upload_path' => $safeUploadPath,
                'upload_root_relative_path' => $targetRelativePath,
                'staged_relative_path' => $stagedRelativePath,
                'created_at' => date(DATE_ATOM),
            ];

            $staged = [
                'batch_id' => $batchId,
                'item_id' => $itemId,
                'destination_relative_path' => $destinationRelativePath,
                'relative_upload_path' => $safeUploadPath,
            ];
        });

        if (!is_array($staged)) {
            throw new RuntimeException('Unable to stage conflicting upload.');
        }

        return $staged;
    }

    private function getPendingItemFromManifest(array $manifest, string $batchId, string $itemId): array
    {
        $this->assertPendingId($batchId);
        $this->assertPendingId($itemId);
        $item = $manifest['batches'][$batchId]['items'][$itemId] ?? null;

        if (!is_array($item)) {
            throw new RuntimeException('Pending upload item was not found.');
        }

        return $item;
    }

    private function removePendingItem(array &$manifest, string $batchId, string $itemId): void
    {
        unset($manifest['batches'][$batchId]['items'][$itemId]);

        if (((array) ($manifest['batches'][$batchId]['items'] ?? [])) === []) {
            unset($manifest['batches'][$batchId]);
            $batchDirectory = $this->pendingRoot . '/' . $batchId;

            if (is_dir($batchDirectory)) {
                @rmdir($batchDirectory);
            }
        }
    }

    private function assertPendingId(string $value): void
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $value)) {
            throw new RuntimeException('Invalid pending upload reference.');
        }
    }

    private function ensurePendingRoot(): void
    {
        if (!is_dir($this->pendingRoot) && !mkdir($this->pendingRoot, 0775, true) && !is_dir($this->pendingRoot)) {
            throw new RuntimeException('Unable to create pending upload root.');
        }
    }

    private function loadManifest(): array
    {
        $this->ensurePendingRoot();

        if (!is_file($this->manifestFile)) {
            return ['batches' => []];
        }

        $raw = file_get_contents($this->manifestFile);

        if ($raw === false || trim($raw) === '') {
            return ['batches' => []];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Pending upload manifest is invalid.');
        }

        return [
            'batches' => is_array($decoded['batches'] ?? null) ? $decoded['batches'] : [],
        ];
    }

    private function saveManifest(array $manifest): void
    {
        $this->ensurePendingRoot();
        $tempFile = tempnam($this->pendingRoot, 'browsebox-pending-');

        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary pending upload manifest.');
        }

        $payload = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($payload === false || file_put_contents($tempFile, $payload, LOCK_EX) === false || !rename($tempFile, $this->manifestFile)) {
            @unlink($tempFile);
            throw new RuntimeException('Unable to write pending upload manifest.');
        }
    }

    private function withManifestLock(callable $callback): void
    {
        $this->ensurePendingRoot();
        $handle = fopen($this->manifestLockFile, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open pending-upload lock.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock pending-upload manifest.');
            }

            $manifest = $this->loadManifest();
            $callback($manifest);
            $this->saveManifest($manifest);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function assertAllowedExtension(string $relativePath): void
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        if ($extension !== '' && in_array($extension, $this->blockedExtensions, true)) {
            throw new RuntimeException('Blocked upload extension: ' . $extension);
        }
    }

    private function normalizeFilesArray(array $files): array
    {
        $names = $files['name'] ?? null;

        if (!is_array($names)) {
            return $files === [] ? [] : [$files];
        }

        $normalized = [];

        foreach (array_keys($names) as $index) {
            $item = [
                'name' => $files['name'][$index] ?? '',
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
                'full_path' => $files['full_path'][$index] ?? '',
            ];

            if (($item['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $normalized[] = $item;
        }

        return $normalized;
    }
}
