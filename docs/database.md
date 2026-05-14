---
layout: page
title: Database
nav_order: 10
---

# Database

Lift includes a PDO-backed database layer: a connection class, a fluent query builder, an active-record model, migrations with a schema builder, and opt-in soft deletes and local query scopes.

---

## Connection

`Connection` wraps PDO and adds a fluent query builder entry point and an optional query listener for debugging.

```php
use Lift\Database\Connection;

// Explicit DSN
$db = new Connection('mysql:host=127.0.0.1;dbname=app;charset=utf8mb4', 'root', 'secret');

// From config array
$db = Connection::fromConfig([
    'driver'   => 'mysql',         // mysql | pgsql | sqlite
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'app',
    'username' => 'root',
    'password' => 'secret',
    'charset'  => 'utf8mb4',
]);

// SQLite in-memory (tests)
$db = new Connection('sqlite::memory:');
```

### Raw queries

```php
// SELECT — returns all rows as associative arrays
$users = $db->select('SELECT * FROM users WHERE active = ?', [1]);

// SELECT — returns first row or null
$user = $db->selectOne('SELECT * FROM users WHERE id = ?', [42]);

// SELECT — returns a single scalar value
$count = $db->value('SELECT COUNT(*) FROM orders WHERE user_id = ?', [42]);

// INSERT / UPDATE / DELETE — returns affected row count
$affected = $db->execute('UPDATE users SET active = 0 WHERE id = ?', [42]);

// Last inserted auto-increment ID
$id = $db->lastInsertId();
```

### Transactions

```php
// Callback form — auto commit / rollback
$result = $db->transaction(function (Connection $db) {
    $orderId = $db->table('orders')->insert([...]);
    $db->table('items')->insert(['order_id' => $orderId, ...]);
    return $orderId;
});

// Manual form
$db->beginTransaction();
try {
    $db->execute('UPDATE accounts SET balance = balance - ? WHERE id = ?', [100, 1]);
    $db->execute('UPDATE accounts SET balance = balance + ? WHERE id = ?', [100, 2]);
    $db->commit();
} catch (\Throwable $e) {
    $db->rollBack();
    throw $e;
}

$db->inTransaction(); // bool
```

### Query listener

Register a callable that receives `($sql, $bindings, $ms)` after every query. Use this to feed the debug toolbar or a slow-query logger.

```php
// Debug toolbar integration
$db->onQuery([$collector, 'recordQuery']);

// Custom slow-query logger
$db->onQuery(function (string $sql, array $bindings, float $ms): void {
    if ($ms > 200) {
        $logger->warning('Slow query', ['sql' => $sql, 'ms' => $ms]);
    }
});
```

---

## Query Builder

Start a fluent query with `$db->table(name)`.

```php
$db->table('users')
   ->select('id', 'name', 'email')
   ->where('active', 1)
   ->orderBy('name')
   ->limit(20)
   ->get();
```

### Selecting columns

```php
->select('id', 'name')         // replace column list
->addSelect('role')            // append to column list
->distinct()                   // add DISTINCT
```

### WHERE clauses

```php
->where('active', 1)                    // column = value
->where('age', '>=', 18)               // column op value
->where('email', null)                  // column IS NULL
->orWhere('role', 'admin')

->whereIn('id', [1, 2, 3])
->whereNotIn('status', ['banned', 'deleted'])

->whereNull('deleted_at')
->whereNotNull('email_verified_at')

->whereBetween('age', 18, 65)

->whereRaw('YEAR(created_at) = ?', [2024])
->orWhere('id', 5)
```

### JOINs

```php
->join('posts', 'users.id', '=', 'posts.user_id')
->leftJoin('profiles', 'users.id', '=', 'profiles.user_id')
->rightJoin('roles', 'users.role_id', '=', 'roles.id')
```

### Grouping and aggregates

