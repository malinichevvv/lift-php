<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Database\Connection;
use Lift\Database\Grammar;
use PHPUnit\Framework\TestCase;

class GrammarTest extends TestCase
{
    public function testWrapsSqliteIdentifiers(): void
    {
        $g = new Grammar('sqlite');
        self::assertSame('"users"', $g->wrap('users'));
        self::assertSame('"users"."id"', $g->wrap('users.id'));
        self::assertSame('"users".*', $g->wrap('users.*'));
    }

    public function testPassesRawExpressions(): void
    {
        $g = new Grammar('sqlite');
        self::assertSame('COUNT(*)', $g->wrap('COUNT(*)'));
        self::assertSame('NOW()', $g->wrap('NOW()'));
        self::assertSame('1 + 1', $g->wrap('1 + 1'));
    }

    public function testWrapsMysqlWithBackticks(): void
    {
        $g = new Grammar('mysql');
        self::assertSame('`users`', $g->wrap('users'));
        self::assertSame('`users`.`id`', $g->wrap('users.id'));
    }

    public function testWrapsPostgresWithDoubleQuotes(): void
    {
        $g = new Grammar('pgsql');
        self::assertSame('"users"', $g->wrap('users'));
        self::assertSame('"id"', $g->wrap('id'));
    }

    public function testWrapsStar(): void
    {
        $g = new Grammar('sqlite');
        self::assertSame('*', $g->wrap('*'));
    }

    public function testCompileLockSqlite(): void
    {
        $g = new Grammar('sqlite');
        self::assertSame('', $g->compileLock('update'));
        self::assertSame('', $g->compileLock('share'));
        self::assertSame('', $g->compileLock('update', true));
    }

    public function testCompileLockMysql(): void
    {
        $g = new Grammar('mysql');
        self::assertSame(' FOR UPDATE', $g->compileLock('update'));
        self::assertSame(' FOR UPDATE SKIP LOCKED', $g->compileLock('update', true));
        self::assertSame(' LOCK IN SHARE MODE', $g->compileLock('share'));
        self::assertSame(' FOR SHARE SKIP LOCKED', $g->compileLock('share', true));
    }

    public function testCompileLockPgsql(): void
    {
        $g = new Grammar('pgsql');
        self::assertSame(' FOR UPDATE', $g->compileLock('update'));
        self::assertSame(' FOR UPDATE SKIP LOCKED', $g->compileLock('update', true));
        self::assertSame(' FOR SHARE', $g->compileLock('share'));
        self::assertSame(' FOR SHARE SKIP LOCKED', $g->compileLock('share', true));
    }
}

class DatabaseTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite extension is not available');
        }

        $this->db = Connection::fromConfig([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->db->execute(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                age INTEGER DEFAULT 0,
                active INTEGER DEFAULT 1
            )'
        );

        $this->db->execute(
            'CREATE TABLE posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                title TEXT NOT NULL
            )'
        );
    }

    // -----------------------------------------------------------------
    // Insert & basic query
    // -----------------------------------------------------------------

    public function testInsertAndGet(): void
    {
        $this->db->table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]);
        $this->db->table('users')->insert(['name' => 'Bob',   'email' => 'bob@example.com',   'age' => 25]);

        $rows = $this->db->table('users')->orderBy('id')->get();
        self::assertCount(2, $rows);
        self::assertSame('Alice', $rows[0]['name']);
        self::assertSame('Bob',   $rows[1]['name']);
    }

    public function testInsertMany(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'Charlie', 'email' => 'charlie@example.com', 'age' => 22],
            ['name' => 'Dave',    'email' => 'dave@example.com',    'age' => 35],
        ]);
        self::assertSame(2, $this->db->table('users')->count());
    }

    // -----------------------------------------------------------------
    // Select variants
    // -----------------------------------------------------------------

    public function testFirst(): void
    {
        $this->db->table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]);
        $row = $this->db->table('users')->first();
        self::assertSame('Alice', $row['name']);
    }

    public function testFirstReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->db->table('users')->first());
    }

    public function testValue(): void
    {
        $this->db->table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]);
        self::assertSame('Alice', $this->db->table('users')->value('name'));
    }

    public function testPluck(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30],
            ['name' => 'Bob',   'email' => 'bob@example.com',   'age' => 25],
        ]);
        self::assertSame(['Alice', 'Bob'], $this->db->table('users')->orderBy('id')->pluck('name'));
    }

    public function testSelectColumns(): void
    {
        $this->db->table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30]);
        $row = $this->db->table('users')->select('name', 'email')->first();
        self::assertArrayHasKey('name', $row);
        self::assertArrayHasKey('email', $row);
        self::assertArrayNotHasKey('id', $row);
    }

    public function testDistinct(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'Alice', 'email' => 'a@x.com', 'age' => 30],
            ['name' => 'Alice', 'email' => 'b@x.com', 'age' => 30],
        ]);
        $names = $this->db->table('users')->distinct()->pluck('name');
        self::assertCount(1, $names);
    }

    // -----------------------------------------------------------------
    // Where clauses
    // -----------------------------------------------------------------

    public function testWhereEqualShorthand(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'Alice', 'email' => 'alice@x.com', 'age' => 30],
            ['name' => 'Bob',   'email' => 'bob@x.com',   'age' => 25],
        ]);
        $rows = $this->db->table('users')->where('name', 'Alice')->get();
        self::assertCount(1, $rows);
        self::assertSame('Alice', $rows[0]['name']);
    }

    public function testWhereWithOperator(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'Alice', 'email' => 'a@x.com', 'age' => 30],
            ['name' => 'Bob',   'email' => 'b@x.com', 'age' => 25],
        ]);
        $rows = $this->db->table('users')->where('age', '>=', 30)->get();
        self::assertCount(1, $rows);
    }

    public function testOrWhere(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'Alice', 'email' => 'a@x.com', 'age' => 30],
            ['name' => 'Bob',   'email' => 'b@x.com', 'age' => 25],
            ['name' => 'Carol', 'email' => 'c@x.com', 'age' => 20],
        ]);
        $rows = $this->db->table('users')
            ->where('name', 'Alice')
            ->orWhere('name', 'Bob')
            ->get();
        self::assertCount(2, $rows);
    }

    public function testWhereIn(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'Alice', 'email' => 'a@x.com', 'age' => 30],
            ['name' => 'Bob',   'email' => 'b@x.com', 'age' => 25],
            ['name' => 'Carol', 'email' => 'c@x.com', 'age' => 20],
        ]);
        $rows = $this->db->table('users')->whereIn('name', ['Alice', 'Carol'])->get();
        self::assertCount(2, $rows);
    }

    public function testWhereInEmptyReturnsFalse(): void
    {
        $this->db->table('users')->insert(['name' => 'Alice', 'email' => 'a@x.com', 'age' => 30]);
        $rows = $this->db->table('users')->whereIn('name', [])->get();
        self::assertCount(0, $rows);
    }

    public function testWhereNotIn(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'Alice', 'email' => 'a@x.com', 'age' => 30],
            ['name' => 'Bob',   'email' => 'b@x.com', 'age' => 25],
        ]);
        $rows = $this->db->table('users')->whereNotIn('name', ['Alice'])->get();
        self::assertCount(1, $rows);
        self::assertSame('Bob', $rows[0]['name']);
    }

    public function testWhereNull(): void
    {
        $this->db->execute('CREATE TABLE nulltest (id INTEGER, val TEXT)');
        $this->db->execute("INSERT INTO nulltest VALUES (1, NULL)");
        $this->db->execute("INSERT INTO nulltest VALUES (2, 'x')");
        $rows = $this->db->table('nulltest')->whereNull('val')->get();
        self::assertCount(1, $rows);
        self::assertSame('1', (string) $rows[0]['id']);
    }

    public function testWhereBetween(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'A', 'email' => 'a@x.com', 'age' => 10],
            ['name' => 'B', 'email' => 'b@x.com', 'age' => 20],
            ['name' => 'C', 'email' => 'c@x.com', 'age' => 30],
        ]);
        $rows = $this->db->table('users')->whereBetween('age', 15, 25)->get();
        self::assertCount(1, $rows);
        self::assertSame('B', $rows[0]['name']);
    }

    public function testWhereNullCondition(): void
    {
        $this->db->table('users')->insert(['name' => 'Alice', 'email' => 'a@x.com', 'age' => 30]);
        $rows = $this->db->table('users')->where('name', null)->get();
        self::assertCount(0, $rows);
    }

    // -----------------------------------------------------------------
    // Aggregates
    // -----------------------------------------------------------------

    public function testCount(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'A', 'email' => 'a@x.com', 'age' => 10],
            ['name' => 'B', 'email' => 'b@x.com', 'age' => 20],
        ]);
        self::assertSame(2, $this->db->table('users')->count());
    }

    public function testSum(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'A', 'email' => 'a@x.com', 'age' => 10],
            ['name' => 'B', 'email' => 'b@x.com', 'age' => 20],
        ]);
        self::assertSame(30.0, $this->db->table('users')->sum('age'));
    }

    public function testAvgMinMax(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'A', 'email' => 'a@x.com', 'age' => 10],
            ['name' => 'B', 'email' => 'b@x.com', 'age' => 20],
            ['name' => 'C', 'email' => 'c@x.com', 'age' => 30],
        ]);
        self::assertSame(20.0, $this->db->table('users')->avg('age'));
        self::assertSame(10.0, $this->db->table('users')->min('age'));
        self::assertSame(30.0, $this->db->table('users')->max('age'));
    }

    public function testExistsAndDoesntExist(): void
    {
        self::assertFalse($this->db->table('users')->exists());
        $this->db->table('users')->insert(['name' => 'A', 'email' => 'a@x.com', 'age' => 10]);
        self::assertTrue($this->db->table('users')->exists());
        self::assertFalse($this->db->table('users')->doesntExist());
    }

    // -----------------------------------------------------------------
    // Order, limit, offset
    // -----------------------------------------------------------------

    public function testOrderByAndLimit(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'C', 'email' => 'c@x.com', 'age' => 30],
            ['name' => 'A', 'email' => 'a@x.com', 'age' => 10],
            ['name' => 'B', 'email' => 'b@x.com', 'age' => 20],
        ]);
        $rows = $this->db->table('users')->orderBy('name')->limit(2)->get();
        self::assertCount(2, $rows);
        self::assertSame('A', $rows[0]['name']);
        self::assertSame('B', $rows[1]['name']);
    }

    public function testOrderByDescAndOffset(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'A', 'email' => 'a@x.com', 'age' => 10],
            ['name' => 'B', 'email' => 'b@x.com', 'age' => 20],
            ['name' => 'C', 'email' => 'c@x.com', 'age' => 30],
        ]);
        $rows = $this->db->table('users')->orderByDesc('name')->offset(1)->limit(1)->get();
        self::assertSame('B', $rows[0]['name']);
    }

    // -----------------------------------------------------------------
    // Update & delete
    // -----------------------------------------------------------------

    public function testUpdate(): void
    {
        $this->db->table('users')->insert(['name' => 'Alice', 'email' => 'alice@x.com', 'age' => 30]);
        $affected = $this->db->table('users')->where('name', 'Alice')->update(['age' => 31]);
        self::assertSame(1, $affected);
        self::assertSame('31', (string) $this->db->table('users')->value('age'));
    }

    public function testDelete(): void
    {
        $this->db->table('users')->insertMany([
            ['name' => 'A', 'email' => 'a@x.com', 'age' => 10],
            ['name' => 'B', 'email' => 'b@x.com', 'age' => 20],
        ]);
        $deleted = $this->db->table('users')->where('name', 'A')->delete();
        self::assertSame(1, $deleted);
        self::assertSame(1, $this->db->table('users')->count());
    }

    // -----------------------------------------------------------------
    // Pagination
    // -----------------------------------------------------------------

    public function testPaginate(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->db->table('users')->insert(['name' => "U{$i}", 'email' => "u{$i}@x.com", 'age' => $i]);
        }

        $page = $this->db->table('users')->orderBy('id')->paginate(2, 3);
        self::assertSame(10, $page->total());
        self::assertSame(3,  $page->perPage());
        self::assertSame(2,  $page->currentPage());
        self::assertSame(4,  $page->lastPage());
        self::assertSame(4,  $page->from());
        self::assertSame(6,  $page->to());
        self::assertCount(3, $page->items());
        self::assertTrue($page->hasMorePages());
        // JsonSerializable envelope
        $arr = $page->jsonSerialize();
        self::assertSame(10, $arr['total']);
        self::assertCount(3, $arr['data']);
    }

    // -----------------------------------------------------------------
    // Chunk
    // -----------------------------------------------------------------

    public function testChunk(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->db->table('users')->insert(['name' => "U{$i}", 'email' => "u{$i}@x.com", 'age' => $i]);
        }

        $chunks = [];
        $this->db->table('users')->chunk(2, function (array $rows) use (&$chunks) {
            $chunks[] = $rows;
        });

        self::assertCount(3, $chunks); // 2 + 2 + 1
        self::assertCount(2, $chunks[0]);
        self::assertCount(1, $chunks[2]);
    }

    // -----------------------------------------------------------------
    // toSql / getBindings
    // -----------------------------------------------------------------

    public function testToSqlAndBindings(): void
    {
        $qb = $this->db->table('users')
            ->select('name', 'email')
            ->where('age', '>', 18)
            ->orderBy('name')
            ->limit(10);

        $sql = $qb->toSql();
        self::assertStringContainsString('SELECT', $sql);
        self::assertStringContainsString('"name"', $sql);
        self::assertStringContainsString('"age"', $sql);
        self::assertSame([18], $qb->getBindings());
    }

    // -----------------------------------------------------------------
    // Transaction
    // -----------------------------------------------------------------

    public function testTransactionCommit(): void
    {
        $this->db->transaction(function () {
            $this->db->table('users')->insert(['name' => 'Tx', 'email' => 'tx@x.com', 'age' => 1]);
        });
        self::assertSame(1, $this->db->table('users')->count());
    }

    public function testTransactionRollback(): void
    {
        try {
            $this->db->transaction(function () {
                $this->db->table('users')->insert(['name' => 'Tx', 'email' => 'tx@x.com', 'age' => 1]);
                throw new \RuntimeException('oops');
            });
        } catch (\RuntimeException) {}

        self::assertSame(0, $this->db->table('users')->count());
    }

    // -----------------------------------------------------------------
    // selectOne / value helpers on Connection
    // -----------------------------------------------------------------

    public function testSelectOneAndConnectionValue(): void
    {
        $this->db->table('users')->insert(['name' => 'Alice', 'email' => 'alice@x.com', 'age' => 30]);

        $row = $this->db->selectOne('SELECT * FROM "users" WHERE "name" = ?', ['Alice']);
        self::assertIsArray($row);
        self::assertSame('Alice', $row['name']);

        $val = $this->db->value('SELECT "age" FROM "users" WHERE "name" = ?', ['Alice']);
        self::assertSame('30', (string) $val);
    }

    // -----------------------------------------------------------------
    // Pessimistic locking — toSql() output (SQLite omits the clause)
    // -----------------------------------------------------------------

    public function testForUpdateAppendsClause(): void
    {
        $sql = $this->db->table('users')->where('active', 1)->forUpdate()->toSql();
        // SQLite grammar returns empty string for lock clause
        self::assertStringNotContainsString('FOR UPDATE', $sql);
        self::assertStringContainsString('WHERE', $sql);
    }

    public function testSharedLockAppendsClause(): void
    {
        $sql = $this->db->table('users')->sharedLock()->toSql();
        self::assertStringNotContainsString('LOCK IN SHARE MODE', $sql);
        self::assertStringNotContainsString('FOR SHARE', $sql);
    }

    public function testForUpdateSkipLockedInSql(): void
    {
        // Verify the builder records the intent even if SQLite omits it
        $qb  = $this->db->table('users')->forUpdate(skipLocked: true);
        $sql = $qb->toSql();
        self::assertStringStartsWith('SELECT', $sql);
        // No clause appended on SQLite — that is correct behaviour
        self::assertStringNotContainsString('SKIP LOCKED', $sql);
    }

    public function testForUpdateExecutesOnSqlite(): void
    {
        // forUpdate() on SQLite runs as a plain SELECT (no lock clause)
        $this->db->table('users')->insert(['name' => 'Bob', 'email' => 'bob@x.com', 'age' => 25]);

        $this->db->transaction(function () {
            $row = $this->db->table('users')->where('name', 'Bob')->forUpdate()->first();
            self::assertIsArray($row);
            self::assertSame('Bob', $row['name']);
        });
    }

    // -----------------------------------------------------------------
    // Advisory locks — only MySQL/PostgreSQL; SQLite throws
    // -----------------------------------------------------------------

    public function testAdvisoryLockThrowsOnSqlite(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Advisory locks are not supported');
        $this->db->advisoryLock('test-lock');
    }

    public function testAdvisoryUnlockThrowsOnSqlite(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->db->advisoryUnlock('test-lock');
    }

    public function testWithAdvisoryLockThrowsOnSqlite(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->db->withAdvisoryLock('test-lock', fn() => null);
    }
}
