<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/Config.php';

$config = new Config(dirname(__DIR__) . '/config/config.php');
$username = $argv[1] ?? '';

if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $username)) {
    fwrite(STDERR, "Usage: php scripts/create-admin.php <username>\n");
    exit(1);
}

$usersFile = rtrim($config->requireString('data_root'), '/') . '/users.json';
$usersData = ['users' => []];

if (is_file($usersFile)) {
    $decoded = json_decode((string) file_get_contents($usersFile), true);

    if (is_array($decoded) && isset($decoded['users']) && is_array($decoded['users'])) {
        $usersData = $decoded;
    }
}

$existingIndex = null;
foreach ($usersData['users'] as $index => $user) {
    if (($user['username'] ?? null) === $username) {
        $existingIndex = $index;
        break;
    }
}

if ($existingIndex !== null) {
    fwrite(STDOUT, "User '{$username}' already exists. Replace password hash? [y/N]: ");
    $answer = strtolower(trim((string) fgets(STDIN)));

    if (!in_array($answer, ['y', 'yes'], true)) {
        fwrite(STDOUT, "Aborted.\n");
        exit(0);
    }
}

function prompt_hidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);

    if (DIRECTORY_SEPARATOR !== '\\') {
        shell_exec('stty -echo');
        $value = rtrim((string) fgets(STDIN), "\r\n");
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);

        return $value;
    }

    return rtrim((string) fgets(STDIN), "\r\n");
}

$password = prompt_hidden('Password: ');
$confirm = prompt_hidden('Confirm password: ');

if ($password === '' || $password !== $confirm) {
    fwrite(STDERR, "Passwords did not match or were empty.\n");
    exit(1);
}

$record = [
    'username' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
];

if ($existingIndex !== null) {
    $usersData['users'][$existingIndex] = $record;
} else {
    $usersData['users'][] = $record;
}

$json = json_encode($usersData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

if ($json === false) {
    fwrite(STDERR, "Unable to encode users JSON.\n");
    exit(1);
}

$usersDirectory = dirname($usersFile);

if (!is_dir($usersDirectory) && !mkdir($usersDirectory, 0775, true) && !is_dir($usersDirectory)) {
    fwrite(STDERR, "Unable to create users directory.\n");
    exit(1);
}

$tempFile = tempnam($usersDirectory, 'browsebox-users-');

if ($tempFile === false) {
    fwrite(STDERR, "Unable to create temporary users file.\n");
    exit(1);
}

if (file_put_contents($tempFile, $json . PHP_EOL, LOCK_EX) === false || !rename($tempFile, $usersFile)) {
    @unlink($tempFile);
    fwrite(STDERR, "Unable to save users file.\n");
    exit(1);
}

fwrite(STDOUT, "Admin user saved to {$usersFile}\n");
