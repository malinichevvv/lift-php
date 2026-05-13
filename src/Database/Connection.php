<?php

declare(strict_types=1);

namespace Lift\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO database connection with a fluent query builder.
 *
 * ```php
 * $db = new Connection('sqlite::memory:');
 *
 * // Fluent query builder
 * $users = $db->table('users')
 *     ->where('active', 1)
 *     ->orderBy('name')
 *     ->get();
 *
 * // Raw queries
 * $row = $db->selectOne('SELECT * FROM users WHERE id = ?', [42]);
 *
 * // Transactions
 * $db->transaction(function (Connection $db) {
 *     $id = $db->table('orders')->insert([...]);
 *     $db->table('items')->insert(['order_id' => $id, ...]);
 * });
 * ```
 */
final class Connection
{
    private readonly PDO $pdo;
    private readonly Grammar $grammar;

    /**
     * @param string      $dsn      PDO data source name.
     * @param string|null $username Database username.
     * @param string|null $password Database password.
     * @param array       $options  Additional PDO options.
     */
    public function __construct(
        string  $dsn,
        ?string $username = null,
        ?string $password = null,
        array   $options  = [],
    ) {
        $defaults = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $username, $password, $defaults + $options);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        $this->grammar = new Grammar($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    /**
     * Create a connection from a config array.
     *
     * ```php
     * $db = Connection::fromConfig([
     *     'driver'   => 'mysql',
     *     'host'     => 'localhost',
     *     'port'     => 3306,
     *     'database' => 'app',
     *     'username' => 'root',
     *     'password' => 'secret',
     *     'charset'  => 'utf8mb4',
     * ]);
     * ```
     */
    public static function fromConfig(array $config): self
    {
        $driver   = $config['driver'] ?? 'mysql';
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;

        $dsn = match ($driver) {
            'mysql', 'mariadb' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'] ?? 'localhost',
                $config['port'] ?? 3306,
                $config['database'] ?? '',
                $config['charset'] ?? 'utf8mb4',
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $config['host'] ?? 'localhost',
                $config['port'] ?? 5432,
                $config['database'] ?? '',
            ),
            'sqlite' => 'sqlite:' . ($config['database'] ?? ':memory:'),
            default  => throw new \InvalidArgumentException("Unsupported driver: {$driver}"),
        };

        return new self($dsn, $username, $password, $config['options'] ?? []);
    }

    // -----------------------------------------------------------------
    // Query builder entry-point
    // -----------------------------------------------------------------

    /** Start a fluent query on the given table. */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $this->grammar, $table);
    }

    // -----------------------------------------------------------------
    // Raw query methods
    // -----------------------------------------------------------------

    /**
     * Execute a raw SELECT and return all rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->fetchAll();
    }

    /**
     * Execute a raw SELECT and return the first row, or null.
     *
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $row  = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Execute a raw SELECT and return a single scalar value.
     */
    public function value(string $sql, array $bindings = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        $val  = $stmt->fetchColumn();
        return $val === false ? null : $val;
    }

    /**
     * Execute a statement (INSERT/UPDATE/DELETE) and return affected row count.
     */
    public function execute(string $sql, array $bindings = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return $stmt->rowCount();
    }

    /** Return the last inserted auto-increment ID. */
    public function lastInsertId(): string|false
    {
        return $this->pdo->lastInsertId();
    }

    // -----------------------------------------------------------------
    // Transactions
    // -----------------------------------------------------------------

    /**
     * Execute a callable inside a transaction.
     *
     * Automatically commits on success and rolls back on any exception.
     *
     * @throws \Throwable Re-throws any exception from $callback after rolling back.
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    // -----------------------------------------------------------------
    // Introspection
    // -----------------------------------------------------------------

    public function getDriverName(): string
    {
        return $this->grammar->getDriver();
    }

    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    /** Access the underlying PDO instance for advanced usage. */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}
