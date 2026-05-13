<?php

declare(strict_types=1);

namespace Lift\Database\Schema;

/**
 * Fluent column definition returned by every `Blueprint::*column*()` call.
 *
 * Each modifier returns `$this` so multiple modifiers can be chained:
 * ```php
 * $table->string('email', 320)->nullable()->unique();
 * $table->integer('score')->default(0)->unsigned();
 * ```
 *
 * The compiled attributes are read by {@see Blueprint} when generating SQL.
 */
final class ColumnDefinition
{
    /** @var array<string, mixed> */
    private array $attributes;

    /** @param array<string, mixed> $base Initial attributes supplied by Blueprint. */
    public function __construct(array $base)
    {
        $this->attributes = $base;
    }

    /** Allow NULL values for this column. */
    public function nullable(bool $value = true): static
    {
        $this->attributes['nullable'] = $value;
        return $this;
    }

    /** Set a default value for this column. */
    public function default(mixed $value): static
    {
        $this->attributes['default'] = $value;
        return $this;
    }

    /** Mark an integer column as UNSIGNED (MySQL only; silently ignored on other drivers). */
    public function unsigned(): static
    {
        $this->attributes['unsigned'] = true;
        return $this;
    }

    /**
     * Add a UNIQUE index on this column.
     *
     * An inline `UNIQUE` constraint is added to the column definition; Blueprint
     * also records a separate `unique` command so it appears in `toIndexSql()`.
     */
    public function unique(): static
    {
        $this->attributes['unique'] = true;
        return $this;
    }

    /** Add a regular (non-unique) index on this column. */
    public function index(): static
    {
        $this->attributes['index'] = true;
        return $this;
    }

    /**
     * Add an inline column comment (MySQL / PostgreSQL).
     *
     * SQLite does not support column comments — they are silently ignored.
     */
    public function comment(string $text): static
    {
        $this->attributes['comment'] = $text;
        return $this;
    }

    /**
     * Mark this column as the table's primary key.
     *
     * Prefer using `Blueprint::id()` which sets this automatically.
     */
    public function primary(): static
    {
        $this->attributes['primary'] = true;
        return $this;
    }

    /**
     * Return all accumulated column attributes.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }
}
