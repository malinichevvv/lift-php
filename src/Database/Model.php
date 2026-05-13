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
     * Derive the conventional foreign-key name for this model.
     *
     * `App\Models\BlogPost` → `blog_post_id`
     */
    private function guessForeignKey(): string
    {
        $short = basename(str_replace('\\', '/', static::class));
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short)) . '_id';
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
