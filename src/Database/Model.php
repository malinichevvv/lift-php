<?php

declare(strict_types=1);

namespace Lift\Database;

use ArrayAccess;
use JsonSerializable;
use Lift\Database\Events\ModelCreated;
use Lift\Database\Events\ModelCreating;
use Lift\Database\Events\ModelDeleted;
use Lift\Database\Events\ModelDeleting;
use Lift\Database\Events\ModelUpdated;
use Lift\Database\Events\ModelUpdating;
use Lift\Events\EventDispatcher;

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

    /** Shared PSR-14-compatible dispatcher for all model classes. */
    protected static ?EventDispatcher $dispatcher = null;
    /** @var list<string> Explicit allow-list of mass-assignable attributes. When non-empty, only listed keys pass `fill()`. */
    protected array $fillable = [];
    /**
     * @var list<string> Deny-list of attributes that are never mass-assignable.
     *
     * Checked only when `$fillable` is empty. Typical usage:
     * ```php
     * protected array $guarded = ['id', 'is_admin'];
     * ```
     */
    protected array $guarded = [];
    /**
     * Attribute type casts.  Keys are column names; values are cast types.
     *
     * Supported types: `int`, `integer`, `float`, `double`, `string`,
     * `bool`, `boolean`, `array`, `json`, `datetime`, `date`, `timestamp`.
     *
     * ```php
     * protected array $casts = [
     *     'active'     => 'bool',
     *     'score'      => 'float',
     *     'meta'       => 'json',      // array ↔ JSON string
     *     'created_at' => 'datetime',  // string ↔ DateTimeImmutable
     * ];
     * ```
     */
    protected array $casts = [];
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

    /**
     * Set the shared event dispatcher used for model lifecycle events.
     *
     * When configured, `save()` and `delete()` fire `ModelCreating` / `ModelCreated`
     * (or `ModelUpdating` / `ModelUpdated`) and `ModelDeleting` / `ModelDeleted` events.
     * Stoppable `*ing` events can cancel the operation by calling `stopPropagation()`.
     *
     * ```php
     * Model::setEventDispatcher($app->events());
     * ```
     */
    public static function setEventDispatcher(EventDispatcher $dispatcher): void
    {
        static::$dispatcher = $dispatcher;
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

    /**
     * Dispatch a local scope defined as `scope{Name}(QueryBuilder $q): void` on the model.
     *
     * ```php
     * // In the model:
     * public function scopeActive(QueryBuilder $query): void
     * {
     *     $query->where('active', 1);
     * }
     *
     * // Usage:
     * User::active()->where('role', 'admin')->get();
     * ```
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        $scope = 'scope' . ucfirst($name);
        if (method_exists(static::class, $scope)) {
            $query = static::query();
            (new static())->$scope($query, ...$arguments);
            return $query;
        }
        throw new \BadMethodCallException(
            sprintf('Call to undefined static method %s::%s().', static::class, $name)
        );
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
                $this->set((string) $key, $value);
            }
        }
        return $this;
    }

    /**
     * Return an attribute value, applying any configured cast.
     *
     * ```php
     * $user->get('active');      // returns bool when cast to 'bool'
     * $user->get('created_at');  // returns DateTimeImmutable when cast to 'datetime'
     * $user->get('meta');        // returns array when cast to 'json'
     * ```
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            return $default;
        }
        $value = $this->attributes[$key];
        return isset($this->casts[$key]) ? $this->castForRead($key, $value) : $value;
    }

    /**
     * Set an attribute value, applying any write cast (e.g. array → JSON string).
     */
    public function set(string $key, mixed $value): static
    {
        $this->attributes[$key] = isset($this->casts[$key]) ? $this->castForWrite($key, $value) : $value;
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

            if (self::$dispatcher !== null) {
                $event = new ModelUpdating($this);
                self::$dispatcher->dispatch($event);
                if ($event->isPropagationStopped()) {
                    return false;
                }
            }

            static::query()->where(static::$primaryKey, $this->getKey())->update($dirty);
            $this->syncOriginal();

            if (self::$dispatcher !== null) {
                self::$dispatcher->dispatch(new ModelUpdated($this));
            }
            return true;
        }

        if (self::$dispatcher !== null) {
            $event = new ModelCreating($this);
            self::$dispatcher->dispatch($event);
            if ($event->isPropagationStopped()) {
                return false;
            }
        }

        $id = static::query()->insert($this->attributes);
        if ($id !== false && !isset($this->attributes[static::$primaryKey])) {
            $this->attributes[static::$primaryKey] = is_numeric($id) ? (int) $id : $id;
        }
        $this->exists = true;
        $this->syncOriginal();

        if (self::$dispatcher !== null) {
            self::$dispatcher->dispatch(new ModelCreated($this));
        }
        return true;
    }

    /** Delete the model from the database. */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if (self::$dispatcher !== null) {
            $event = new ModelDeleting($this);
            self::$dispatcher->dispatch($event);
            if ($event->isPropagationStopped()) {
                return false;
            }
        }

        static::query()->where(static::$primaryKey, $this->getKey())->delete();
        $this->exists = false;

        if (self::$dispatcher !== null) {
            self::$dispatcher->dispatch(new ModelDeleted($this));
        }
        return true;
    }

    /** Return the model primary key value. */
    public function getKey(): mixed
    {
        return $this->get(static::$primaryKey);
    }

    /** Return the primary key column name. */
    public static function keyName(): string
    {
        return static::$primaryKey;
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    /**
     * Define a one-to-many relationship: this model's PK → child model's FK.
     *
     * ```php
     * // In User model
     * public function posts(): array
     * {
     *     return $this->hasMany(Post::class);
     *     // SELECT * FROM posts WHERE user_id = {$this->id}
     * }
     * ```
     *
     * @template T of Model
     * @param  class-string<T> $related     Fully-qualified related model class name.
     * @param  string|null     $foreignKey  FK column on the related table (default: `{snake_class}_id`).
     * @param  string|null     $localKey    PK column on this model (default: `static::$primaryKey`).
     * @return T[]
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): array
    {
        $fk  = $foreignKey ?? $this->guessForeignKey();
        $lk  = $localKey ?? static::$primaryKey;
        $rows = $related::query()->where($fk, $this->get($lk))->get();
        return array_map(fn($row) => $related::hydrate($row), $rows);
    }

    /**
     * Define a one-to-one relationship: this model's PK → child model's FK.
     *
     * ```php
     * public function profile(): ?Profile
     * {
     *     return $this->hasOne(Profile::class);
     *     // SELECT * FROM profiles WHERE user_id = {$this->id} LIMIT 1
     * }
     * ```
     *
     * @template T of Model
     * @param  class-string<T> $related
     * @param  string|null     $foreignKey  FK column on the related table.
     * @param  string|null     $localKey    PK column on this model.
     * @return T|null
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): ?Model
    {
        $fk  = $foreignKey ?? $this->guessForeignKey();
        $lk  = $localKey ?? static::$primaryKey;
        $row = $related::query()->where($fk, $this->get($lk))->first();
        return $row === null ? null : $related::hydrate($row);
    }

    /**
     * Define an inverse relationship: this model's FK → parent model's PK.
     *
     * ```php
     * // In Post model
     * public function user(): ?User
     * {
     *     return $this->belongsTo(User::class);
     *     // SELECT * FROM users WHERE id = {$this->user_id} LIMIT 1
     * }
     * ```
     *
     * @template T of Model
     * @param  class-string<T> $related     Fully-qualified parent model class name.
     * @param  string|null     $foreignKey  FK column on THIS model (default: `{snake_related_class}_id`).
     * @param  string|null     $ownerKey    PK column on the parent model (default: `id`).
     * @return T|null
     */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): ?Model
    {
        $short = basename(str_replace('\\', '/', $related));
        $fk    = $foreignKey ?? strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short)) . '_id';
        $ok    = $ownerKey ?? 'id';
        $row   = $related::query()->where($ok, $this->get($fk))->first();
        return $row === null ? null : $related::hydrate($row);
    }

    /**
     * Many-to-many relationship via a pivot table.
     *
     * The pivot table name defaults to the two model names in alphabetical order,
     * snake_cased: `User ↔ Role` → `role_user`.
     *
     * ```php
     * // In User model
     * public function roles(): array
     * {
     *     return $this->belongsToMany(Role::class);
     *     // SELECT roles.* FROM roles
     *     //   JOIN role_user ON roles.id = role_user.role_id
     *     //   WHERE role_user.user_id = {$this->id}
     * }
     *
     * // Custom pivot / keys:
     * $this->belongsToMany(Role::class, 'user_roles', 'uid', 'rid');
     * ```
     *
     * @template T of Model
     * @param  class-string<T> $related     Fully-qualified related model class.
     * @param  string|null     $pivotTable  Pivot table name (default: alphabetical snake_case pair).
     * @param  string|null     $foreignKey  This model's FK on the pivot (default: `{this_snake}_id`).
     * @param  string|null     $relatedKey  Related model's FK on the pivot (default: `{related_snake}_id`).
     * @return T[]
     */
    public function belongsToMany(
        string $related,
        ?string $pivotTable = null,
        ?string $foreignKey = null,
        ?string $relatedKey = null,
    ): array {
        $thisSnake    = self::classToSnake(static::class);
        $relatedSnake = self::classToSnake($related);

        $names = [$thisSnake, $relatedSnake];
        sort($names);
        $pivot = $pivotTable ?? implode('_', $names);

        $fk = $foreignKey ?? "{$thisSnake}_id";
        $rk = $relatedKey ?? "{$relatedSnake}_id";

        $relatedTable = $related::tableName();
        $relatedPk    = $related::keyName();

        $rows = $related::query()
            ->join($pivot, "{$relatedTable}.{$relatedPk}", '=', "{$pivot}.{$rk}")
            ->where("{$pivot}.{$fk}", $this->get(static::$primaryKey))
            ->get();

        return array_map(fn($row) => $related::hydrate($row), $rows);
    }

    /**
     * Derive the conventional foreign-key name for this model.
     *
     * `App\Models\BlogPost` → `blog_post_id`
     */
    private function guessForeignKey(): string
    {
        return self::classToSnake(static::class) . '_id';
    }

    /** Convert a fully-qualified class name to snake_case short name. */
    private static function classToSnake(string $class): string
    {
        $short = basename(str_replace('\\', '/', $class));
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
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

    /**
     * Return all model attributes with casts applied.
     *
     * This is also used by {@see jsonSerialize()}, so JSON output reflects the
     * cast types (e.g. a `json` column becomes an array, not a string).
     */
    public function toArray(): array
    {
        if ($this->casts === []) {
            return $this->attributes;
        }
        $result = [];
        foreach ($this->attributes as $key => $value) {
            $result[$key] = isset($this->casts[$key]) ? $this->castForRead($key, $value) : $value;
        }
        return $result;
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

    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    public function __isset(string $key): bool
    {
        return array_key_exists($key, $this->attributes) && $this->attributes[$key] !== null;
    }

    private function isFillable(string $key): bool
    {
        if ($this->fillable !== []) {
            return in_array($key, $this->fillable, true);
        }
        if ($this->guarded !== []) {
            return !in_array($key, $this->guarded, true);
        }
        return true;
    }

    private function syncOriginal(): void
    {
        $this->original = $this->attributes;
    }

    // -----------------------------------------------------------------
    // Casting
    // -----------------------------------------------------------------

    /**
     * Cast a raw database value to the declared PHP type (on read).
     *
     * Returns `$value` unchanged when it is `null`.
     */
    private function castForRead(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->casts[$key]) {
            'int', 'integer'   => (int) $value,
            'float', 'double'  => (float) $value,
            'string'           => (string) $value,
            'bool', 'boolean'  => (bool) $value,
            'array', 'json'    => is_string($value)
                ? (json_decode($value, true) ?? [])
                : (array) $value,
            'datetime'         => $value instanceof \DateTimeInterface
                ? $value
                : new \DateTimeImmutable((string) $value),
            'date'             => $value instanceof \DateTimeInterface
                ? $value
                : new \DateTimeImmutable(substr((string) $value, 0, 10)),
            'timestamp'        => $value instanceof \DateTimeInterface
                ? $value
                : (new \DateTimeImmutable())->setTimestamp((int) $value),
            default            => $value,
        };
    }

    /**
     * Prepare a PHP value for storage (on write).
     *
     * Serialises types that cannot be stored directly (arrays → JSON,
     * DateTimeInterface → formatted string).
     */
    private function castForWrite(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->casts[$key]) {
            'array', 'json'   => (is_array($value) || is_object($value))
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : $value,
            'datetime', 'date' => $value instanceof \DateTimeInterface
                ? $value->format('Y-m-d H:i:s')
                : $value,
            'timestamp'        => $value instanceof \DateTimeInterface
                ? $value->getTimestamp()
                : $value,
            default            => $value,
        };
    }
}
