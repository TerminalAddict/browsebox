<?php

declare(strict_types=1);

final class FileResponder
{
    public function __construct(
        private readonly Config $config,
        private readonly PathGuard $pathGuard,
    ) {
    }

    public function serve(string $relativePath, bool $forceDownload = false): never
    {
        $relativePath = $this->pathGuard->normalizeRelativePath($relativePath, false);
        $fullPath = $this->pathGuard->resolve($relativePath, true);

        if (!is_file($fullPath)) {
            http_response_code(404);
            exit('Not found');
        }

        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $forceDownloadExtensions = array_map(
            static fn (mixed $value): string => strtolower((string) $value),
            (array) $this->config->get('force_download_extensions', [])
        );
        $blockedExtensions = array_map(
            static fn (mixed $value): string => strtolower((string) $value),
            (array) $this->config->get('blocked_upload_extensions', [])
        );
        $allowHtmlRendering = (bool) $this->config->get('allow_html_rendering', false);
        $sandboxPublicHtml = (bool) $this->config->get('sandbox_public_html', false);
        $inline = !$forceDownload;

        if (in_array($extension, $forceDownloadExtensions, true) || in_array($extension, $blockedExtensions, true)) {
            $inline = false;
        }

        if (in_array($extension, ['html', 'htm'], true) && !$allowHtmlRendering) {
            $inline = false;
        }

        $mimeType = $this->detectMimeType($fullPath, $extension);
        $isInlineHtml = $inline && in_array($extension, ['html', 'htm'], true);

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . (string) filesize($fullPath));
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes(basename($fullPath)) . '"');

        if ($isInlineHtml && $sandboxPublicHtml) {
            // Public HTML is intentionally supported, but it must not share a normal browser origin
            // with the management portal.
            header("Content-Security-Policy: sandbox allow-scripts allow-forms allow-downloads allow-modals");
        }

        readfile($fullPath);
        exit;
    }

    public function serveDirectoryArchive(string $relativePath): never
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZIP archive downloads are not available on this server.');
        }

        $relativePath = $this->pathGuard->normalizeRelativePath($relativePath, false);
        $directoryPath = $this->pathGuard->resolve($relativePath, true);

        if (!is_dir($directoryPath)) {
            throw new RuntimeException('Not found');
        }

        $archiveName = basename($relativePath) . '.zip';
        $tempFile = tempnam(sys_get_temp_dir(), 'browsebox-zip-');

        if ($tempFile === false) {
            throw new RuntimeException('Unable to create ZIP archive.');
        }

        $zip = new ZipArchive();
        $archiveOpen = false;

        try {
            $result = $zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            if ($result !== true) {
                throw new RuntimeException('Unable to open ZIP archive for writing.');
            }

            $archiveOpen = true;

            $this->addDirectoryToArchive($zip, $directoryPath, basename($relativePath));
            $zip->close();
            $archiveOpen = false;

            clearstatcache(true, $tempFile);

            header('Content-Type: application/zip');
            header('Content-Length: ' . (string) filesize($tempFile));
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            header('Content-Disposition: attachment; filename="' . addslashes($archiveName) . '"');

            readfile($tempFile);
            exit;
        } finally {
            if ($archiveOpen) {
                @$zip->close();
            }

            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    private function detectMimeType(string $fullPath, string $extension): string
    {
        $byExtension = [
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'mjs' => 'application/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'html' => 'text/html; charset=UTF-8',
            'htm' => 'text/html; charset=UTF-8',
            'txt' => 'text/plain; charset=UTF-8',
            'csv' => 'text/csv; charset=UTF-8',
            'xml' => 'application/xml; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'pdf' => 'application/pdf',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
        ];

        if (isset($byExtension[$extension])) {
            return $byExtension[$extension];
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = finfo_file($finfo, $fullPath);
                finfo_close($finfo);

                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        return 'application/octet-stream';
    }

    private function addDirectoryToArchive(ZipArchive $zip, string $directoryPath, string $archivePath): void
    {
        $zip->addEmptyDir($archivePath);
        $entries = scandir($directoryPath);

        if ($entries === false) {
            throw new RuntimeException('Unable to read folder for archive download.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $fullPath = $directoryPath . '/' . $entry;
            $entryArchivePath = $archivePath . '/' . $entry;

            if (is_dir($fullPath)) {
                $this->addDirectoryToArchive($zip, $fullPath, $entryArchivePath);
                continue;
            }

            if (!is_file($fullPath) || !$zip->addFile($fullPath, $entryArchivePath)) {
                throw new RuntimeException('Unable to add file to ZIP archive.');
            }
        }
    }
}
