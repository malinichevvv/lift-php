<?php

declare(strict_types=1);

namespace Lift\Database\Schema;

/**
 * Translates {@see ColumnDefinition} attributes into driver-specific DDL fragments.
 *
 * Supported drivers: `mysql`, `pgsql` (PostgreSQL), `sqlite`.
 * Unknown drivers fall back to the SQLite/ANSI dialect.
 */
final class SchemaCompiler
{
    public function __construct(
        private readonly string $driver,
        private readonly string $table,
    ) {}

    /** Quote an identifier for the current driver. */
    public function quote(string $identifier): string
    {
        return match ($this->driver) {
            'mysql'  => '`' . str_replace('`', '``', $identifier) . '`',
            default  => '"' . str_replace('"', '""', $identifier) . '"',
        };
    }

    /**
     * Compile one column definition line for use inside `CREATE TABLE (...)`.
     *
     * @param array<string, mixed> $attrs
     */
    public function compileColumn(array $attrs): string
    {
        $name = $this->quote((string) $attrs['name']);
        $type = $this->resolveType($attrs);

        $line = "{$name} {$type}";

        if (!empty($attrs['unsigned']) && $this->driver === 'mysql') {
            $line .= ' UNSIGNED';
        }

        // NOT NULL / NULL
        if (empty($attrs['nullable']) && !$this->isAutoIncrement($attrs)) {
            $line .= ' NOT NULL';
        }

        // DEFAULT
        if (array_key_exists('default', $attrs) && !$this->isAutoIncrement($attrs)) {
            $line .= ' DEFAULT ' . $this->compileDefault($attrs['default']);
        }

        // UNIQUE (inline)
        if (!empty($attrs['unique']) && !$this->isAutoIncrement($attrs)) {
            $line .= ' UNIQUE';
        }

        // Comment (MySQL / PostgreSQL)
        if (isset($attrs['comment'])) {
            $escaped = str_replace("'", "''", (string) $attrs['comment']);
            if ($this->driver === 'mysql') {
                $line .= " COMMENT '{$escaped}'";
            } elseif ($this->driver === 'pgsql') {
                // PostgreSQL COMMENT is a separate statement — append as a note only
                // (Schema::create() ignores it; callers can add COMMENT ON COLUMN separately)
            }
        }

        return $line;
    }

    /**
     * Compile a standalone `CREATE INDEX` statement for a single-column index.
     */
    public function compileIndex(string $type, string $columnName): string
    {
        $unique = $type === 'unique' ? 'UNIQUE ' : '';
        $idxName = $this->quote('idx_' . $this->table . '_' . $columnName);
        return "CREATE {$unique}INDEX {$idxName} ON "
            . $this->quote($this->table) . ' (' . $this->quote($columnName) . ')';
    }

    /**
     * Compile a multi-column `CREATE [UNIQUE] INDEX` statement.
     *
     * @param array<string, mixed> $cmd
     */
    public function compileMultiColumnIndex(array $cmd): string
    {
        $unique = $cmd['type'] === 'unique' ? 'UNIQUE ' : '';
        $name = $this->quote((string) $cmd['name']);
        $cols = implode(', ', array_map(fn($c) => $this->quote($c), (array) $cmd['columns']));
        return "CREATE {$unique}INDEX {$name} ON " . $this->quote($this->table) . " ({$cols})";
    }

    /**
     * Compile an inline `CONSTRAINT ... FOREIGN KEY ...` fragment (for CREATE TABLE).
     *
     * @param array<string, mixed> $cmd
     */
    public function compileForeignKey(array $cmd): string
    {
        $col      = $this->quote((string) $cmd['column']);
        $refTable = $this->quote((string) $cmd['references']);
        $refCol   = $this->quote((string) $cmd['referencedColumn']);
        $onDelete = strtoupper((string) ($cmd['onDelete'] ?? 'RESTRICT'));
        $onUpdate = strtoupper((string) ($cmd['onUpdate'] ?? 'RESTRICT'));
        $name     = $this->quote('fk_' . $this->table . '_' . $cmd['column']);
        return "CONSTRAINT {$name} FOREIGN KEY ({$col}) REFERENCES {$refTable} ({$refCol}) ON DELETE {$onDelete} ON UPDATE {$onUpdate}";
    }

    // -----------------------------------------------------------------
    // Type resolution
    // -----------------------------------------------------------------

    /** @param array<string, mixed> $attrs */
    private function resolveType(array $attrs): string
    {
        return match ($attrs['type']) {
            'increments'    => $this->autoIncrement(false),
            'bigIncrements' => $this->autoIncrement(true),
            'foreignId'     => $this->driver === 'mysql' ? 'BIGINT UNSIGNED' : 'BIGINT',

            'string'        => 'VARCHAR(' . ($attrs['length'] ?? 255) . ')',
            'char'          => 'CHAR(' . ($attrs['length'] ?? 100) . ')',
            'text'          => 'TEXT',
            'mediumText'    => $this->driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT',
            'longText'      => $this->driver === 'mysql' ? 'LONGTEXT' : 'TEXT',

            'integer'       => 'INTEGER',
            'bigInteger'    => 'BIGINT',
            'smallInteger'  => 'SMALLINT',
            'tinyInteger'   => $this->driver === 'mysql' ? 'TINYINT' : 'SMALLINT',
            'decimal'       => 'DECIMAL(' . ($attrs['precision'] ?? 8) . ',' . ($attrs['scale'] ?? 2) . ')',
            'float'         => $this->driver === 'sqlite' ? 'REAL' : 'FLOAT',
            'double'        => $this->driver === 'sqlite' ? 'REAL' : 'DOUBLE',

            'boolean'       => $this->driver === 'mysql' ? 'TINYINT(1)' : 'BOOLEAN',

            'binary'        => match ($this->driver) {
                'pgsql'  => 'BYTEA',
                default  => 'BLOB',
            },

            'date'          => 'DATE',
            'time'          => 'TIME',
            'dateTime'      => match ($this->driver) {
                'pgsql'  => 'TIMESTAMP',
                default  => 'DATETIME',
            },
            'timestamp'     => 'TIMESTAMP',

            'json'          => match ($this->driver) {
                'mysql', 'pgsql' => 'JSON',
                default          => 'TEXT',
            },

            'uuid'          => $this->driver === 'pgsql' ? 'UUID' : 'CHAR(36)',

            'enum'          => $this->resolveEnum($attrs),

            default         => strtoupper($attrs['type']),
        };
    }

    private function autoIncrement(bool $big): string
    {
        return match ($this->driver) {
            'mysql'  => ($big ? 'BIGINT' : 'INT') . ' UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'pgsql'  => $big ? 'BIGSERIAL PRIMARY KEY' : 'SERIAL PRIMARY KEY',
            default  => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
    }

    /** @param array<string, mixed> $attrs */
    private function resolveEnum(array $attrs): string
    {
        $quoted = implode(', ', array_map(
            fn($v) => "'" . str_replace("'", "''", (string) $v) . "'",
            (array) ($attrs['allowed'] ?? []),
        ));

        if ($this->driver === 'mysql') {
            return "ENUM({$quoted})";
        }

        // PostgreSQL / SQLite: VARCHAR + CHECK constraint
        $col = $this->quote((string) $attrs['name']);
        return "VARCHAR(255) CHECK ({$col} IN ({$quoted}))";
    }

    private function compileDefault(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    private function isAutoIncrement(array $attrs): bool
    {
        return in_array($attrs['type'] ?? '', ['increments', 'bigIncrements'], true);
    }
}
