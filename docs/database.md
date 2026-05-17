---
layout: page
title: Database
nav_order: 18
---

# Database

Lift ships a small but real database layer on top of PDO: a fluent query builder, schema/migrations, an optional active-record model, soft deletes, pagination, and multi-connection support. **MySQL, PostgreSQL, and SQLite** are supported out of the box.

> Mental model: everything starts from a `Connection` (one per database). `$db->table('users')` gives you a fluent `QueryBuilder`. `Schema` runs DDL through the same connection. `Model` is a thin object wrapper around the builder — you can ignore it entirely if you prefer query-builder style.

## 1. Connect

The cleanest way: build a `Connection` once and put it in the container.

```php
use Lift\Database\Connection;

$app->singleton(Connection::class, fn() => Connection::fromConfig([
    'driver'   => 'mysql',          // mysql | mariadb | pgsql | sqlite
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'myapp',
    'username' => 'app',
    'password' => $_ENV['DB_PASS'],
    'charset'  => 'utf8mb4',
]));
```

SQLite (great for prototypes & tests):

```php
Connection::fromConfig([
    'driver'   => 'sqlite',
    'database' => __DIR__ . '/../database.sqlite',   // or ':memory:'
]);
```

Then inject anywhere:

```php
class UserRepository
{
    public function __construct(private readonly Connection $db) {}

    public function all(): array
    {
        return $this->db->table('users')->orderBy('id')->get();
    }
}
```

Or build one directly when you don't want DI yet:

```php
$db = new Connection('sqlite::memory:');
```

PDO error mode is set to `ERRMODE_EXCEPTION` and emulated prepares are off — failures throw `PDOException` / `RuntimeException` with the real driver message.

### Multiple connections

`DatabaseManager` keeps named connections lazy:

```php
use Lift\Database\DatabaseManager;

$db = DatabaseManager::fromConfig([
    'default' => 'main',
    'connections' => [
        'main'      => ['driver' => 'mysql',  'host' => '...', 'database' => 'app'],
        'analytics' => ['driver' => 'pgsql', 'host' => '...', 'database' => 'analytics'],
        'cache_db'  => ['driver' => 'sqlite', 'database' => '/tmp/cache.sqlite'],
    ],
]);

$users    = $db->table('users')->get();              // default = main
$events   = $db->table('events', 'analytics')->count();
```

Only the first call to `table('…', 'analytics')` opens the second PDO.

## 2. Query builder — reads

Start a query with `$db->table('foo')`. Every method returns `$this`, so chain them.

```php
$users = $db->table('users')
    ->select('id', 'name', 'email')
    ->where('active', 1)
    ->where('age', '>=', 18)
    ->orderBy('name')
    ->limit(20)
    ->get();              // [['id' => 1, …], …]
```

### Selecting

```php
->select('id', 'name')
->addSelect('email')         // append more columns
->distinct()
```

By default `SELECT *`.

### Where clauses

```php
->where('status', 'active')                 // status = 'active'  (2-arg form)
->where('age',    '>=',  18)                // age >= 18
->where('name',   'LIKE', 'Al%')

->orWhere('status', 'pending')

->whereIn   ('id',  [1, 2, 3])
->whereNotIn('id',  $bannedIds)

->whereNull   ('deleted_at')
->whereNotNull('verified_at')

->whereBetween('age', 18, 65)

->whereRaw('json_extract(meta, "$.role") = ?', ['admin'])
```

`where('column', null)` is a shortcut for `whereNull('column')`. The supported operators are `=`, `<`, `>`, `<=`, `>=`, `<>`, `!=`, `LIKE`, `NOT LIKE`, `ILIKE` — invalid ones throw `InvalidArgumentException` (which prevents SQL injection through the operator argument).

