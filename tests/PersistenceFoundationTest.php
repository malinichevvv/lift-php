<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Database\Connection;
use Lift\Database\DatabaseManager;
use Lift\Database\Migration;
use Lift\Database\Migrator;
use Lift\Database\Model;
use Lift\Http\Session\ArraySessionStore;
use Lift\Http\Session\DatabaseSessionStore;
use Lift\Http\Session\FileSessionStore;
use Lift\Http\Session\MemcachedSessionStore;
use Lift\Http\Session\Session;
use PHPUnit\Framework\TestCase;

class PersistenceFoundationTest extends TestCase
{
    public function testArraySessionStorePersistsSessionData(): void
    {
        $store = new ArraySessionStore();
        $session = new Session($store, id: 'abc');
        $session->set('user_id', 10)->save();

        $next = new Session($store, id: 'abc');

        self::assertSame(10, $next->get('user_id'));
    }

    public function testFileSessionStorePersistsSessionData(): void
    {
        $dir = sys_get_temp_dir() . '/lift_sessions_' . bin2hex(random_bytes(4));
        $store = new FileSessionStore($dir);
        $session = new Session($store, id: 'file-id');
        $session->set('name', 'Alice')->save();

        $next = new Session($store, id: 'file-id');

        self::assertSame('Alice', $next->get('name'));
    }

    public function testDatabaseSessionStorePersistsSessionData(): void
    {
        $db = new Connection('sqlite::memory:');
        (new Migrator($db, __DIR__))->createSessionsTable();
        $store = new DatabaseSessionStore($db);
        $session = new Session($store, id: 'db-id');
        $session->set('name', 'Bob')->save();

        $next = new Session($store, id: 'db-id');

        self::assertSame('Bob', $next->get('name'));
    }

    public function testMigratorRunsAndRollsBackMigrations(): void
    {
        $dir = sys_get_temp_dir() . '/lift_migrations_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/2026_01_01_000000_create_widgets.php', <<<'PHP'
<?php

use Lift\Database\Migration;

return new class($db) extends Migration {
    public function up(): void { $this->db->execute('CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)'); }
    public function down(): void { $this->db->execute('DROP TABLE widgets'); }
};
PHP);

        $db = new Connection('sqlite::memory:');
        $migrator = new Migrator($db, $dir);

        self::assertSame(['2026_01_01_000000_create_widgets'], $migrator->migrate());
        $db->execute("INSERT INTO widgets (name) VALUES ('A')");
        self::assertSame(1, $db->table('widgets')->count());
        self::assertSame(['2026_01_01_000000_create_widgets'], $migrator->rollback());
    }

    public function testModelCreateSaveAndDelete(): void
    {
        $db = new Connection('sqlite::memory:');
        $db->execute('CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)');
        PostTestModel::setConnection($db);

        $post = PostTestModel::create(['title' => 'First']);
        self::assertSame(1, $post->getKey());

        $post->set('title', 'Updated')->save();
        self::assertSame('Updated', PostTestModel::find(1)?->get('title'));

        self::assertTrue($post->delete());
        self::assertNull(PostTestModel::find(1));
    }

    public function testDatabaseManagerLazilyResolvesConnections(): void
    {
        $manager = new DatabaseManager();
        $manager->add('default', ['driver' => 'sqlite', 'database' => ':memory:']);

        $db = $manager->connection();
        $db->execute('CREATE TABLE items (id INTEGER PRIMARY KEY AUTOINCREMENT)');

        self::assertSame('sqlite', $manager->connection()->getDriverName());
        self::assertSame($db, $manager->connection());
    }

    public function testMemcachedSessionStoreRejectsInvalidClient(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MemcachedSessionStore(new \stdClass());
    }
}

final class PostTestModel extends Model
{
    protected static string $table = 'posts';
    protected array $fillable = ['id', 'title'];
}
