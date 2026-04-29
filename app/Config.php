<?php

declare(strict_types=1);

final class Config
{
    private array $config;
    private string $configFile;

    public function __construct(string $configFile)
    {
        $this->configFile = $configFile;

        if (!is_file($configFile)) {
            throw new RuntimeException('Missing config file: ' . $configFile);
        }

        $loaded = require $configFile;

        if (!is_array($loaded)) {
            throw new RuntimeException('Config file must return an array.');
        }

        $this->config = $loaded;
        $this->validate();

        date_default_timezone_set((string) $this->get('default_timezone', 'UTC'));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->config;
    }

    public function write(array $config): void
    {
        $export = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        $directory = dirname($this->configFile);
        $tempFile = tempnam($directory, 'browsebox-config-');

        if ($tempFile === false) {
            throw new RuntimeException('Unable to create temporary config file.');
        }

        try {
            if (file_put_contents($tempFile, $export, LOCK_EX) === false) {
                throw new RuntimeException('Unable to write config file.');
            }

            $existingMode = @fileperms($this->configFile);

            if ($existingMode !== false) {
                @chmod($tempFile, $existingMode & 0777);
            }

            new self($tempFile);

            if (!rename($tempFile, $this->configFile)) {
                throw new RuntimeException('Unable to replace config file.');
            }
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    public function requireString(string $key): string
    {
        $value = $this->get($key);

        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Missing required config value: ' . $key);
        }

        return $value;
    }

    private function validate(): void
    {
        foreach (['app_name', 'storage_root', 'data_root', 'management_path', 'default_timezone'] as $key) {
            $this->requireString($key);
        }

        foreach (['storage_root', 'data_root'] as $key) {
            $path = $this->requireString($key);

            if (!is_dir($path)) {
                throw new RuntimeException('Configured directory does not exist: ' . $path);
            }
        }
    }
}