> **Never** interpolate user input into column/table names. Values are bound parameters automatically; identifiers go through `Grammar::wrap()`. Plain names (`users`, `u.name`) are quoted; anything else is treated as a raw expression so that `COUNT(*)` and aliases keep working.
>
> **Since 1.2.1:** `Grammar::wrap()` rejects a raw expression that contains a statement separator (`;`), an SQL comment (`--`, `/* */`), a NUL byte, or a newline with an `InvalidArgumentException`. This catches the common mistake of passing user input as a column or `orderBy()` name — but it is a safety net, not a substitute for validating identifiers against your own allow-list.

### JOINs

```php
$db->table('orders')
    ->select('orders.id', 'orders.total', 'users.email')
    ->join     ('users', 'orders.user_id', '=', 'users.id')
    ->leftJoin ('addresses', 'orders.address_id', '=', 'addresses.id')
    ->rightJoin('payments',  'orders.id',         '=', 'payments.order_id')
    ->where('orders.status', 'paid')
    ->get();
```

### Grouping / ordering / paging

```php
->groupBy('status', 'country')
->having('count', '>', 5)

->orderBy('created_at', 'DESC')
->orderByDesc('id')
->latest('created_at')   // ORDER BY created_at DESC
->oldest('created_at')   // ORDER BY created_at ASC

->limit(20)
->offset(40)
->take(20)               // alias for limit
->skip(40)               // alias for offset
```

### Fetching

```php
->get();              // array of rows
->first();            // first row or null
->value('email');     // single scalar from first row
->pluck('email');     // array of one column from all matching rows
->exists();           // bool
->doesntExist();      // bool

->count();            // int
->count('email');     // count non-null emails
->sum('amount');
->avg('rating');
->min('price');
->max('price');
```

### See the SQL without running it

```php
$sql      = $db->table('users')->where('active', 1)->toSql();    // string
$bindings = $db->table('users')->where('active', 1)->getBindings(); // [1]
```

Great for debugging and writing tests that don't hit the DB.

## 3. Query builder — writes

```php
// INSERT — returns the last insert ID (string|false)
$id = $db->table('users')->insert([
    'name'  => 'Alice',
    'email' => 'a@b.c',
]);

// Bulk INSERT — single round-trip, no return value
$db->table('logs')->insertMany([
    ['level' => 'info',  'msg' => 'one'],
    ['level' => 'error', 'msg' => 'two'],
]);

// UPDATE — returns affected row count
$db->table('users')
    ->where('id', 42)
    ->update(['name' => 'Bobby', 'updated_at' => date('Y-m-d H:i:s')]);

// DELETE — returns affected row count
$db->table('sessions')->where('expires_at', '<', date('Y-m-d H:i:s'))->delete();
```

Calling `update()` / `delete()` **without** any `where()` will affect every row. Always double-check.

## 4. Pagination

```php
$page = $db->table('posts')
    ->where('published', 1)
    ->orderBy('created_at', 'DESC')
    ->paginate(page: 2, perPage: 15, path: '/posts');

return Response::json($page);
```

Returns a `Paginator` that implements `JsonSerializable`, so handing it to `Response::json()` produces:

```json
{
  "data":         [ /* 15 rows */ ],
  "total":        324,
  "per_page":     15,
  "current_page": 2,
  "last_page":    22,
  "from":         16,
  "to":           30
}
```

Other methods:

```php
$page->items();          // raw row array
$page->total();
$page->currentPage();
$page->lastPage();
$page->hasMorePages();
$page->onFirstPage();
$page->links();          // simple HTML pagination bar with «Prev / 1 2 … / Next»
```

`$page->links()` is intentionally minimal — render your own HTML if you want a fancier control.

## 5. Chunking — large result sets

When you can't load everything into RAM:

```php
$db->table('users')
    ->orderBy('id')
    ->chunk(500, function (array $rows, int $page) use ($mailer) {
        foreach ($rows as $row) {
            $mailer->send($row['email'], 'Newsletter');
        }
        // return false to stop early
    });
```

Lift loads 500 rows at a time and calls your callback. Keep an `orderBy('id')` (or another stable column) — otherwise you'll skip / re-process rows when the DB reorders results.

### cursor() — streaming iteration