```php
->groupBy('department', 'role')
->having('count', '>', 5)

// Aggregate methods (execute immediately)
$count = $db->table('users')->where('active', 1)->count();
$total = $db->table('orders')->sum('amount');
$avg   = $db->table('ratings')->avg('score');
$min   = $db->table('products')->min('price');
$max   = $db->table('products')->max('price');
```

### Ordering and pagination

```php
->orderBy('name')                // ASC
->orderBy('created_at', 'DESC')
->orderByDesc('created_at')
->latest()                      // orderBy('created_at', 'DESC')
->oldest()                      // orderBy('created_at', 'ASC')
->limit(10)
->offset(20)
->skip(20)->take(10)            // aliases for offset/limit
```

### Fetching results

```php
->get()           // array of associative arrays (all rows)
->first()         // first row or null
->value('email')  // single scalar value
->pluck('name')   // flat array of one column
->exists()        // bool
->doesntExist()   // bool
->count()         // int
```

### Pagination

```php
$page      = (int) ($request->getQueryParams()['page'] ?? 1);
$paginator = $db->table('posts')
                ->where('published', 1)
                ->orderByDesc('created_at')
                ->paginate($page, perPage: 15, path: '/posts');

$paginator->items();       // rows for this page
$paginator->currentPage(); // int
$paginator->lastPage();    // int
$paginator->total();       // total row count
$paginator->links();       // HTML pagination links
```

### Chunking large result sets

```php
$db->table('users')->chunk(100, function (array $rows): void {
    foreach ($rows as $row) {
        // process $row
    }
});
```

### Writing data

```php
// INSERT — returns last insert ID (string) or false
$id = $db->table('users')->insert(['name' => 'Alice', 'email' => 'alice@example.com']);

// INSERT many rows at once
$db->table('logs')->insertMany([
    ['message' => 'Started', 'level' => 'info'],
    ['message' => 'Done',    'level' => 'info'],
]);

// UPDATE — returns affected row count
$affected = $db->table('users')->where('id', 42)->update(['active' => 0]);

// DELETE — returns affected row count
$deleted = $db->table('sessions')->where('expires_at', '<', date('Y-m-d H:i:s'))->delete();
```

### Inspecting the generated SQL

```php
$qb = $db->table('users')->where('active', 1)->orderBy('name');
echo $qb->toSql();         // SELECT * FROM `users` WHERE `active` = ? ORDER BY `name` ASC
print_r($qb->getBindings()); // [1]
```

---

## Model

Extend `Model` for a lightweight active-record interface.

```php
use Lift\Database\Model;

final class User extends Model
{
    protected static string $table = 'users';
    protected array $fillable = ['name', 'email', 'role'];
}

// One-time setup at bootstrap
User::setConnection($db);
```

### Querying

```php
// Returns a QueryBuilder scoped to the model's table
$users = User::query()->where('active', 1)->get();

// Find by primary key
$user = User::find(42);          // ?User
$user = User::find('abc-uuid');  // ?User (string PKs work too)

// Static helpers
$users = User::query()->orderBy('name')->get(); // raw rows as arrays
```

### Creating and saving

```php
// Create (insert) in one step
$user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

// Or construct then save
$user = new User(['name' => 'Bob']);
$user->save(); // INSERT

$user->set('email', 'bob@example.com');
$user->save(); // UPDATE (because $user->exists === true)
```

### Reading and writing attributes

```php
$user->get('name');           // attribute read
$user->set('name', 'Charlie'); // attribute write
$user->fill(['name' => 'Dave', 'email' => 'dave@example.com']); // mass-assign

$user->getKey();              // value of the primary key
$user->isDirty();             // bool — attributes changed since load?
$user->getDirty();            // array of changed attributes
$user->getOriginal();         // attributes as loaded from DB
```

### Deleting

```php
$user->delete();  // DELETE WHERE id = ?
```

### Array / JSON access

`Model` implements `ArrayAccess` and `JsonSerializable`:

