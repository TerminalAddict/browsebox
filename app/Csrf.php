<?php

declare(strict_types=1);

final class Csrf
{
    private const SESSION_KEY = '_browsebox_csrf';

    public function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[self::SESSION_KEY];
    }

    public function input(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public function verify(?string $token): bool
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;

        return is_string($stored) && is_string($token) && hash_equals($stored, $token);
    }

    public function requireValid(?string $token): void
    {
        if (!$this->verify($token)) {
            throw new RuntimeException('Invalid CSRF token.');
        }
    }
}
