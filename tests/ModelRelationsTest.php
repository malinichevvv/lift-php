<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Database\Connection;
use Lift\Database\Events\ModelCreated;
use Lift\Database\Events\ModelCreating;
use Lift\Database\Events\ModelDeleted;
use Lift\Database\Events\ModelDeleting;
use Lift\Database\Events\ModelUpdated;
use Lift\Database\Events\ModelUpdating;
use Lift\Database\Model;
use Lift\Database\Paginator;
use Lift\Events\EventDispatcher;
use PHPUnit\Framework\TestCase;

// ---- Test fixtures ---------------------------------------------------

final class Author extends Model
{
    protected static string $table = 'authors';
    protected array $fillable = ['name'];
}

final class Article extends Model
{
    protected static string $table = 'articles';
    protected array $fillable = ['title', 'author_id'];

    public function author(): ?Author
    {
        return $this->belongsTo(Author::class);
    }
}

final class AuthorWithArticles extends Model
{
    protected static string $table = 'authors';
    protected array $fillable = ['name'];

    public function articles(): array
    {
        return $this->hasMany(Article::class, 'author_id');
    }
}

// ---- Test class -------------------------------------------------------

final class ModelRelationsTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }

        $this->db = new Connection('sqlite::memory:');
        $this->db->execute('CREATE TABLE authors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)');
        $this->db->execute('CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, author_id INTEGER NOT NULL)');

        Author::setConnection($this->db);
        Article::setConnection($this->db);
        AuthorWithArticles::setConnection($this->db);
    }

    // -----------------------------------------------------------------
    // hasMany
    // -----------------------------------------------------------------

    public function testHasMany(): void
    {
        $this->db->execute("INSERT INTO authors (name) VALUES ('Alice')");
        $this->db->execute("INSERT INTO authors (name) VALUES ('Bob')");
        $this->db->execute("INSERT INTO articles (title, author_id) VALUES ('A1', 1)");
        $this->db->execute("INSERT INTO articles (title, author_id) VALUES ('A2', 1)");
        $this->db->execute("INSERT INTO articles (title, author_id) VALUES ('B1', 2)");

        $author = AuthorWithArticles::find(1);
        $articles = $author->articles();

        $this->assertCount(2, $articles);
        $this->assertInstanceOf(Article::class, $articles[0]);
        $this->assertSame('A1', $articles[0]->get('title'));
        $this->assertSame('A2', $articles[1]->get('title'));
    }

    public function testHasManyEmptyRelation(): void
    {
        $this->db->execute("INSERT INTO authors (name) VALUES ('Lonely')");
        $author = AuthorWithArticles::find(1);
        $this->assertSame([], $author->articles());
    }

    // -----------------------------------------------------------------
    // belongsTo
    // -----------------------------------------------------------------

    public function testBelongsTo(): void
    {
        $this->db->execute("INSERT INTO authors (name) VALUES ('Carol')");
        $this->db->execute("INSERT INTO articles (title, author_id) VALUES ('Article', 1)");

        $article = Article::find(1);
        $author  = $article->author();

        $this->assertInstanceOf(Author::class, $author);
        $this->assertSame('Carol', $author->get('name'));
    }

    public function testBelongsToMissingParentReturnsNull(): void
    {
        $this->db->execute("INSERT INTO articles (title, author_id) VALUES ('Orphan', 999)");
        $article = Article::find(1);
        $this->assertNull($article->author());
    }

    // -----------------------------------------------------------------
    // Model lifecycle events
    // -----------------------------------------------------------------

    public function testModelCreatingAndCreatedEvents(): void
    {
        $fired = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->listen(ModelCreating::class, function (ModelCreating $e) use (&$fired) {
            $fired[] = 'creating';
        });
        $dispatcher->listen(ModelCreated::class, function (ModelCreated $e) use (&$fired) {
            $fired[] = 'created';
        });
        Model::setEventDispatcher($dispatcher);

        Author::create(['name' => 'Dan']);
        $this->assertSame(['creating', 'created'], $fired);
    }

    public function testModelCreatingCancelsInsert(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->listen(ModelCreating::class, function (ModelCreating $e) {
            $e->stopPropagation(); // cancel
        });
        Model::setEventDispatcher($dispatcher);

        $model = new Author(['name' => 'Ghost']);
        $result = $model->save();

        $this->assertFalse($result);
        $this->assertSame(0, (int) $this->db->table('authors')->count());
    }

    public function testModelUpdatingAndUpdatedEvents(): void
    {
        $this->db->execute("INSERT INTO authors (name) VALUES ('Eve')");
        $author = Author::find(1);
        $author->set('name', 'Eve Updated');

        $fired = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->listen(ModelUpdating::class, function () use (&$fired) { $fired[] = 'updating'; });
        $dispatcher->listen(ModelUpdated::class,  function () use (&$fired) { $fired[] = 'updated'; });
        Model::setEventDispatcher($dispatcher);

        $author->save();
        $this->assertSame(['updating', 'updated'], $fired);
    }

    public function testModelDeletingAndDeletedEvents(): void
    {
        $this->db->execute("INSERT INTO authors (name) VALUES ('Fred')");
        $author = Author::find(1);

        $fired = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->listen(ModelDeleting::class, function () use (&$fired) { $fired[] = 'deleting'; });
        $dispatcher->listen(ModelDeleted::class,  function () use (&$fired) { $fired[] = 'deleted'; });
        Model::setEventDispatcher($dispatcher);

        $author->delete();
        $this->assertSame(['deleting', 'deleted'], $fired);
    }

    public function testModelDeletingCancelsDeletion(): void
    {
        $this->db->execute("INSERT INTO authors (name) VALUES ('Greg')");
        $author = Author::find(1);

        $dispatcher = new EventDispatcher();
        $dispatcher->listen(ModelDeleting::class, fn(ModelDeleting $e) => $e->stopPropagation());
        Model::setEventDispatcher($dispatcher);

        $result = $author->delete();
        $this->assertFalse($result);
        $this->assertSame(1, (int) $this->db->table('authors')->count());
    }

    // -----------------------------------------------------------------
    // Paginator
    // -----------------------------------------------------------------

    public function testPaginatorBasic(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->db->execute("INSERT INTO authors (name) VALUES ('Author{$i}')");
        }

        $page = $this->db->table('authors')->orderBy('id')->paginate(2, 3, '/authors');

        $this->assertInstanceOf(Paginator::class, $page);
        $this->assertSame(10, $page->total());
        $this->assertSame(3,  $page->perPage());
        $this->assertSame(2,  $page->currentPage());
        $this->assertSame(4,  $page->lastPage());
        $this->assertSame(4,  $page->from());
        $this->assertSame(6,  $page->to());
        $this->assertCount(3, $page->items());
        $this->assertTrue($page->hasMorePages());
        $this->assertFalse($page->onFirstPage());
    }

    public function testPaginatorJsonSerialize(): void
    {
        $this->db->execute("INSERT INTO authors (name) VALUES ('X')");
        $page = $this->db->table('authors')->paginate(1, 5);
        $arr  = $page->toArray();

        $this->assertArrayHasKey('data', $arr);
        $this->assertArrayHasKey('total', $arr);
        $this->assertArrayHasKey('last_page', $arr);
        $this->assertSame(1, $arr['total']);
        $this->assertSame(1, $arr['last_page']);
    }

    public function testPaginatorLinks(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->db->execute("INSERT INTO authors (name) VALUES ('A{$i}')");
        }
        $page = $this->db->table('authors')->paginate(2, 5, '/authors');
        $links = $page->links();

        $this->assertStringContainsString('<nav', $links);
        $this->assertStringContainsString('page=1', $links);
        $this->assertStringContainsString('page=3', $links);
        $this->assertStringContainsString('Next', $links);
        $this->assertStringContainsString('Prev', $links);
    }

    public function testPaginatorNoLinksOnSinglePage(): void
    {
        $this->db->execute("INSERT INTO authors (name) VALUES ('Solo')");
        $page = $this->db->table('authors')->paginate(1, 10);
        $this->assertSame('', $page->links());
    }

    protected function tearDown(): void
    {
        // Reset global dispatcher after each test
        Model::setEventDispatcher(new EventDispatcher());
    }
}
