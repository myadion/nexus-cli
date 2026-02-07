<?php

declare(strict_types=1);

namespace Adion\NexusCli\Config;

class ConfigStore
{
    private string $path;
    private array $data = [];

    public function __construct(?string $path = null)
    {
        $this->path = $path ?: self::defaultPath();
        $this->data = $this->load();
    }

    public static function defaultPath(): string
    {
        $home = getenv('USERPROFILE') ?: getenv('HOME') ?: getcwd();
        return rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.nexus' . DIRECTORY_SEPARATOR . 'config.json';
    }

    public static function normalizeRemote(string $remote): string
    {
        $remote = trim($remote);
        if ($remote === '') {
            return '';
        }

        if (!preg_match('#^https?://#i', $remote)) {
            $remote = 'https://' . $remote;
        }

        return rtrim($remote, '/');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function load(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $raw = (string) file_get_contents($this->path);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function merge(array $data): void
    {
        $this->data = array_merge($this->data, $data);
    }

    public function save(): bool
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                return false;
            }
        }

        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($this->path, $json . "\n") !== false;
    }
}
