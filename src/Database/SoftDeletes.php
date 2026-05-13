<?php

declare(strict_types=1);

namespace Lift\Database;

use Lift\Database\Events\ModelDeleted;
use Lift\Database\Events\ModelDeleting;

/**
 * Opt-in soft-delete behaviour for Eloquent-style models.
 *
 * When a model uses this trait, `delete()` sets a `deleted_at` timestamp instead
 * of removing the row.  `query()` is automatically scoped to exclude soft-deleted
 * records; use `withTrashed()` or `onlyTrashed()` to bypass that filter.
 *
 * ```php
 * final class Post extends Model
 * {
 *     use SoftDeletes;
 *
 *     protected static string $table = 'posts';
 *     protected array $fillable = ['title'];
 * }
 *
 * $post = Post::find(1);
 * $post->delete();           // sets deleted_at; row stays in DB
 * Post::find(1);             // returns null (soft-deleted is excluded)
 * Post::withTrashed()->get();// returns all rows including soft-deleted
 * $post->restore();          // clears deleted_at
 * $post->forceDelete();      // permanently removes the row
 * ```
 */
trait SoftDeletes
{
    /** Column that stores the soft-delete timestamp. */
    protected static string $deletedAtColumn = 'deleted_at';

    /**
     * Override the base `query()` to exclude soft-deleted rows by default.
     */
    public static function query(): QueryBuilder
    {
        return parent::query()->whereNull(static::$deletedAtColumn);
    }

    /**
     * Return a query builder that includes soft-deleted rows.
     */
    public static function withTrashed(): QueryBuilder
    {
        if (static::$connection === null) {
            throw new \RuntimeException(
                'No database connection configured for model [' . static::class . ']'
            );
        }
        return static::$connection->table(static::tableName());
    }

    /**
     * Return a query builder scoped to only soft-deleted rows.
     */
    public static function onlyTrashed(): QueryBuilder
    {
        return static::withTrashed()->whereNotNull(static::$deletedAtColumn);
    }

    /**
     * Soft-delete the model (set `deleted_at`; keep the row).
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if (static::$dispatcher !== null) {
            $event = new ModelDeleting($this);
            static::$dispatcher->dispatch($event);
            if ($event->isPropagationStopped()) {
                return false;
            }
        }

        $now = date('Y-m-d H:i:s');
        static::withTrashed()
            ->where(static::$primaryKey, $this->getKey())
            ->update([static::$deletedAtColumn => $now]);
        $this->attributes[static::$deletedAtColumn] = $now;

        if (static::$dispatcher !== null) {
            static::$dispatcher->dispatch(new ModelDeleted($this));
        }

        return true;
    }

    /**
     * Clear `deleted_at` and un-delete the model.
     */
    public function restore(): bool
    {
        static::withTrashed()
            ->where(static::$primaryKey, $this->getKey())
            ->update([static::$deletedAtColumn => null]);
        $this->attributes[static::$deletedAtColumn] = null;
        return true;
    }

    /**
     * Permanently remove the row from the database.
     */
    public function forceDelete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        static::withTrashed()
            ->where(static::$primaryKey, $this->getKey())
            ->delete();
        $this->exists = false;
        return true;
    }

    /** Return `true` when this record is currently soft-deleted. */
    public function trashed(): bool
    {
        return $this->get(static::$deletedAtColumn) !== null;
    }
}
