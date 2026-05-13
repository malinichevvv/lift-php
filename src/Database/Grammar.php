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

    public function getDriver(): string
    {
        return $this->driver;
    }
}
