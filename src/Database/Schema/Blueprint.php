<?php

declare(strict_types=1);

namespace Lift\Database\Schema;

/**
 * Fluent table blueprint — records column and constraint definitions.
 *
 * A `Blueprint` is passed to the callback in `Schema::create()` / `Schema::alter()`:
 *
 * ```php
 * $schema->create('posts', function (Blueprint $table) {
 *     $table->id();
 *     $table->string('title');
 *     $table->text('body')->nullable();
 *     $table->boolean('published')->default(false);
 *     $table->foreignId('user_id')->index();
 *     $table->timestamps();
 * });
 * ```
 *
 * After the callback runs, `Schema` calls `toSql()` to obtain the DDL statements.
 */
final class Blueprint
{
    /** @var ColumnDefinition[] Columns in declaration order. */
    private array $columns = [];

    /** @var array<string, mixed>[] Index / constraint commands. */
    private array $commands = [];

    public function __construct(private readonly string $table) {}

    // -----------------------------------------------------------------
    // Auto-increment primary key shortcuts
    // -----------------------------------------------------------------

    /**
     * Add an auto-incrementing unsigned integer primary key column named `id`.
     *
     * Maps to `INT UNSIGNED AUTO_INCREMENT PRIMARY KEY` (MySQL),
     * `SERIAL PRIMARY KEY` (PostgreSQL), or `INTEGER PRIMARY KEY AUTOINCREMENT` (SQLite).
     */
    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->addColumn('increments', $name);
    }

    /**
     * Add an auto-incrementing unsigned big-integer primary key column named `id`.
     */
    public function bigIncrements(string $name = 'id'): ColumnDefinition
    {
        return $this->addColumn('bigIncrements', $name);
    }

    // -----------------------------------------------------------------
    // String / text
    // -----------------------------------------------------------------

    /**
     * Add a VARCHAR column.
     */
    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn('string', $name, ['length' => $length]);
    }

    /**
     * Add a CHAR column of fixed length.
     */
    public function char(string $name, int $length = 100): ColumnDefinition
    {
        return $this->addColumn('char', $name, ['length' => $length]);
    }

    /**
     * Add an unbounded TEXT column.
     */
    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn('text', $name);
    }

    /**
     * Add a MEDIUMTEXT (MySQL) / TEXT (others) column.
     */
    public function mediumText(string $name): ColumnDefinition
    {
        return $this->addColumn('mediumText', $name);
    }

    /**
     * Add a LONGTEXT (MySQL) / TEXT (others) column.
     */
    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn('longText', $name);
    }

    // -----------------------------------------------------------------
    // Numeric
    // -----------------------------------------------------------------

    /**
     * Add an INTEGER column.
     */
    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn('integer', $name);
    }

    /**
     * Add a BIGINT column.
     */
    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $name);
    }

    /**
     * Add a SMALLINT column.
     */
    public function smallInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('smallInteger', $name);
    }

    /**
     * Add a TINYINT column.
     */
    public function tinyInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('tinyInteger', $name);
    }

    /**
     * Add a DECIMAL(precision, scale) column.
     */
    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn('decimal', $name, ['precision' => $precision, 'scale' => $scale]);
    }

    /**
     * Add a FLOAT column.
     */
    public function float(string $name): ColumnDefinition
    {
        return $this->addColumn('float', $name);
    }

    /**
     * Add a DOUBLE column.
     */
    public function double(string $name): ColumnDefinition
    {
        return $this->addColumn('double', $name);
    }

    // -----------------------------------------------------------------
    // Boolean / binary
    // -----------------------------------------------------------------

    /**
     * Add a BOOLEAN column (`TINYINT(1)` on MySQL, `BOOLEAN` on PostgreSQL and SQLite).
     */
    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn('boolean', $name);
    }

    /**
     * Add a BLOB / BYTEA binary column.
     */
    public function binary(string $name): ColumnDefinition
    {
        return $this->addColumn('binary', $name);
    }

    // -----------------------------------------------------------------
    // Date / time
    // -----------------------------------------------------------------

    /**
     * Add a DATE column.
     */
    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn('date', $name);
    }

    /**
     * Add a DATETIME (MySQL/SQLite) / TIMESTAMP (PostgreSQL) column.
     */
    public function dateTime(string $name): ColumnDefinition
    {
        return $this->addColumn('dateTime', $name);
    }

    /**
     * Add a TIMESTAMP column.
     */
    public function timestamp(string $name): ColumnDefinition
    {
        return $this->addColumn('timestamp', $name);
    }

    /**
     * Add `created_at` and `updated_at` TIMESTAMP columns, both nullable.
     */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    /**
     * Add a TIME column.
     */
    public function time(string $name): ColumnDefinition
    {
        return $this->addColumn('time', $name);
    }

    // -----------------------------------------------------------------
    // JSON / UUID / other
    // -----------------------------------------------------------------

    /**
     * Add a JSON column (`JSON` on MySQL ≥5.7 / PostgreSQL, `TEXT` on SQLite).
     */
    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn('json', $name);
    }

    /**
     * Add a UUID / CHAR(36) column.
     */
    public function uuid(string $name): ColumnDefinition
    {
        return $this->addColumn('uuid', $name);
    }

    /**
     * Add an ENUM column.
     *
     * MySQL emits `ENUM('a','b',...)`. PostgreSQL and SQLite emit `VARCHAR(255)` with
     * a `CHECK (name IN ('a','b',...))` constraint.
     *
     * @param string[] $allowed
     */
    public function enum(string $name, array $allowed): ColumnDefinition
    {
        return $this->addColumn('enum', $name, ['allowed' => $allowed]);
    }

    // -----------------------------------------------------------------
    // Foreign key helpers
    // -----------------------------------------------------------------

    /**
     * Add an unsigned BIGINT column suitable for a foreign key.
     *
     * Use in place of `bigInteger()->unsigned()` — shorter and self-documenting.
     */
    public function foreignId(string $name): ColumnDefinition
    {
        return $this->addColumn('foreignId', $name);
    }

    // -----------------------------------------------------------------
    // Explicit index / constraint commands
    // -----------------------------------------------------------------

    /**
     * Add a named UNIQUE constraint across one or more columns.
     *
     * @param string|string[] $columns
     */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $cols = (array) $columns;
        $this->commands[] = [
            'type'    => 'unique',
            'columns' => $cols,
            'name'    => $name ?? ('unique_' . $this->table . '_' . implode('_', $cols)),
        ];
    }

    /**
     * Add a named regular index across one or more columns.
     *
     * @param string|string[] $columns
     */
    public function index(string|array $columns, ?string $name = null): void
    {
        $cols = (array) $columns;
        $this->commands[] = [
            'type'    => 'index',
            'columns' => $cols,
            'name'    => $name ?? ('idx_' . $this->table . '_' . implode('_', $cols)),
        ];
    }

    /**
     * Add a FOREIGN KEY constraint.
     *
     * ```php
     * $table->foreignKey('user_id', 'users', 'id', 'CASCADE', 'SET NULL');
     * ```
     */
    public function foreignKey(
        string $column,
        string $referencedTable,
        string $referencedColumn = 'id',
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'RESTRICT',
    ): void {
        $this->commands[] = [
            'type'              => 'foreign',
            'column'            => $column,
            'references'        => $referencedTable,
            'referencedColumn'  => $referencedColumn,
            'onDelete'          => $onDelete,
            'onUpdate'          => $onUpdate,
        ];
    }

    // -----------------------------------------------------------------
    // SQL compilation
    // -----------------------------------------------------------------

    /**
     * Compile the blueprint into one or more DDL SQL statements.
     *
     * The first element is always `CREATE TABLE ...`. Subsequent elements are
     * `CREATE INDEX` statements for columns that had `.index()` or explicit
     * `index()` / `unique()` calls.
     *
     * @return string[]
     */
    public function toSql(string $driver): array
    {
        $compiler = new SchemaCompiler($driver, $this->table);
        $lines = [];

        foreach ($this->columns as $col) {
            $lines[] = $compiler->compileColumn($col->getAttributes());
        }

        // Inline foreign keys (appended at end of CREATE TABLE)
        foreach ($this->commands as $cmd) {
            if ($cmd['type'] === 'foreign') {
                $lines[] = $compiler->compileForeignKey($cmd);
            }
        }

        $statements = [
            'CREATE TABLE ' . $compiler->quote($this->table) . " (\n    "
                . implode(",\n    ", $lines)
                . "\n)",
        ];

        // Separate index statements (must come after CREATE TABLE)
        foreach ($this->columns as $col) {
            $attrs = $col->getAttributes();
            if (!empty($attrs['index'])) {
                $statements[] = $compiler->compileIndex('index', (string) $attrs['name']);
            }
        }

        foreach ($this->commands as $cmd) {
            if ($cmd['type'] === 'index' || $cmd['type'] === 'unique') {
                $statements[] = $compiler->compileMultiColumnIndex($cmd);
            }
        }

        return $statements;
    }

    /**
     * Compile ALTER TABLE ADD COLUMN statements for each column in this blueprint.
     *
     * Used by `Schema::alter()`.
     *
     * @return string[]
     */
    public function toAlterSql(string $driver): array
    {
        $compiler = new SchemaCompiler($driver, $this->table);
        $statements = [];

        foreach ($this->columns as $col) {
            $compiled = $compiler->compileColumn($col->getAttributes());
            $statements[] = 'ALTER TABLE ' . $compiler->quote($this->table) . ' ADD COLUMN ' . $compiled;
        }

        foreach ($this->commands as $cmd) {
            if ($cmd['type'] === 'index' || $cmd['type'] === 'unique') {
                $statements[] = $compiler->compileMultiColumnIndex($cmd);
            }
        }

        return $statements;
    }

    // -----------------------------------------------------------------
    // Internal
    // -----------------------------------------------------------------

    /** @param array<string, mixed> $extra */
    private function addColumn(string $type, string $name, array $extra = []): ColumnDefinition
    {
        $col = new ColumnDefinition(array_merge(['type' => $type, 'name' => $name], $extra));
        $this->columns[] = $col;
        return $col;
    }
}
