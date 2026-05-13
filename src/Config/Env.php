<?php

declare(strict_types=1);

namespace Lift\Config;

/** Small typed environment variable reader. */
final class Env
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        return self::cast((string) $value);
    }

    public static function string(string $key, ?string $default = null): ?string
    {
        $value = self::get($key, $default);
        return $value === null ? null : (string) $value;
    }

    public static function int(string $key, ?int $default = null): ?int
    {
        $value = self::get($key, $default);
        return $value === null ? null : (int) $value;
    }

    public static function bool(string $key, ?bool $default = null): ?bool
    {
        $value = self::get($key, $default);
        return $value === null ? null : (bool) $value;
    }

    public static function has(string $key): bool
    {
        return ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key)) !== false;
    }

    private static function cast(string $value): mixed
    {
        $lower = strtolower(trim($value));
        return match ($lower) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}
