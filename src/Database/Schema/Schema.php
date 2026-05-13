<?php

declare(strict_types=1);

namespace Lift\Database\Schema;

use Lift\Database\Connection;

/**
 * Entry point for database schema manipulation.
 *
 * Wraps a {@see Connection} and provides a fluent, driver-aware DSL for
 * creating, modifying, and dropping tables without writing raw SQL.
 *
 * ```php
 * $schema = new Schema($db);
 *
 * $schema->create('users', function (Blueprint $table) {
 *     $table->id();
 *     $table->string('email')->unique();
 *     $table->string('password');
 *     $table->boolean('active')->default(true);
 *     $table->timestamps();
 * });
 *
 * $schema->alter('users', function (Blueprint $table) {
 *     $table->string('avatar_url', 500)->nullable();
 * });
 *
 * $schema->dropIfExists('users');
 * ```
 *
 * All generated SQL is passed through {@see Connection::execute()} so it
 * participates in the same connection lifecycle as query builder statements.
 */
final class Schema
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Create a new table using the supplied callback to define its columns.
     *
     * All column and index definitions recorded on the {@see Blueprint} are
     * compiled to DDL statements and executed in order.
     *
     * @throws \RuntimeException When any DDL statement fails.
     */
    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        foreach ($blueprint->toSql($this->connection->getDriverName()) as $sql) {
            $this->connection->execute($sql);
        }
    }

    /**
     * Add columns or indexes to an existing table.
     *
     * Each column defined in the callback is compiled to an
     * `ALTER TABLE ... ADD COLUMN ...` statement.
     */
    public function alter(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table);
        $callback($blueprint);

        foreach ($blueprint->toAlterSql($this->connection->getDriverName()) as $sql) {
            $this->connection->execute($sql);
        }
    }

    /**
     * Drop a table.
     *
     * @throws \InvalidArgumentException When the table does not exist.
     */
    public function drop(string $table): void
    {
        $compiler = new SchemaCompiler($this->connection->getDriverName(), $table);
        $this->connection->execute('DROP TABLE ' . $compiler->quote($table));
    }

    /**
     * Drop a table if it exists — no error when the table is absent.
     */
    public function dropIfExists(string $table): void
    {
        $compiler = new SchemaCompiler($this->connection->getDriverName(), $table);
        $this->connection->execute('DROP TABLE IF EXISTS ' . $compiler->quote($table));
    }

    /**
     * Return `true` when the named table exists in the current database.
     */
    public function hasTable(string $table): bool
    {
        $driver = $this->connection->getDriverName();

        $sql = match ($driver) {
            'mysql'  => "SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
            'pgsql'  => "SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?",
            default  => "SELECT COUNT(*) as cnt FROM sqlite_master WHERE type = 'table' AND name = ?",
        };

        $row = $this->connection->selectOne($sql, [$table]);
        return (int) ($row['cnt'] ?? 0) > 0;
    }

    /**
     * Return `true` when the given column exists in a table.
     */
    public function hasColumn(string $table, string $column): bool
    {
        $driver = $this->connection->getDriverName();

        $sql = match ($driver) {
            'mysql'  => "SELECT COUNT(*) as cnt FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            'pgsql'  => "SELECT COUNT(*) as cnt FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? AND column_name = ?",
            default  => "SELECT COUNT(*) as cnt FROM pragma_table_info(?) WHERE name = ?",
        };

        $row = $this->connection->selectOne($sql, [$table, $column]);
        return (int) ($row['cnt'] ?? 0) > 0;
    }

    /**
     * Rename a table.
     */
    public function rename(string $from, string $to): void
    {
        $compiler = new SchemaCompiler($this->connection->getDriverName(), $from);
        $this->connection->execute(
            'ALTER TABLE ' . $compiler->quote($from) . ' RENAME TO ' . $compiler->quote($to),
        );
    }
}
