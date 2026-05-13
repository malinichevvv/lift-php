<?php

declare(strict_types=1);

namespace Lift\Config;

/**
 * Dependency-free `.env` file loader.
 *
 * Parses a file containing `KEY=value` declarations and populates `$_ENV`,
 * `$_SERVER`, and `putenv()`.  Supports:
 * - Quoted values (`"double"` and `'single'`) — double-quoted strings have
 *   C-style escape sequences processed via `stripcslashes()`.
 * - `export KEY=value` lines (the `export` keyword is stripped).
 * - Inline comments: everything after ` #` on an unquoted value is ignored.
 * - Blank lines and lines starting with `#` are skipped.
 *
 * By default, existing environment variables are **not** overwritten.
 * Set `$overwrite = true` to force re-assignment.
 *
 * ```php
 * Dotenv::load(__DIR__ . '/../.env');
 * ```
 */
final class Dotenv
{
    /**
     * @param string $path      Absolute path to the `.env` file.
     * @param bool   $overwrite When `true`, existing env vars are overwritten.
     */
    public function __construct(
        private readonly string $path,
        private readonly bool $overwrite = false,
    ) {}

    /**
     * Static convenience wrapper — construct, parse, and load in one call.
     *
     * @return array<string, string> Map of variable names to loaded values.
     */
    public static function load(string $path, bool $overwrite = false): array
    {
        return (new self($path, $overwrite))->parseAndLoad();
    }

    /**
     * Parse the `.env` file and populate the PHP environment superglobals.
     *
     * @return array<string, string> Map of variable names that were actually set.
     * @throws \InvalidArgumentException When the file is not readable.
     */
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