`cursor()` returns a generator that fetches **one row at a time** from the database. Unlike `chunk()`, there is no callback — use a plain `foreach`. Memory stays near-constant regardless of table size.

```php
foreach ($db->table('events')->where('processed', 0)->orderBy('id')->cursor() as $row) {
    processEvent($row);
}
```

When to prefer `cursor()` over `chunk()`:

| | `chunk()` | `cursor()` |
|---|---|---|
| API | callback-based | `foreach` generator |
| Memory per step | N rows (chunk size) | 1 row |
| Early exit | `return false` in callback | `break` in `foreach` |
| Suitable when | chunk-level operations needed | row-by-row streaming |

## 6. Transactions

The closure form is recommended — it commits on success and rolls back on any exception:

```php
$id = $db->transaction(function (Connection $db) {
    $orderId = $db->table('orders')->insert([
        'user_id' => 42,
        'total'   => 100.0,
    ]);
    $db->table('items')->insertMany([
        ['order_id' => $orderId, 'sku' => 'A1', 'qty' => 1],
        ['order_id' => $orderId, 'sku' => 'B2', 'qty' => 2],
    ]);
    return $orderId;
});
```

Manual form when you need finer control:

```php
$db->beginTransaction();
try {
    // …
    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    throw $e;
}

$db->inTransaction();   // bool
```

## 7. Pessimistic locking

When two processes might race over the same rows (queue, counter, …):

```php
$db->transaction(function (Connection $db) {
    $job = $db->table('jobs')
        ->where('status', 'pending')
        ->orderBy('id')
        ->forUpdate(skipLocked: true)   // FOR UPDATE SKIP LOCKED (mysql 8 / pg)
        ->first();

    if ($job !== null) {
        $db->table('jobs')->where('id', $job['id'])->update(['status' => 'running']);
    }
});
```

| Method                       | SQL                                                |
|------------------------------|----------------------------------------------------|
| `forUpdate()`                | `... FOR UPDATE`                                   |
| `forUpdate(skipLocked: true)`| `... FOR UPDATE SKIP LOCKED`                       |
| `sharedLock()`               | `... FOR SHARE` (PG) / `LOCK IN SHARE MODE` (MySQL)|

> On SQLite the lock clause is silently omitted — SQLite locks the whole DB during a write transaction anyway.

## 8. Advisory (named) locks

For "only one process should run this at a time" without table locks:

```php
// Block until acquired or timeout in seconds
$db->withAdvisoryLock('daily-report', function (Connection $db) {
    generateReport($db);
}, timeout: 30);

// Manual
if ($db->advisoryLock('export', timeout: 10)) {
    try {
        // …
    } finally {
        $db->advisoryUnlock('export');
    }
}
```

Supported on MySQL & PostgreSQL. SQLite throws `RuntimeException`.

## 9. Raw queries

When the builder can't express what you need:

```php
$rows  = $db->select   ('SELECT * FROM users WHERE id IN (?, ?, ?)', [1, 2, 3]);
$row   = $db->selectOne('SELECT * FROM users WHERE id = ?', [42]);
$count = $db->value    ('SELECT COUNT(*) FROM users WHERE active = ?', [1]);

$affected = $db->execute(
    'UPDATE users SET last_seen = ? WHERE id = ?',
    [time(), 42],
);

$lastId = $db->lastInsertId();
```

Always pass user input as bound parameters (`?` placeholders + `$bindings` array). **Never** `"WHERE id = " . $_GET['id']`.

## 10. Query listener (debug / metrics)

Subscribe to every executed query:

```php
$db->onQuery(function (string $sql, array $bindings, float $ms): void {
    error_log(sprintf('[%.1f ms] %s | %s', $ms, $sql, json_encode($bindings)));
});
```

The debug toolbar uses this internally. Slow-query alerting is two lines on top.

## 11. Schema & migrations

Two pieces:
- `Schema` — DDL helper (CREATE / ALTER / DROP).
- `Migrator` — versioned change runner.

### Schema (without migrations)