```php
$user['name'] = 'Alice';
echo $user['email'];
json_encode($user); // {"id":1,"name":"Alice","email":"alice@example.com"}
```

### Table name convention

When `$table` is not declared, the name is derived from the class name: `CamelCase` → `snake_cases` (pluralized).

```php
final class BlogPost extends Model {}
// table: blog_posts
```

### Custom primary key

```php
final class Token extends Model
{
    protected static string $table = 'tokens';
    protected static string $primaryKey = 'token';
}
```

### Model events

```php
use Lift\Database\Events\{ModelCreating, ModelCreated, ModelUpdating, ModelUpdated, ModelDeleting, ModelDeleted};
use Lift\Events\EventDispatcher;

Model::setEventDispatcher($dispatcher);

$dispatcher->listen(ModelCreating::class, function (ModelCreating $event): void {
    // return nothing to allow; call $event->stopPropagation() to cancel
});
$dispatcher->listen(ModelCreated::class, function (ModelCreated $event): void {
    cache()->forget('user_list');
});
```

`*ing` events are stoppable: calling `$event->stopPropagation()` inside a listener cancels the operation.

---

## Soft Deletes

Add the `SoftDeletes` trait to opt into soft-delete behaviour. `delete()` sets `deleted_at` to the current timestamp instead of removing the row. All default queries exclude soft-deleted records automatically.

```php
use Lift\Database\Model;
use Lift\Database\SoftDeletes;

final class Post extends Model
{
    use SoftDeletes;

    protected static string $table = 'posts';
    protected array $fillable = ['title', 'body'];
}
```

### Usage

```php
$post = Post::find(1);
$post->delete();           // sets deleted_at; row stays in DB

Post::find(1);             // null — soft-deleted is excluded
Post::query()->get();      // only non-deleted rows

Post::withTrashed()->get();  // all rows, including soft-deleted
Post::onlyTrashed()->get();  // only soft-deleted rows

$post->restore();          // clears deleted_at; back to normal
$post->trashed();          // bool — currently soft-deleted?

$post->forceDelete();      // permanently removes the row
```

### Custom column name

Override `$deletedAtColumn` if your table uses a different column:

```php
final class Order extends Model
{
    use SoftDeletes;

    protected static string $deletedAtColumn = 'archived_at';
}
```

### Required schema

```php
$schema->alter('posts', function (Blueprint $t): void {
    $t->timestamp('deleted_at')->nullable();
});
```

---

## Local Scopes

Define reusable query constraints as `scope{Name}(QueryBuilder $query): void` methods, then call them as static methods on the model.

```php
use Lift\Database\Model;
use Lift\Database\QueryBuilder;

final class User extends Model
{
    protected static string $table = 'users';

    public function scopeActive(QueryBuilder $query): void
    {
        $query->where('active', 1);
    }

    public function scopeRole(QueryBuilder $query, string $role): void
    {
        $query->where('role', $role);
    }
}
```

```php
// Usage — scopes return a QueryBuilder, so they're fully chainable
User::active()->get();
User::role('admin')->get();
User::active()->role('admin')->orderBy('name')->limit(10)->get();
```

Arguments after `$query` are forwarded from the static call:

```php
// In model
public function scopeCreatedAfter(QueryBuilder $query, string $date): void
{
    $query->where('created_at', '>=', $date);
}

// Usage
User::createdAfter('2024-01-01')->get();
```

---

## Migrations

Migrations version your schema as a sequence of ordered PHP files. Each file returns an anonymous class extending `Migration`.

### Writing a migration

```php
// database/migrations/2024_01_15_000000_create_users_table.php
use Lift\Database\Migration;
use Lift\Database\Schema\Blueprint;
use Lift\Database\Schema\Schema;

return new class($db) extends Migration {
    public function up(): void
    {
        (new Schema($this->db))->create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        (new Schema($this->db))->dropIfExists('users');
    }
};
```

### Running migrations

