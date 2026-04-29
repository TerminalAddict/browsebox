<?php

declare(strict_types=1);

final class UploadManager
{
    private array $blockedExtensions;

    public function __construct(
        private readonly PathGuard $pathGuard,
        Config $config,
    ) {
        $this->blockedExtensions = array_map(
            static fn (mixed $value): string => strtolower((string) $value),
            (array) $config->get('blocked_upload_extensions', [])
        );
    }

    public function uploadMany(string $targetRelativePath, array $files): array
    {
        return $this->uploadManyWithRelativePaths($targetRelativePath, $files, []);
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

            if (!move_uploaded_file($tmpName, $destinationPath)) {
                throw new RuntimeException('Unable to move uploaded file.');
            }

            $uploaded[] = $destinationRelativePath;
        }

        return $uploaded;
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
