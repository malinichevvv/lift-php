<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Database\Schema\Blueprint;
use Lift\Database\Schema\Schema;
use Lift\Database\Connection;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    // -----------------------------------------------------------------
    // Blueprint SQL compilation (no DB needed)
    // -----------------------------------------------------------------

    public function testCreateTableSqliteBasic(): void
    {
        $bp = new Blueprint('users');
        $bp->id();
        $bp->string('email')->unique();
        $bp->string('name', 100)->nullable();
        $bp->boolean('active')->default(true);
        $bp->timestamps();

        $sql = $bp->toSql('sqlite');
        $this->assertCount(1, $sql); // only CREATE TABLE (unique is inline)
        $ddl = $sql[0];

        $this->assertStringContainsString('CREATE TABLE "users"', $ddl);
        $this->assertStringContainsString('"id" INTEGER PRIMARY KEY AUTOINCREMENT', $ddl);
        $this->assertStringContainsString('"email" VARCHAR(255) NOT NULL UNIQUE', $ddl);
        $this->assertStringContainsString('"name" VARCHAR(100)', $ddl); // nullable → no NOT NULL
        $this->assertStringNotContainsString('"name" VARCHAR(100) NOT NULL', $ddl);
        $this->assertStringContainsString('"active" BOOLEAN NOT NULL DEFAULT 1', $ddl);
        $this->assertStringContainsString('"created_at" TIMESTAMP', $ddl);
        $this->assertStringContainsString('"updated_at" TIMESTAMP', $ddl);
    }

    public function testCreateTableMysqlBasic(): void
    {
        $bp = new Blueprint('posts');
        $bp->id();
        $bp->string('title');
        $bp->text('body')->nullable();
        $bp->integer('views')->default(0)->unsigned();

        $sql = $bp->toSql('mysql');
        $ddl = $sql[0];

        $this->assertStringContainsString('CREATE TABLE `posts`', $ddl);
        $this->assertStringContainsString('`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', $ddl);
        $this->assertStringContainsString('`title` VARCHAR(255) NOT NULL', $ddl);
        $this->assertStringContainsString('`body` TEXT', $ddl);
        $this->assertStringContainsString('`views` INTEGER UNSIGNED NOT NULL DEFAULT 0', $ddl);
    }

    public function testCreateTablePgsql(): void
    {
        $bp = new Blueprint('orders');
        $bp->bigIncrements();
        $bp->decimal('total', 10, 2)->default('0.00');
        $bp->dateTime('placed_at')->nullable();

        $sql = $bp->toSql('pgsql');
        $ddl = $sql[0];

        $this->assertStringContainsString('CREATE TABLE "orders"', $ddl);
        $this->assertStringContainsString('"id" BIGSERIAL PRIMARY KEY', $ddl);
        $this->assertStringContainsString('"total" DECIMAL(10,2) NOT NULL DEFAULT \'0.00\'', $ddl);
        $this->assertStringContainsString('"placed_at" TIMESTAMP', $ddl);
    }

    public function testIndexStatementsGenerated(): void
    {
        $bp = new Blueprint('articles');
        $bp->id();
        $bp->string('slug')->index();
        $bp->index(['title', 'status'], 'idx_articles_title_status');

        $sql = $bp->toSql('sqlite');
        $this->assertCount(3, $sql); // CREATE TABLE + 2 × CREATE INDEX

        $this->assertStringContainsString('CREATE INDEX', $sql[1]);
        $this->assertStringContainsString('"slug"', $sql[1]);

        $this->assertStringContainsString('idx_articles_title_status', $sql[2]);
        $this->assertStringContainsString('"title"', $sql[2]);
        $this->assertStringContainsString('"status"', $sql[2]);
    }

    public function testUniqueIndexStatement(): void
    {
        $bp = new Blueprint('tags');
        $bp->id();
        $bp->string('name');
        $bp->unique('name');

        $sql = $bp->toSql('sqlite');
        $uniqueSql = implode(' ', $sql);
        $this->assertStringContainsString('CREATE UNIQUE INDEX', $uniqueSql);
    }

    public function testForeignKey(): void
    {
        $bp = new Blueprint('comments');
        $bp->id();
        $bp->foreignId('post_id');
        $bp->foreignKey('post_id', 'posts', 'id', 'CASCADE', 'CASCADE');

        $sql = $bp->toSql('mysql');
        $ddl = $sql[0];
        $this->assertStringContainsString('FOREIGN KEY', $ddl);
        $this->assertStringContainsString('REFERENCES `posts`', $ddl);
        $this->assertStringContainsString('ON DELETE CASCADE', $ddl);
        $this->assertStringContainsString('ON UPDATE CASCADE', $ddl);
    }

    public function testEnumMysql(): void
    {
        $bp = new Blueprint('statuses');
        $bp->id();
        $bp->enum('status', ['active', 'inactive', 'banned']);

        $ddl = $bp->toSql('mysql')[0];
        $this->assertStringContainsString("ENUM(", $ddl);
        $this->assertStringContainsString("'active'", $ddl);
        $this->assertStringContainsString("'inactive'", $ddl);
        $this->assertStringContainsString("'banned'", $ddl);
    }

    public function testEnumSqlite(): void
    {
        $bp = new Blueprint('statuses');
        $bp->id();
        $bp->enum('status', ['a', 'b']);

        $ddl = $bp->toSql('sqlite')[0];
        $this->assertStringContainsString("VARCHAR(255) CHECK", $ddl);
        $this->assertStringContainsString("'a'", $ddl);
        $this->assertStringContainsString("'b'", $ddl);
        $this->assertStringContainsString('IN (', $ddl);
    }

    public function testJsonColumnType(): void
    {
        $bp = new Blueprint('settings');
        $bp->id();
        $bp->json('data');

        $this->assertStringContainsString('JSON', $bp->toSql('mysql')[0]);
        $this->assertStringContainsString('JSON', $bp->toSql('pgsql')[0]);
        $this->assertStringContainsString('TEXT', $bp->toSql('sqlite')[0]);
    }

    public function testAlterSql(): void
    {
        $bp = new Blueprint('users');
        $bp->string('phone', 20)->nullable();
        $bp->boolean('verified')->default(false);

        $stmts = $bp->toAlterSql('sqlite');
        $this->assertCount(2, $stmts);
        $this->assertStringContainsString('ALTER TABLE "users" ADD COLUMN', $stmts[0]);
        $this->assertStringContainsString('"phone"', $stmts[0]);
        $this->assertStringContainsString('"verified"', $stmts[1]);
    }

    public function testDefaultNull(): void
    {
        $bp = new Blueprint('t');
        $bp->string('note')->nullable()->default(null);
        $ddl = $bp->toSql('sqlite')[0];
        $this->assertStringContainsString('DEFAULT NULL', $ddl);
    }

    // -----------------------------------------------------------------
    // Live SQLite tests (require pdo_sqlite)
    // -----------------------------------------------------------------

    private function connection(): Connection
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }
        return new Connection('sqlite::memory:');
    }

    public function testSchemaCreateAndHasTable(): void
    {
        $schema = new Schema($this->connection());

        $schema->create('widgets', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });

        $this->assertTrue($schema->hasTable('widgets'));
        $this->assertFalse($schema->hasTable('nonexistent'));
    }

    public function testSchemaHasColumn(): void
    {
        $schema = new Schema($this->connection());

        $schema->create('items', function (Blueprint $t) {
            $t->id();
            $t->string('label');
        });

        $this->assertTrue($schema->hasColumn('items', 'label'));
        $this->assertFalse($schema->hasColumn('items', 'color'));
    }

    public function testSchemaDropIfExists(): void
    {
        $schema = new Schema($this->connection());

        $schema->create('temp', function (Blueprint $t) { $t->id(); });
        $this->assertTrue($schema->hasTable('temp'));

        $schema->dropIfExists('temp');
        $this->assertFalse($schema->hasTable('temp'));

        // Must not throw when table is already gone
        $schema->dropIfExists('temp');
        $this->assertTrue(true);
    }

    public function testSchemaAlterAddsColumn(): void
    {
        $db = $this->connection();
        $schema = new Schema($db);

        $schema->create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });

        $schema->alter('products', function (Blueprint $t) {
            $t->integer('stock')->default(0)->nullable();
        });

        $this->assertTrue($schema->hasColumn('products', 'stock'));
    }

    public function testSchemaRename(): void
    {
        $schema = new Schema($this->connection());

        $schema->create('old_name', function (Blueprint $t) { $t->id(); });
        $schema->rename('old_name', 'new_name');

        $this->assertTrue($schema->hasTable('new_name'));
        $this->assertFalse($schema->hasTable('old_name'));
    }

    public function testFullTableInsertAndSelect(): void
    {
        $db = $this->connection();
        $schema = new Schema($db);

        $schema->create('entries', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->boolean('active')->default(false);
            $t->timestamps();
        });

        $db->execute(
            "INSERT INTO \"entries\" (title, active, created_at, updated_at) VALUES (?, ?, ?, ?)",
            ['Hello', 1, '2026-01-01', '2026-01-01'],
        );

        $row = $db->selectOne('SELECT * FROM "entries" WHERE id = 1');
        $this->assertSame('Hello', $row['title']);
        $this->assertSame('1', (string) $row['active']);
    }
}