```php
use Lift\Database\Migrator;

$migrator = new Migrator($db, __DIR__ . '/database/migrations');

$migrator->migrate();                // run all pending migrations
$migrator->rollback();              // roll back the last batch
$migrator->rollback(steps: 3);      // roll back the last 3 batches
$migrator->reset();                 // roll back all batches
$migrator->fresh();                 // reset + migrate
$migrator->status();                // array of all migrations with ran/pending state
```

---

## Schema Builder

`Schema` and `Blueprint` build DDL statements for MySQL, PostgreSQL, and SQLite.

### Creating a table

```php
use Lift\Database\Schema\Blueprint;
use Lift\Database\Schema\Schema;

$schema = new Schema($db);

$schema->create('products', function (Blueprint $t): void {
    $t->id();
    $t->string('name');
    $t->string('sku', 64)->unique();
    $t->decimal('price', precision: 10, scale: 2);
    $t->integer('stock')->default(0);
    $t->boolean('published')->default(false);
    $t->text('description')->nullable();
    $t->timestamps();
});
```

### Altering a table

```php
$schema->alter('products', function (Blueprint $t): void {
    $t->string('currency', 3)->default('USD');
    $t->index('published');
});
```

### Other schema operations

```php
$schema->drop('old_table');
$schema->dropIfExists('temp_table');
$schema->rename('old_name', 'new_name');
$schema->hasTable('users');           // bool
$schema->hasColumn('users', 'email'); // bool
```

### Blueprint column types

| Method | SQL type |
|--------|----------|
| `id()` | `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| `bigIncrements()` | alias for `id()` |
| `string(name, length=255)` | `VARCHAR(n)` |
| `char(name, length=100)` | `CHAR(n)` |
| `text(name)` | `TEXT` |
| `mediumText(name)` | `MEDIUMTEXT` |
| `longText(name)` | `LONGTEXT` |
| `integer(name)` | `INT` |
| `bigInteger(name)` | `BIGINT` |
| `smallInteger(name)` | `SMALLINT` |
| `tinyInteger(name)` | `TINYINT` |
| `decimal(name, precision=8, scale=2)` | `DECIMAL(p,s)` |
| `float(name)` | `FLOAT` |
| `double(name)` | `DOUBLE` |
| `boolean(name)` | `TINYINT(1)` |
| `binary(name)` | `BLOB` |
| `date(name)` | `DATE` |
| `dateTime(name)` | `DATETIME` |
| `timestamp(name)` | `TIMESTAMP` |
| `timestamps()` | adds `created_at` + `updated_at` TIMESTAMP columns |
| `time(name)` | `TIME` |
| `json(name)` | `JSON` |
| `uuid(name)` | `CHAR(36)` |
| `enum(name, values[])` | `ENUM(...)` |
| `foreignId(name)` | `BIGINT UNSIGNED` + index |

### Column modifiers (chainable)

```php
$t->string('email')->unique();
$t->integer('age')->nullable();
$t->boolean('active')->default(true);
$t->string('code', 6)->nullable()->default(null);
```

### Indexes and foreign keys

```php
$t->unique('email');
$t->unique(['first_name', 'last_name'], 'idx_full_name');
$t->index('status');
$t->index(['country', 'city']);

$t->foreignKey('user_id', 'users', 'id', onDelete: 'CASCADE');
```

---

## DatabaseManager

`DatabaseManager` manages named connections and pools them by name.

```php
use Lift\Database\DatabaseManager;

$manager = new DatabaseManager();

$manager->addConnection('default', Connection::fromConfig([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'database' => 'app',
    'username' => 'root',
    'password' => 'secret',
]));

$manager->addConnection('readonly', Connection::fromConfig([
    'driver'   => 'mysql',
    'host'     => 'replica.example.com',
    'database' => 'app',
    'username' => 'readonly',
    'password' => 'secret',
]));

$db   = $manager->connection();           // default
$read = $manager->connection('readonly'); // named
```