```php
use Lift\Database\Schema\Schema;

$schema = new Schema($db);

$schema->create('users', function ($table) {
    $table->id();
    $table->string('email', 200)->unique();
    $table->string('password');
    $table->boolean('active')->default(true);
    $table->json('settings')->nullable();
    $table->timestamps();        // created_at + updated_at
});

$schema->alter('users', function ($table) {
    $table->string('avatar_url', 500)->nullable();
});

$schema->dropIfExists('old_table');
$schema->rename('users_v1', 'users');

$schema->hasTable('users');       // bool
$schema->hasColumn('users', 'email');
```

### Column types

```php
$table->id();           $table->bigIncrements('order_id');
$table->string($n, 200); $table->char($n, 32);
$table->text($n);       $table->mediumText($n);  $table->longText($n);

$table->integer($n);    $table->bigInteger($n);  $table->smallInteger($n);
$table->tinyInteger($n);$table->decimal($n, 10, 2);
$table->float($n);      $table->double($n);

$table->boolean($n);    $table->binary($n);
$table->date($n);       $table->dateTime($n);    $table->time($n);
$table->timestamp($n);  $table->timestamps();    // created_at + updated_at
$table->softDeletes();  // deleted_at (nullable)

$table->json($n);
$table->uuid($n);
$table->enum($n, ['admin','user']);
$table->foreignId($n);  // unsigned big int suitable for FK
```

Per-column modifiers:

```php
->nullable()
->default($value)
->index()
->unique()
->primary()
->after('email')                 // MySQL only
->foreign('users', 'id')         // FK → users.id  (sets up the constraint)
->onDelete('cascade')->onUpdate('cascade')
```

### Migrations

Each migration is a single PHP file returning a `Migration` instance:

```php
// database/migrations/2025_05_14_120000_create_posts_table.php
use Lift\Database\Migration;
use Lift\Database\Schema\Schema;

return new class($db) extends Migration {
    public function up(): void
    {
        (new Schema($this->db))->create('posts', function ($t) {
            $t->id();
            $t->string('title');
            $t->text('body');
            $t->foreignId('user_id')->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        (new Schema($this->db))->dropIfExists('posts');
    }
};
```

The file basename (without `.php`) becomes the migration name and is what's stored in the `migrations` table.

Run them:

```php
use Lift\Database\Migrator;

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');

$migrator->migrate();     // run all pending — returns array of names
$migrator->rollback();    // roll back the last batch
$migrator->rollback(3);   // roll back the last 3 batches
$migrator->reset();       // roll back everything
$migrator->fresh();       // reset + migrate (start over)
$migrator->status();      // [['migration'=>'...','ran'=>bool,'batch'=>int|null], …]
```

Via the CLI (`vendor/bin/lift`):

```bash
lift make:migration create_posts_table
lift migrate
lift migrate:rollback
lift migrate:status
lift migrate:fresh
```

`Migrator` creates a `migrations` table the first time it runs — no separate setup step.

> Best practice: keep migrations **append-only** in main. Never edit a migration that's already been deployed; write a new one.

## 12. Model (active record)

Optional thin wrapper. Skip this section entirely if you prefer query-builder style.

```php
use Lift\Database\Model;

final class User extends Model
{
    protected static string $table      = 'users';
    protected static string $primaryKey = 'id';
    protected array $fillable           = ['name', 'email', 'role'];
}

User::setConnection($db);

// CRUD
$user = User::find(1);
$user = User::create(['name' => 'Alice', 'email' => 'a@b.c']);
$user->set('name', 'Updated')->save();
$user->delete();

// Query
$users  = User::query()->where('active', 1)->get();   // raw rows
$active = User::query()->where('active', 1)->first();

// Bulk
foreach ($users as $row) {
    $user = User::hydrate($row);     // wrap row in a Model without re-querying
}

// Dirty tracking
$user->set('name', 'X');
$user->dirty();                       // ['name' => 'X']
$user->save();                        // only updates `name`
```

### Mass-assignment safety

Either an allow-list (`$fillable`) or a deny-list (`$guarded`) — never both:

```php
protected array $fillable = ['name', 'email'];   // ONLY these are mass-assignable

// OR (mutually exclusive):
protected array $guarded = ['id', 'is_admin'];   // everything else IS mass-assignable
```

Calling `new User($request->json())` only copies allowed keys; the rest are silently dropped.

### Attribute casts

Declare `$casts` to convert raw database values to typed PHP values automatically.

```php
final class Post extends Model
{
    protected static string $table = 'posts';
    protected array $fillable      = ['title', 'body', 'meta', 'published', 'published_at'];

    protected array $casts = [
        'published'    => 'bool',
        'view_count'   => 'int',
        'score'        => 'float',
        'meta'         => 'json',         // array ↔ JSON string
        'published_at' => 'datetime',     // string ↔ DateTimeImmutable
        'expires_on'   => 'date',         // date portion only
        'expires_ts'   => 'timestamp',    // Unix int ↔ DateTimeImmutable
    ];
}
```

Casting happens **transparently**:

```php
$post = Post::find(1);

$post->get('published');    // bool — not "1" / "0"
$post->get('meta');         // ['key' => 'val'] — not '{"key":"val"}'
$post->get('published_at'); // DateTimeImmutable — not '2026-05-15 10:00:00'

// Write — serialises back automatically
$post->set('meta', ['theme' => 'dark']);   // stored as '{"theme":"dark"}'
$post->set('published_at', new \DateTimeImmutable('now'));  // stored as '2026-05-15 …'
$post->save();

// toArray() and JSON output also reflect casts
Response::json($post);   // 'meta' is an object, 'published' is true/false
```

Supported cast types:

| Type | PHP read type | Write serialisation |
|---|---|---|
| `int` / `integer` | `int` | — |
| `float` / `double` | `float` | — |
| `string` | `string` | — |
| `bool` / `boolean` | `bool` | — |
| `array` / `json` | `array` | `json_encode()` |
| `datetime` | `DateTimeImmutable` | `Y-m-d H:i:s` |
| `date` | `DateTimeImmutable` (midnight) | `Y-m-d H:i:s` |
| `timestamp` | `DateTimeImmutable` | Unix int |

`null` values are passed through without casting.

### Local scopes

Define `scope{Name}` methods to bundle reusable filters:

```php
class Post extends Model
{
    public function scopePublished(QueryBuilder $q): void
    {
        $q->where('published', 1)->whereNotNull('published_at');
    }
}

Post::published()->where('author_id', 7)->get();    // calls scopePublished, then chains
```

### Relationships

Use the helpers from inside model methods you define yourself:

```php
class User extends Model
{
    public function posts(): array              { return $this->hasMany(Post::class); }
    public function profile(): ?Profile         { return $this->hasOne(Profile::class); }
}

class Post extends Model
{
    public function user(): ?User               { return $this->belongsTo(User::class); }
}

$user = User::find(1);
foreach ($user->posts() as $post) { … }
```

**Many-to-many** via a pivot table:

```php
class User extends Model
{
    // pivot table: role_user (alphabetical order, auto-derived)
    // columns:     user_id, role_id
    public function roles(): array { return $this->belongsToMany(Role::class); }
}

class Role extends Model
{
    public function users(): array { return $this->belongsToMany(User::class); }
}

$user  = User::find(1);
$roles = $user->roles();   // Role[]
```

Custom pivot table or foreign key names:

```php
// belongsToMany(related, pivotTable, thisFk, relatedFk)
$this->belongsToMany(Role::class, 'user_roles', 'uid', 'rid');
```

The pivot table name defaults to the two snake_case model names in **alphabetical order**: `User ↔ Role` → `role_user`, `Post ↔ Tag` → `post_tag`.

The helpers run a **separate query each call** — fine for the simple case, but watch out for N+1 in loops. For the N+1 pattern, drop down to a raw join:

```php
$rows = $db->table('posts')
    ->join('users', 'posts.user_id', '=', 'users.id')
    ->select('posts.*', 'users.email as author_email')
    ->get();
```

### Model lifecycle events

