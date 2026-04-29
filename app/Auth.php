<?php

declare(strict_types=1);

final class Auth
{
    private string $usersFile;
    private string $rememberTokensFile;
    private string $rememberCookieName = 'browsebox_remember';
    private int $rememberLifetime = 2592000;
    private bool $https;

    public function __construct(Config $config)
    {
        $this->usersFile = rtrim($config->requireString('data_root'), '/') . '/users.json';
        $this->rememberTokensFile = rtrim($config->requireString('data_root'), '/') . '/remember_tokens.json';
        $this->https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) === '443');
        $this->startSession();
        $this->attemptRememberedLogin();
    }

    public function login(string $username, string $password, bool $remember = false): bool
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
            $this->forgetRememberedLogin();

            if ($remember) {
                $this->issueRememberToken($username);
            }

            return true;
        }

        return false;
    }

    public function logout(): void
    {
        $this->forgetRememberedLogin();
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

        session_name('browsebox_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $this->https,
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

    private function attemptRememberedLogin(): void
    {
        if ($this->check()) {
            return;
        }

        $rawCookie = $_COOKIE[$this->rememberCookieName] ?? null;

        if (!is_string($rawCookie) || $rawCookie === '') {
            return;
        }

        [$selector, $validator] = $this->parseRememberCookie($rawCookie);

        if ($selector === null || $validator === null) {
            $this->clearRememberCookie();
            return;
        }

        $tokens = $this->loadRememberTokens();
        $this->pruneExpiredTokens($tokens);
        $matchIndex = null;
        $matchToken = null;

        foreach ($tokens['tokens'] as $index => $token) {
            if (($token['selector'] ?? '') !== $selector) {
                continue;
            }

            $matchIndex = $index;
            $matchToken = $token;
            break;
        }

        if (!is_int($matchIndex) || !is_array($matchToken)) {
            $this->clearRememberCookie();
            $this->saveRememberTokens($tokens);
            return;
        }

        $expiresAt = $matchToken['expires_at'] ?? 0;
        $tokenHash = $matchToken['token_hash'] ?? '';
        $username = $matchToken['username'] ?? '';

        if (!is_int($expiresAt) || $expiresAt < time() || !is_string($tokenHash) || !is_string($username) || !$this->userExists($username)) {
            unset($tokens['tokens'][$matchIndex]);
            $tokens['tokens'] = array_values($tokens['tokens']);
            $this->saveRememberTokens($tokens);
            $this->clearRememberCookie();
            return;
        }

        if (!hash_equals($tokenHash, hash('sha256', $validator))) {
            unset($tokens['tokens'][$matchIndex]);
            $tokens['tokens'] = array_values($tokens['tokens']);
            $this->saveRememberTokens($tokens);
            $this->clearRememberCookie();
            return;
        }

        session_regenerate_id(true);
        $_SESSION['browsebox_user'] = $username;
        $this->rotateRememberToken($tokens, $matchIndex, $username);
    }

    private function issueRememberToken(string $username): void
    {
        $tokens = $this->loadRememberTokens();
        $this->pruneExpiredTokens($tokens);

        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $expiresAt = time() + $this->rememberLifetime;

        $tokens['tokens'][] = [
            'selector' => $selector,
            'username' => $username,
            'token_hash' => hash('sha256', $validator),
            'expires_at' => $expiresAt,
        ];

        $this->saveRememberTokens($tokens);
        $this->setRememberCookie($selector, $validator, $expiresAt);
    }

    private function rotateRememberToken(array $tokens, int $index, string $username): void
    {
        unset($tokens['tokens'][$index]);
        $tokens['tokens'] = array_values($tokens['tokens']);

        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $expiresAt = time() + $this->rememberLifetime;

        $tokens['tokens'][] = [
            'selector' => $selector,
            'username' => $username,
            'token_hash' => hash('sha256', $validator),
            'expires_at' => $expiresAt,
        ];

        $this->saveRememberTokens($tokens);
        $this->setRememberCookie($selector, $validator, $expiresAt);
    }

    private function forgetRememberedLogin(): void
    {
        $rawCookie = $_COOKIE[$this->rememberCookieName] ?? null;

        if (is_string($rawCookie) && $rawCookie !== '') {
            [$selector] = $this->parseRememberCookie($rawCookie);

            if ($selector !== null) {
                $tokens = $this->loadRememberTokens();
                $tokens['tokens'] = array_values(array_filter(
                    $tokens['tokens'],
                    static fn (array $token): bool => ($token['selector'] ?? '') !== $selector
                ));
                $this->saveRememberTokens($tokens);
            }
        }

        $this->clearRememberCookie();
    }

    private function parseRememberCookie(string $rawCookie): array
    {
        $parts = explode(':', $rawCookie, 2);

        if (count($parts) !== 2) {
            return [null, null];
        }

        [$selector, $validator] = $parts;

        if (!preg_match('/^[a-f0-9]{18}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
            return [null, null];
        }

        return [$selector, $validator];
    }

    private function setRememberCookie(string $selector, string $validator, int $expiresAt): void
    {
        setcookie($this->rememberCookieName, $selector . ':' . $validator, [
            'expires' => $expiresAt,
            'path' => '/',
            'domain' => '',
            'secure' => $this->https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        $_COOKIE[$this->rememberCookieName] = $selector . ':' . $validator;
    }

    private function clearRememberCookie(): void
    {
        setcookie($this->rememberCookieName, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $this->https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        unset($_COOKIE[$this->rememberCookieName]);
    }

    private function loadRememberTokens(): array
    {
        if (!is_file($this->rememberTokensFile)) {
            return ['tokens' => []];
        }

        $raw = file_get_contents($this->rememberTokensFile);

        if ($raw === false || trim($raw) === '') {
            return ['tokens' => []];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['tokens']) || !is_array($decoded['tokens'])) {
            return ['tokens' => []];
        }

        return ['tokens' => array_values(array_filter($decoded['tokens'], 'is_array'))];
    }

    private function saveRememberTokens(array $tokens): void
    {
        $directory = dirname($this->rememberTokensFile);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create remember-token directory.');
        }

        $json = json_encode(['tokens' => array_values($tokens['tokens'] ?? [])], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Unable to encode remember tokens.');
        }

        if (file_put_contents($this->rememberTokensFile, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to save remember tokens.');
        }
    }

    private function pruneExpiredTokens(array &$tokens): void
    {
        $now = time();
        $tokens['tokens'] = array_values(array_filter(
            $tokens['tokens'] ?? [],
            static fn (array $token): bool => is_int($token['expires_at'] ?? null) && ($token['expires_at'] ?? 0) >= $now
        ));
    }

    private function userExists(string $username): bool
    {
        foreach ($this->loadUsers() as $user) {
            if (($user['username'] ?? '') === $username) {
                return true;
            }
        }

        return false;
    }
}
