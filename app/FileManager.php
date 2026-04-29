<?php

declare(strict_types=1);

final class FileManager
{
    public function __construct(
        private readonly PathGuard $pathGuard,
    ) {
    }

    public function listDirectory(string $relativePath = ''): array
    {
        $directoryPath = $this->pathGuard->resolve($relativePath, true);

        if (!is_dir($directoryPath)) {
            throw new RuntimeException('Not a directory.');
        }

        $entries = scandir($directoryPath);

        if ($entries === false) {
            throw new RuntimeException('Unable to read directory.');
        }

        $items = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (str_starts_with($entry, '.')) {
                continue;
            }

            $itemRelativePath = $this->pathGuard->join($relativePath, $entry);
            $itemPath = $this->pathGuard->resolve($itemRelativePath, true);
            $isDir = is_dir($itemPath);

            $items[] = [
                'name' => $entry,
                'relative_path' => $itemRelativePath,
                'type' => $isDir ? 'dir' : 'file',
                'size' => $isDir ? null : filesize($itemPath),
                'modified' => filemtime($itemPath) ?: null,
                'icon' => $isDir ? 'folder' : $this->iconForFile($entry),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            if ($left['type'] !== $right['type']) {
                return $left['type'] === 'dir' ? -1 : 1;
            }

            return strcasecmp((string) $left['name'], (string) $right['name']);
        });

        return $items;
    }

    public function listDirectoryTree(string $relativePath = ''): array
    {
        $directoryPath = $this->pathGuard->resolve($relativePath, true);

        if (!is_dir($directoryPath)) {
            throw new RuntimeException('Not a directory.');
        }

        $entries = scandir($directoryPath);

        if ($entries === false) {
            throw new RuntimeException('Unable to read directory tree.');
        }

        $directories = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $itemRelativePath = $this->pathGuard->join($relativePath, $entry);
            $itemPath = $this->pathGuard->resolve($itemRelativePath, true);

            if (!is_dir($itemPath)) {
                continue;
            }

            $directories[] = [
                'name' => $entry,
                'relative_path' => $itemRelativePath,
                'children' => $this->listDirectoryTree($itemRelativePath),
            ];
        }

        usort($directories, static function (array $left, array $right): int {
            return strcasecmp((string) $left['name'], (string) $right['name']);
        });

        return $directories;
    }

    public function createDirectory(string $parentRelativePath, string $name): string
    {
        $relativePath = $this->pathGuard->join($parentRelativePath, $name);
        $fullPath = $this->pathGuard->resolve($relativePath);

        if (file_exists($fullPath)) {
            throw new RuntimeException('Folder already exists.');
        }

        if (!mkdir($fullPath, 0775, true) && !is_dir($fullPath)) {
            throw new RuntimeException('Unable to create folder.');
        }

        return $relativePath;
    }

    public function rename(string $relativePath, string $newName): string
    {
        $sourcePath = $this->pathGuard->resolve($relativePath, true);
        $targetRelativePath = $this->pathGuard->join($this->pathGuard->parent($relativePath), $newName);
        $targetPath = $this->pathGuard->resolve($targetRelativePath);

        if (file_exists($targetPath)) {
            throw new RuntimeException('Target already exists.');
        }

        if (!rename($sourcePath, $targetPath)) {
            throw new RuntimeException('Unable to rename item.');
        }

        return $targetRelativePath;
    }

    public function move(string $relativePath, string $destinationDirectoryRelativePath): string
    {
        $sourceRelativePath = $this->pathGuard->normalizeRelativePath($relativePath, false);
        $destinationDirectoryRelativePath = $this->pathGuard->normalizeRelativePath($destinationDirectoryRelativePath);

        $sourcePath = $this->pathGuard->resolve($sourceRelativePath, true);
        $destinationDirectoryPath = $this->pathGuard->resolve($destinationDirectoryRelativePath, true);

        if (!is_dir($destinationDirectoryPath)) {
            throw new RuntimeException('Move destination is not a directory.');
        }

        $name = basename($sourceRelativePath);
        $targetRelativePath = $destinationDirectoryRelativePath === ''
            ? $name
            : $destinationDirectoryRelativePath . '/' . $name;

        if ($targetRelativePath === $sourceRelativePath) {
            throw new RuntimeException('Item is already in that folder.');
        }

        if (is_dir($sourcePath) && str_starts_with($destinationDirectoryRelativePath . '/', $sourceRelativePath . '/')) {
            throw new RuntimeException('Cannot move a folder into itself.');
        }

        $targetPath = $this->pathGuard->resolve($targetRelativePath);

        if (file_exists($targetPath)) {
            throw new RuntimeException('Target already exists.');
        }

        if (!rename($sourcePath, $targetPath)) {
            throw new RuntimeException('Unable to move item.');
        }

        return $targetRelativePath;
    }

    public function delete(string $relativePath): void
    {
        $fullPath = $this->pathGuard->resolve($relativePath, true);

        if (is_dir($fullPath)) {
            $this->deleteDirectory($fullPath);
            return;
        }

        if (!unlink($fullPath)) {
            throw new RuntimeException('Unable to delete file.');
        }
    }

    public function exists(string $relativePath): bool
    {
        try {
            return file_exists($this->pathGuard->resolve($relativePath));
        } catch (RuntimeException) {
            return false;
        }
    }

    private function deleteDirectory(string $directoryPath): void
    {
        $entries = scandir($directoryPath);

        if ($entries === false) {
            throw new RuntimeException('Unable to read directory for deletion.');
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $childPath = $directoryPath . '/' . $entry;

            if (is_dir($childPath)) {
                $this->deleteDirectory($childPath);
                continue;
            }

            if (!unlink($childPath)) {
                throw new RuntimeException('Unable to delete file.');
            }
        }

        if (!rmdir($directoryPath)) {
            throw new RuntimeException('Unable to delete folder.');
        }
    }

    private function iconForFile(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'zip', 'tar', 'gz', 'tgz', '7z', 'rar' => 'archive',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' => 'image',
            'html', 'htm' => 'html',
            'pdf' => 'pdf',
            'mp3', 'wav', 'ogg', 'flac' => 'audio',
            'mp4', 'webm', 'mov', 'mkv' => 'video',
            default => 'file',
        };
    }
}