Hook into create / update / delete via the event dispatcher:

```php
use Lift\Database\Events\ModelCreating;

Model::setEventDispatcher($app->events());

$app->events()->listen(ModelCreating::class, function (ModelCreating $e) {
    if ($e->model instanceof User && empty($e->model->get('uuid'))) {
        $e->model->set('uuid', Uuid::v7());
    }
});
```

`Model{Creating, Created, Updating, Updated, Deleting, Deleted}` events. The `*ing` ones are *stoppable* — call `$e->stopPropagation()` to cancel.

### Soft deletes

Opt-in trait. Sets `deleted_at` instead of deleting; auto-scopes queries to exclude soft-deleted:

```php
use Lift\Database\Model;
use Lift\Database\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    protected static string $table = 'posts';
}

$post = Post::find(1);
$post->delete();                // sets deleted_at; row stays
Post::find(1);                  // null — soft-deleted excluded

Post::withTrashed()->get();     // include soft-deleted
Post::onlyTrashed()->get();     // only soft-deleted

$post->restore();               // clear deleted_at
$post->forceDelete();           // permanently DELETE FROM …
$post->trashed();               // bool
```

Don't forget to add the column in your migration:

```php
$table->softDeletes();   // adds nullable `deleted_at` timestamp
```

## 13. JSON output

Both `Model` and `Paginator` implement `JsonSerializable`. Return them from a handler and Lift wraps them in JSON automatically:

```php
$app->get('/users/{id}', fn($req) => User::find((int) $req->param('id')));
// → 200 JSON or 204 if the model is null
```

To shape the output (hide passwords, rename fields), wrap in a [JsonResource](json-resources).

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `Database connection failed: SQLSTATE[…]` at boot | Bad DSN / DB down | Print `getMessage()`, check creds, network. |
| `Invalid WHERE operator: [contains]` | Used a non-SQL operator | Stick to the operator list; use `LIKE` for substring. |
| Update affects 0 rows but I expected 1 | Your `where()` didn't match | Re-check identifiers; cast types (`(int)$id`). |
| Bulk insert silently does nothing | Empty array | `insertMany([])` is a no-op; the framework doesn't error. |
| Massive `UPDATE` ran without `WHERE` | You forgot `->where(...)` | Always chain `where` first; in code review require it. |
| `N+1` queries (one per loop iteration) | `Model::hasMany()` inside a loop | Use a single JOIN, or pre-fetch IDs and group manually. |
| Migration order is random | File system order isn't guaranteed | Lift sorts files by name — always prefix with timestamp `YYYY_MM_DD_HHMMSS_`. |
| `lastInsertId()` returns `0` | PostgreSQL + no sequence | Use `RETURNING id` via `selectOne` or set a sequence. |

## Cheat sheet

```php
// Connect
$db = Connection::fromConfig([...]);

// Read
$rows = $db->table('users')->where('active', 1)->orderBy('id')->get();
$one  = $db->table('users')->where('id', 42)->first();

// Write
$id = $db->table('users')->insert([...]);
$db->table('users')->where('id', $id)->update([...]);
$db->table('users')->where('id', $id)->delete();

// Transaction
$db->transaction(fn($db) => /* … */);

// Paginate
$page = $db->table('users')->paginate(1, 20, '/users');

// Stream one row at a time (constant memory)
foreach ($db->table('events')->cursor() as $row) { … }

// Raw
$rows = $db->select('SELECT … WHERE x = ?', [$v]);

// Schema
(new Schema($db))->create('t', fn($t) => $t->id());

// Migrate
(new Migrator($db, __DIR__ . '/db/migrations'))->migrate();

// Model
class User extends Model {
    protected static string $table = 'users';
    protected array $fillable      = ['name', 'email'];
    protected array $casts         = ['active' => 'bool', 'meta' => 'json', 'created_at' => 'datetime'];
}
User::setConnection($db);
$user = User::find(1);
$user->roles();   // belongsToMany(Role::class) — via role_user pivot
```

[Validation →](validation)
