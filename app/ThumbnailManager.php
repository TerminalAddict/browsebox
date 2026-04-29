<?php

declare(strict_types=1);

final class ThumbnailManager
{
    private const THUMB_WIDTH = 320;
    private const THUMB_HEIGHT = 240;

    private string $thumbnailRoot;

    public function __construct(
        private readonly PathGuard $pathGuard,
    ) {
        $this->thumbnailRoot = dirname($this->pathGuard->storageRoot()) . '/thumbnails';
    }

    public function canGenerateFor(string $relativePath): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    public function serve(string $relativePath): never
    {
        $relativePath = $this->pathGuard->normalizeRelativePath($relativePath, false);
        $sourcePath = $this->pathGuard->resolve($relativePath, true);

        if (!$this->canGenerateFor($sourcePath) || !is_file($sourcePath)) {
            http_response_code(404);
            exit('Not found');
        }

        $thumbnailPath = $this->thumbnailPathFor($relativePath, $sourcePath);

        if (!is_file($thumbnailPath)) {
            $this->generateThumbnail($sourcePath, $thumbnailPath);
        }

        clearstatcache(true, $thumbnailPath);

        if (!is_file($thumbnailPath)) {
            http_response_code(404);
            exit('Not found');
        }

        header('Content-Type: image/png');
        header('Content-Length: ' . (string) filesize($thumbnailPath));
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($thumbnailPath);
        exit;
    }

    public function thumbnailRoot(): string
    {
        return $this->thumbnailRoot;
    }

    private function isAvailable(): bool
    {
        return function_exists('imagecreatetruecolor')
            && function_exists('imagepng')
            && function_exists('getimagesize');
    }

    private function thumbnailPathFor(string $relativePath, string $sourcePath): string
    {
        $signature = sha1($relativePath . '|' . (string) filesize($sourcePath) . '|' . (string) filemtime($sourcePath));

        return $this->thumbnailRoot . '/' . substr($signature, 0, 2) . '/' . $signature . '.png';
    }

    private function generateThumbnail(string $sourcePath, string $thumbnailPath): void
    {
        $directory = dirname($thumbnailPath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create thumbnail directory.');
        }

        $imageInfo = getimagesize($sourcePath);

        if ($imageInfo === false) {
            throw new RuntimeException('Unable to read image metadata.');
        }

        $sourceImage = $this->createSourceImage($sourcePath);

        if ($sourceImage === null) {
            throw new RuntimeException('Unable to load image for thumbnailing.');
        }

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);

        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($sourceImage);
            throw new RuntimeException('Image dimensions are invalid.');
        }

        $scale = min(self::THUMB_WIDTH / $sourceWidth, self::THUMB_HEIGHT / $sourceHeight);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $offsetX = (int) floor((self::THUMB_WIDTH - $targetWidth) / 2);
        $offsetY = (int) floor((self::THUMB_HEIGHT - $targetHeight) / 2);

        $thumbnail = imagecreatetruecolor(self::THUMB_WIDTH, self::THUMB_HEIGHT);

        if ($thumbnail === false) {
            imagedestroy($sourceImage);
            throw new RuntimeException('Unable to create thumbnail canvas.');
        }

        imagealphablending($thumbnail, false);
        imagesavealpha($thumbnail, true);
        $background = imagecolorallocatealpha($thumbnail, 244, 247, 251, 0);
        imagefill($thumbnail, 0, 0, $background);

        if (!imagecopyresampled(
            $thumbnail,
            $sourceImage,
            $offsetX,
            $offsetY,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        )) {
            imagedestroy($thumbnail);
            imagedestroy($sourceImage);
            throw new RuntimeException('Unable to render thumbnail.');
        }

        $tempFile = tempnam($directory, 'thumb-');

        if ($tempFile === false) {
            imagedestroy($thumbnail);
            imagedestroy($sourceImage);
            throw new RuntimeException('Unable to create thumbnail temporary file.');
        }

        try {
            if (!imagepng($thumbnail, $tempFile)) {
                throw new RuntimeException('Unable to write thumbnail.');
            }

            if (!rename($tempFile, $thumbnailPath)) {
                throw new RuntimeException('Unable to publish thumbnail.');
            }
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }

            imagedestroy($thumbnail);
            imagedestroy($sourceImage);
        }
    }

    private function createSourceImage(string $sourcePath): mixed
    {
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($sourcePath) : null,
            'png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($sourcePath) : null,
            'gif' => function_exists('imagecreatefromgif') ? @imagecreatefromgif($sourcePath) : null,
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : null,
            default => null,
        };
    }
}
