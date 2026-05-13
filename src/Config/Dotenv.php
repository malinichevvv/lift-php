<?php

declare(strict_types=1);

namespace Lift\Config;

/** Dependency-free .env loader compatible with common KEY=value files. */
final class Dotenv
{
    public function __construct(
        private readonly string $path,
        private readonly bool $overwrite = false,
    ) {}

    public static function load(string $path, bool $overwrite = false): array
    {
        return (new self($path, $overwrite))->parseAndLoad();
    }

    /** @return array<string, string> */
    public function parseAndLoad(): array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new \InvalidArgumentException("Environment file [{$this->path}] is not readable");
        }

        $loaded = [];
        foreach (file($this->path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $entry = $this->parseLine($line);
            if ($entry === null) {
                continue;
            }

            [$key, $value] = $entry;
            if (!$this->overwrite && Env::has($key)) {
                continue;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
            $loaded[$key] = $value;
        }

        return $loaded;
    }

    /** @return array{string, string}|null */
    private function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $position = strpos($line, '=');
        if ($position === false) {
            return null;
        }

        $key = trim(substr($line, 0, $position));
        if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException("Invalid environment variable name [{$key}]");
        }

        $value = trim(substr($line, $position + 1));
        return [$key, $this->parseValue($value)];
    }

    private function parseValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            $inner = substr($value, 1, -1);
            return $quote === '"' ? stripcslashes($inner) : $inner;
        }

        $hash = strpos($value, ' #');
        if ($hash !== false) {
            $value = substr($value, 0, $hash);
        }

        return trim($value);
    }
}
