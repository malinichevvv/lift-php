<?php

declare(strict_types=1);

namespace Lift\Database;

/**
 * Registry and lazy factory for named database connections.
 *
 * Keeps multi-connection apps fast by creating PDO connections only when first
 * requested, while still allowing already-created Connection instances.
 */
final class DatabaseManager
{
    /** @var array<string, array<string, mixed>|callable|Connection> */
    private array $connections = [];
    /** @var array<string, Connection> */
    private array $resolved = [];

    public function __construct(private string $default = 'default') {}

    /** Register a named connection config, factory, or instance. */
    public function add(string $name, array|callable|Connection $connection, bool $default = false): self
    {
        $this->connections[$name] = $connection;
        unset($this->resolved[$name]);
        if ($default) {
            $this->default = $name;
        }
        return $this;
    }

    /** Build a manager from config arrays keyed by connection name. */
    public static function fromConfig(array $config): self
    {
        $manager = new self((string) ($config['default'] ?? 'default'));
        foreach (($config['connections'] ?? []) as $name => $connection) {
            if (is_array($connection)) {
                $manager->add((string) $name, $connection);
            }
        }
        return $manager;
    }

    /** Resolve a connection by name or the configured default. */
    public function connection(?string $name = null): Connection
    {
        $name ??= $this->default;
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }
        if (!array_key_exists($name, $this->connections)) {
            throw new \InvalidArgumentException("Database connection [{$name}] is not configured");
        }

        $entry = $this->connections[$name];
        if ($entry instanceof Connection) {
            return $this->resolved[$name] = $entry;
        }
        if (is_callable($entry)) {
            $connection = $entry();
            if (!$connection instanceof Connection) {
                throw new \RuntimeException("Database factory [{$name}] must return " . Connection::class);
            }
            return $this->resolved[$name] = $connection;
        }

        return $this->resolved[$name] = Connection::fromConfig($entry);
    }

    /** Start a query on the default or named connection. */
    public function table(string $table, ?string $connection = null): QueryBuilder
    {
        return $this->connection($connection)->table($table);
    }

    /** Return the default connection name. */
    public function getDefaultConnection(): string
    {
        return $this->default;
    }

    /** Set the default connection name. */
    public function setDefaultConnection(string $name): self
    {
        $this->default = $name;
        return $this;
    }
}
