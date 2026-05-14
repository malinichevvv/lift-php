<?php

declare(strict_types=1);

namespace Lift\Database;

/**
 * SQL grammar — handles identifier quoting per database driver.
 *
 * Column and table names that are plain identifiers (`/^[a-zA-Z_][a-zA-Z0-9_.]*\*?$/`)
 * are quoted to prevent reserved-word conflicts.  Anything outside that pattern
 * is treated as a raw expression and returned verbatim (e.g. `COUNT(*)`, `NOW()`).
 */
final class Grammar
{
    public function __construct(private readonly string $driver) {}

    /**
     * Quote a single identifier (table or column name).
     *
     * Supports `table.column` notation — each segment is quoted separately.
     */
    public function wrap(string $value): string
    {
        if ($value === '*') {
            return '*';
        }

        // Raw expressions: anything with spaces, parens, math ops, etc.
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_*][a-zA-Z0-9_]*)?$/', $value)) {
            return $value;
        }

        if (str_contains($value, '.')) {
            [$table, $col] = explode('.', $value, 2);
            return $this->quoteOne($table) . '.' . ($col === '*' ? '*' : $this->quoteOne($col));
        }

        return $this->quoteOne($value);
    }

    private function quoteOne(string $value): string
    {
        return match ($this->driver) {
            'mysql'  => '`' . str_replace('`', '``', $value) . '`',
            default  => '"' . str_replace('"', '""', $value) . '"',
        };
    }

    /**
     * Compile the locking suffix for a SELECT statement.
     *
     * Driver behaviour:
     * - MySQL/MariaDB: `FOR UPDATE [SKIP LOCKED]` or `LOCK IN SHARE MODE` / `FOR SHARE SKIP LOCKED`
     * - PostgreSQL:    `FOR UPDATE [SKIP LOCKED]` or `FOR SHARE [SKIP LOCKED]`
     * - SQLite:        empty string — SQLite has no row-level locking
     *
     * @param 'update'|'share' $type
     */
    public function compileLock(string $type, bool $skipLocked = false): string
    {
        if ($this->driver === 'sqlite') {
            return '';
        }

        if ($type === 'share') {
            if ($skipLocked) {
                return ' FOR SHARE SKIP LOCKED';
            }
            // LOCK IN SHARE MODE works on all MySQL versions; FOR SHARE is 8.0+ only
            return $this->driver === 'mysql' ? ' LOCK IN SHARE MODE' : ' FOR SHARE';
        }

        return $skipLocked ? ' FOR UPDATE SKIP LOCKED' : ' FOR UPDATE';
    }

    public function getDriver(): string
    {
        return $this->driver;
    }
}
