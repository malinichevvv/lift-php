<?php

declare(strict_types=1);

namespace Lift\Config;

/**
 * Typed environment variable reader.
 *
 * Reads from `$_ENV`, `$_SERVER`, and `getenv()` in that order.  String values
 * that represent well-known literals are automatically cast:
 *
 * | Raw string    | PHP value  |
 * |---------------|------------|
 * | `true`        | `true`     |
 * | `false`       | `false`    |
 * | `null`        | `null`     |
 * | `empty`       | `''`       |
 *
 * Parenthesised forms (`(true)`, `(false)`, etc.) are also accepted.
 *
 * Example:
 * ```php
 * $dsn  = Env::string('DB_URL');         // ?string
 * $port = Env::int('PORT', 8080);        // ?int
 * $flag = Env::bool('FEATURE_FOO');      // ?bool
 * ```
 */
final class Env
{
    /**
     * Return the raw (auto-cast) value for `$key`, or `$default` when absent.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }

        return self::cast((string) $value);
    }

    /**
     * Return the value for `$key` cast to `string`, or `$default` when absent.
     */
    public static function string(string $key, ?string $default = null): ?string
    {
        $value = self::get($key, $default);
        return $value === null ? null : (string) $value;
    }

    /**
     * Return the value for `$key` cast to `int`, or `$default` when absent.
     */
    public static function int(string $key, ?int $default = null): ?int
    {
        $value = self::get($key, $default);
        return $value === null ? null : (int) $value;
    }

    /**
     * Return the value for `$key` cast to `bool`, or `$default` when absent.
     */
    public static function bool(string $key, ?bool $default = null): ?bool
    {
        $value = self::get($key, $default);
        return $value === null ? null : (bool) $value;
    }

    /**
     * Return `true` when the environment variable is set (even to an empty string).
     */
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
