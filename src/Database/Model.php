<?php

declare(strict_types=1);

namespace Lift\Database;

use ArrayAccess;
use JsonSerializable;

/**
 * Lightweight active-record style base model.
 *
 * The model intentionally stays small: it provides attribute storage, JSON
 * serialisation, mass-assignment guards, and helper methods backed by
 * {@see QueryBuilder}. Applications can extend it for simple CRUD models while
 * still using the lower-level query builder for complex queries.
 *
 * ```php
 * final class User extends Model
 * {
 *     protected static string $table = 'users';
 *     protected array $fillable = ['name', 'email'];
 * }
 *
 * User::setConnection($db);
 * $user = User::find(1);
 * ```
 */
abstract class Model implements ArrayAccess, JsonSerializable
{
    protected static ?Connection $connection = null;
    protected static string $table = '';
    protected static string $primaryKey = 'id';
    /** @var list<string> */
    protected array $fillable = [];
    /** @var array<string, mixed> */
    protected array $attributes = [];
    /** @var array<string, mixed> */
    protected array $original = [];
    protected bool $exists = false;

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /** Set the shared connection used by static query helpers. */
    public static function setConnection(Connection $connection): void
    {
        static::$connection = $connection;
    }

    /** Start a query for this model's table. */
    public static function query(): QueryBuilder
    {
        if (static::$connection === null) {
            throw new \RuntimeException('No database connection configured for model [' . static::class . ']');
        }

        return static::$connection->table(static::tableName());
    }

    /** Find a model by primary key. */
    public static function find(int|string $id): ?static
    {
        $row = static::query()->where(static::$primaryKey, $id)->first();
        return $row === null ? null : static::hydrate($row);
    }

    /** Create and persist a new model. */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();
        return $model;
    }

    /** Hydrate a model that already exists in the database. */
    public static function hydrate(array $attributes): static
    {
        $model = new static();
        $model->attributes = $attributes;
        $model->original = $attributes;
        $model->exists = true;
        return $model;
    }

    /** Return the database table name for the model. */
    public static function tableName(): string
    {
        if (static::$table !== '') {
            return static::$table;
        }

        $short = substr(strrchr(static::class, '\\') ?: static::class, 1) ?: static::class;
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short)) . 's';
    }

    /** Mass-assign attributes allowed by `$fillable`. */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable((string) $key)) {
                $this->attributes[(string) $key] = $value;
            }
        }
        return $this;
    }

    /** Return an attribute value. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** Set an attribute value. */
    public function set(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /** Persist the model using insert or update. */
    public function save(): bool
    {
        if ($this->exists) {
            $dirty = $this->dirty();
            if ($dirty === []) {
                return true;
            }
            static::query()->where(static::$primaryKey, $this->getKey())->update($dirty);
            $this->syncOriginal();
            return true;
        }

        $id = static::query()->insert($this->attributes);
        if ($id !== false && !isset($this->attributes[static::$primaryKey])) {
            $this->attributes[static::$primaryKey] = is_numeric($id) ? (int) $id : $id;
        }
        $this->exists = true;
        $this->syncOriginal();
        return true;
    }

    /** Delete the model from the database. */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        static::query()->where(static::$primaryKey, $this->getKey())->delete();
        $this->exists = false;
        return true;
    }

    /** Return the model primary key value. */
    public function getKey(): mixed
    {
        return $this->get(static::$primaryKey);
    }

    /** Return changed attributes since hydration or last save. */
    public function dirty(): array
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    /** Return all model attributes. */
    public function toArray(): array
    {
        return $this->attributes;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->attributes);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[(string) $offset]);
    }

    private function isFillable(string $key): bool
    {
        return $this->fillable === [] || in_array($key, $this->fillable, true);
    }

    private function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }
}
