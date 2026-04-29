<?php

declare(strict_types=1);

final class PathGuard
{
    private string $storageRoot;

    public function __construct(string $storageRoot)
    {
        $realRoot = realpath($storageRoot);

        if ($realRoot === false || !is_dir($realRoot)) {
            throw new RuntimeException('Invalid storage root: ' . $storageRoot);
        }

        $this->storageRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
    }

    public function storageRoot(): string
    {
        return $this->storageRoot;
    }

    public function normalizeRelativePath(?string $path, bool $allowEmpty = true): string
    {
        $path = $path ?? '';
        $path = rawurldecode($path);

        if (str_contains($path, "\0")) {
            throw new RuntimeException('Path contains null bytes.');
        }

        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = ltrim($path, '/');

        if ($path === '') {
            if ($allowEmpty) {
                return '';
            }

            throw new RuntimeException('Path cannot be empty.');
        }

        $parts = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new RuntimeException('Path traversal detected.');
            }

            if (str_starts_with($segment, '.')) {
                throw new RuntimeException('Hidden path segments are not allowed.');
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $segment)) {
                throw new RuntimeException('Path contains control characters.');
            }

            $parts[] = $segment;
        }

        $normalized = implode('/', $parts);

        if ($normalized === '' && !$allowEmpty) {
            throw new RuntimeException('Path cannot be empty.');
        }

        return $normalized;
    }

    public function validateUploadRelativePath(string $path): string
    {
        $normalized = $this->normalizeRelativePath($path, false);

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Unsafe upload path.');
            }
        }

        return $normalized;
    }

    public function validateName(string $name): string
    {
        if (str_contains($name, "\0")) {
            throw new RuntimeException('Name contains null bytes.');
        }

        $name = trim(str_replace('\\', '/', $name));

        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, '/')) {
            throw new RuntimeException('Invalid name.');
        }

        if (str_starts_with($name, '.')) {
            throw new RuntimeException('Hidden names are not allowed.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new RuntimeException('Name contains control characters.');
        }

        return $name;
    }

    public function resolve(string $relativePath = '', bool $mustExist = false): string
    {
        $normalized = $this->normalizeRelativePath($relativePath);
        $candidate = $this->storageRoot . ($normalized === '' ? '' : '/' . $normalized);

        if ($mustExist) {
            $real = realpath($candidate);

            if ($real === false) {
                throw new RuntimeException('Path does not exist.');
            }

            $real = str_replace('\\', '/', $real);

            if (!$this->isWithinRoot($real)) {
                throw new RuntimeException('Resolved path escapes storage root.');
            }

            return $real;
        }

        if (!$this->isWithinRoot($candidate)) {
            throw new RuntimeException('Resolved path escapes storage root.');
        }

        return $candidate;
    }

    public function join(string $baseRelativePath, string $name): string
    {
        $base = $this->normalizeRelativePath($baseRelativePath);
        $name = $this->validateName($name);

        return $base === '' ? $name : $base . '/' . $name;
    }

    public function parent(string $relativePath): string
    {
        $normalized = $this->normalizeRelativePath($relativePath);

        if ($normalized === '' || !str_contains($normalized, '/')) {
            return '';
        }

        return (string) dirname($normalized);
    }

    private function isWithinRoot(string $path): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return $path === $this->storageRoot || str_starts_with($path, $this->storageRoot . '/');
    }
}
