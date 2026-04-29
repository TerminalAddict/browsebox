<?php

declare(strict_types=1);

final class Auth
{
    private string $usersFile;

    public function __construct(Config $config)
    {
        $this->usersFile = rtrim($config->requireString('data_root'), '/') . '/users.json';
        $this->startSession();
    }

    public function login(string $username, string $password): bool
    {
        $username = trim($username);

        if ($username === '' || $password === '') {
            return false;
        }

        foreach ($this->loadUsers() as $user) {
            if (($user['username'] ?? null) !== $username) {
                continue;
            }

            $hash = $user['password_hash'] ?? null;

            if (!is_string($hash) || !password_verify($password, $hash)) {
                return false;
            }

            session_regenerate_id(true);
            $_SESSION['browsebox_user'] = $username;

            return true;
        }

        return false;
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => $params['path'] ?? '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
                'samesite' => $params['samesite'] ?? 'Strict',
            ]);
            session_destroy();
        }
    }

    public function user(): ?string
    {
        $user = $_SESSION['browsebox_user'] ?? null;

        return is_string($user) && $user !== '' ? $user : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) === '443');

        session_name('browsebox_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_start();
    }

    private function loadUsers(): array
    {
        if (!is_file($this->usersFile)) {
            return [];
        }

        $raw = file_get_contents($this->usersFile);

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['users']) || !is_array($decoded['users'])) {
            return [];
        }

        return $decoded['users'];
    }
}
