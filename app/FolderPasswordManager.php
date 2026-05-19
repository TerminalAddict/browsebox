<?php

declare(strict_types=1);

final class FolderPasswordManager
{
    private const CONTROL_FILENAME = '.browsebox-password';
    private const SESSION_KEY = 'browsebox_public_folder_access';

    public function __construct(
        private readonly PathGuard $pathGuard,
    ) {
    }

    public function isFolderProtectedExact(string $relativePath): bool
    {
        return is_file($this->controlFilePath($relativePath));
    }

    public function protectedRootFor(string $relativePath): ?string
    {
        $candidatePath = $this->pathGuard->normalizeRelativePath($relativePath);

        if ($candidatePath !== '' && $this->existsAsFile($candidatePath)) {
            $candidatePath = $this->pathGuard->parent($candidatePath);
        }

        while (true) {
            if ($this->isFolderProtectedExact($candidatePath)) {
                return $candidatePath;
            }

            if ($candidatePath === '') {
                break;
            }

            $candidatePath = $this->pathGuard->parent($candidatePath);
        }

        return null;
    }

    public function isAccessGranted(string $relativePath, bool $adminBypass = false): bool
    {
        if ($adminBypass) {
            return true;
        }

        $protectedRoot = $this->protectedRootFor($relativePath);

        if ($protectedRoot === null) {
            return true;
        }

        return $this->isUnlocked($protectedRoot);
    }

    public function unlock(string $relativePath, string $password): bool
    {
        $password = trim($password);

        if ($password === '') {
            return false;
        }

        $protectedRoot = $this->protectedRootFor($relativePath);

        if ($protectedRoot === null) {
            return true;
        }

        $hash = $this->storedHashFor($protectedRoot);

        if ($hash === null || !password_verify($password, $hash)) {
            return false;
        }

        $_SESSION[self::SESSION_KEY] ??= [];
        $_SESSION[self::SESSION_KEY][$protectedRoot] = hash('sha256', $hash);

        return true;
    }

    public function setPassword(string $folderRelativePath, string $password): void
    {
        $password = trim($password);

        if ($password === '') {
            throw new RuntimeException('Folder password cannot be empty.');
        }

        $folderPath = $this->pathGuard->resolve($folderRelativePath, true);

        if (!is_dir($folderPath)) {
            throw new RuntimeException('Folder not found.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Unable to hash folder password.');
        }

        $this->writeControlFile($this->controlFilePath($folderRelativePath), $hash . PHP_EOL);
        $this->forgetUnlocked($folderRelativePath);
    }

    public function removePassword(string $folderRelativePath): void
    {
        $controlFile = $this->controlFilePath($folderRelativePath);

        if (!is_file($controlFile)) {
            throw new RuntimeException('Folder password is not set.');
        }

        if (!unlink($controlFile)) {
            throw new RuntimeException('Unable to remove folder password.');
        }

        $this->forgetUnlocked($folderRelativePath);
    }

    private function forgetUnlocked(string $protectedRoot): void
    {
        $protectedRoot = $this->pathGuard->normalizeRelativePath($protectedRoot);

        if (!isset($_SESSION[self::SESSION_KEY]) || !is_array($_SESSION[self::SESSION_KEY])) {
            return;
        }

        unset($_SESSION[self::SESSION_KEY][$protectedRoot]);
    }

    private function isUnlocked(string $protectedRoot): bool
    {
        $unlocked = $_SESSION[self::SESSION_KEY] ?? [];
        $hash = $this->storedHashFor($protectedRoot);

        return is_array($unlocked)
            && is_string($hash)
            && is_string($unlocked[$protectedRoot] ?? null)
            && hash_equals(hash('sha256', $hash), $unlocked[$protectedRoot]);
    }

    private function storedHashFor(string $folderRelativePath): ?string
    {
        $controlFile = $this->controlFilePath($folderRelativePath);

        if (!is_file($controlFile)) {
            return null;
        }

        $raw = file_get_contents($controlFile);

        if ($raw === false) {
            throw new RuntimeException('Unable to read folder password.');
        }

        $hash = trim($raw);

        return $hash === '' ? null : $hash;
    }

    private function controlFilePath(string $folderRelativePath): string
    {
        $folderPath = $this->pathGuard->resolve($folderRelativePath, true);

        if (!is_dir($folderPath)) {
            throw new RuntimeException('Folder not found.');
        }

        return rtrim($folderPath, '/') . '/' . self::CONTROL_FILENAME;
    }

    private function existsAsFile(string $relativePath): bool
    {
        try {
            $resolved = $this->pathGuard->resolve($relativePath, true);
        } catch (RuntimeException) {
            return false;
        }

        return is_file($resolved);
    }

    private function writeControlFile(string $controlFile, string $contents): void
    {
        $directory = dirname($controlFile);
        $tempFile = tempnam($directory, 'browsebox-password-');

        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary password file.');
        }

        try {
            if (file_put_contents($tempFile, $contents, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write folder password.');
            }

            chmod($tempFile, 0664);

            if (!rename($tempFile, $controlFile)) {
                throw new RuntimeException('Unable to save folder password.');
            }
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }
}
