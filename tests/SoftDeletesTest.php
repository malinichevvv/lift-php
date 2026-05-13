<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Database\Connection;
use Lift\Database\Model;
use Lift\Database\QueryBuilder;
use Lift\Database\SoftDeletes;
use PHPUnit\Framework\TestCase;

class SoftDeletesTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        $this->db = new Connection('sqlite::memory:');
        $this->db->execute('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            deleted_at TEXT
        )');
        $this->db->execute('CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT "user",
            active INTEGER NOT NULL DEFAULT 1
        )');

        SoftPost::setConnection($this->db);
        ScopedUser::setConnection($this->db);
    }

    // -----------------------------------------------------------------
    // SoftDeletes
    // -----------------------------------------------------------------

    public function testDeleteSetsSoftDeleteTimestamp(): void
    {
        $post = SoftPost::create(['title' => 'Hello']);
        self::assertTrue($post->delete());

        $raw = $this->db->selectOne('SELECT deleted_at FROM posts WHERE id = ?', [$post->getKey()]);
        self::assertNotNull($raw['deleted_at']);
    }

    public function testTrashedReturnsTrueAfterDelete(): void
    {
        $post = SoftPost::create(['title' => 'Hello']);
        $post->delete();

        self::assertTrue($post->trashed());
    }

    public function testQueryExcludesSoftDeletedByDefault(): void
    {
        SoftPost::create(['title' => 'Kept']);
        $deleted = SoftPost::create(['title' => 'Deleted']);
        $deleted->delete();

        $results = SoftPost::query()->get();
        self::assertCount(1, $results);
        self::assertSame('Kept', $results[0]['title']);
    }

    public function testFindExcludesSoftDeleted(): void
    {
        $post = SoftPost::create(['title' => 'Gone']);
        $id = $post->getKey();
        $post->delete();

        self::assertNull(SoftPost::find($id));
    }

    public function testWithTrashedIncludesAll(): void
    {
        SoftPost::create(['title' => 'Active']);
        $del = SoftPost::create(['title' => 'Deleted']);
        $del->delete();

        $results = SoftPost::withTrashed()->get();
        self::assertCount(2, $results);
    }

    public function testOnlyTrashedReturnsOnlyDeleted(): void
    {
        SoftPost::create(['title' => 'Active']);
        $del = SoftPost::create(['title' => 'Deleted']);
        $del->delete();

        $results = SoftPost::onlyTrashed()->get();
        self::assertCount(1, $results);
        self::assertSame('Deleted', $results[0]['title']);
    }

    public function testRestoreClearsSoftDelete(): void
    {
        $post = SoftPost::create(['title' => 'Hello']);
        $post->delete();
        self::assertTrue($post->trashed());

        $post->restore();
        self::assertFalse($post->trashed());

        $found = SoftPost::find($post->getKey());
        self::assertNotNull($found);
    }

    public function testForceDeleteRemovesRowPermanently(): void
    {
        $post = SoftPost::create(['title' => 'To delete']);
        $id = $post->getKey();
        $post->forceDelete();

        $raw = $this->db->selectOne('SELECT id FROM posts WHERE id = ?', [$id]);
        self::assertNull($raw);
    }

    public function testDeleteOnNonExistingModelReturnsFalse(): void
    {
        $post = new SoftPost();
        self::assertFalse($post->delete());
    }

    public function testForceDeleteOnNonExistingModelReturnsFalse(): void
    {
        $post = new SoftPost();
        self::assertFalse($post->forceDelete());
    }

    // -----------------------------------------------------------------
    // Local scopes
    // -----------------------------------------------------------------

    public function testLocalScopeFiltersResults(): void
    {
        $this->db->execute('INSERT INTO users (name, role, active) VALUES (?, ?, ?)', ['Alice', 'admin', 1]);
        $this->db->execute('INSERT INTO users (name, role, active) VALUES (?, ?, ?)', ['Bob', 'user', 1]);
        $this->db->execute('INSERT INTO users (name, role, active) VALUES (?, ?, ?)', ['Charlie', 'user', 0]);

        $active = ScopedUser::active()->get();
        self::assertCount(2, $active);
    }

    public function testLocalScopeCanAcceptArguments(): void
    {
        $this->db->execute('INSERT INTO users (name, role, active) VALUES (?, ?, ?)', ['Alice', 'admin', 1]);
        $this->db->execute('INSERT INTO users (name, role, active) VALUES (?, ?, ?)', ['Bob', 'user', 1]);

        $admins = ScopedUser::role('admin')->get();
        self::assertCount(1, $admins);
        self::assertSame('Alice', $admins[0]['name']);
    }

    public function testLocalScopesAreChainable(): void
    {
        $this->db->execute('INSERT INTO users (name, role, active) VALUES (?, ?, ?)', ['Alice', 'admin', 1]);
        $this->db->execute('INSERT INTO users (name, role, active) VALUES (?, ?, ?)', ['Bob', 'admin', 0]);

        $results = ScopedUser::active()->where('role', 'admin')->get();
        self::assertCount(1, $results);
        self::assertSame('Alice', $results[0]['name']);
    }

    public function testUndefinedStaticMethodThrowsBadMethodCallException(): void
    {
        $this->expectException(\BadMethodCallException::class);
        ScopedUser::nonExistentScope();
    }
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

final class SoftPost extends Model
{
    use SoftDeletes;

    protected static string $table = 'posts';
    protected array $fillable = ['title'];
}

final class ScopedUser extends Model
{
    protected static string $table = 'users';
    protected array $fillable = ['name', 'role', 'active'];

    public function scopeActive(QueryBuilder $query): void
    {
        $query->where('active', 1);
    }

    public function scopeRole(QueryBuilder $query, string $role): void
    {
        $query->where('role', $role);
    }
}
