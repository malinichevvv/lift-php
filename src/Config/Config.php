<?php

declare(strict_types=1);

namespace Lift\Config;

use InvalidArgumentException;

/**
 * Mutable application configuration repository.
 *
 * Config stores arbitrary nested arrays and exposes dot notation for reads and
 * writes. Lift keeps this component deliberately small: applications can merge
 * configuration from arrays, PHP files, YAML files, environment variables, or
 * any custom source without adopting a prescribed directory structure.
 *
 * Supported files:
 * - `*.php`: included with `require` and expected to return an array.
 * - `*.yaml` / `*.yml`: parsed through the optional `ext-yaml` extension.
 *
 * ```php
 * $app->config([
 *     'app' => ['name' => 'Example'],
 *     'debug' => ['enabled' => true],
 * ]);
 *
 * $name = $app->configuration()->get('app.name', 'Lift');
 * ```
 */
final class Config
{
    /**
     * @param array<string, mixed> $items Initial configuration values.
     */
    public function __construct(private array $items = []) {}

    /**
     * Create a repository from an already loaded array.
     *
     * @param array<string, mixed> $items Nested configuration values.
     */
    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    /**
     * Load configuration from a PHP or YAML file.
     *
     * @throws InvalidArgumentException When the file is unreadable, unsupported,
     *                                  or does not produce an array mapping.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("Configuration file [{$path}] is not readable");
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'php' => self::fromPhpFile($path),
            'yaml', 'yml' => self::fromYamlFile($path),
            default => throw new InvalidArgumentException("Unsupported configuration file type [{$extension}]")
        };
    }

    /**
     * Read a value using dot notation.
     *
     * Missing keys return `$default` instead of throwing. Numeric array indexes
     * can also be addressed as dot segments.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Determine whether a key exists, including keys explicitly set to null.
     */
    public function has(string $key): bool
    {
        $missing = new \stdClass();
        return $this->get($key, $missing) !== $missing;
    }

    /**
     * Set a value using dot notation, creating intermediate arrays as needed.
     *
     * ```php
     * $config->set('queue.default', 'redis');
     * ```
     */
    public function set(string $key, mixed $value): self
    {
        $target = &$this->items;
        foreach (explode('.', $key) as $segment) {
            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
        $target = $value;

        return $this;
    }

    /**
     * Recursively merge values into the repository.
     *
     * @param array<string, mixed> $items Values to merge.
     */
    public function merge(array $items): self
    {
        $this->items = array_replace_recursive($this->items, $items);
        return $this;
    }

    /**
     * Return all configuration values as a plain array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    private static function fromPhpFile(string $path): self
    {
        $data = require $path;
        if (!is_array($data)) {
            throw new InvalidArgumentException("PHP configuration file [{$path}] must return an array");
        }

        return new self($data);
    }

    private static function fromYamlFile(string $path): self
    {
        if (!function_exists('yaml_parse_file')) {
            throw new InvalidArgumentException('YAML configuration requires the ext-yaml PHP extension');
        }

        $data = yaml_parse_file($path);
        if (!is_array($data)) {
            throw new InvalidArgumentException("YAML configuration file [{$path}] must contain a mapping");
        }

        return new self($data);
    }
}
