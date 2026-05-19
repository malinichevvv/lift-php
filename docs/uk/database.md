---
layout: page
title: База даних
nav_order: 18
---

# База даних

Lift постачає невеликий, але справжній шар бази даних поверх PDO: плавний конструктор запитів, схему/міграції, опційну модель active-record, м’яке видалення, пагінацію та підтримку кількох з’єднань. **MySQL, PostgreSQL і SQLite** підтримуються «з коробки».

> Ментальна модель: усе починається з `Connection` (одне на базу даних). `$db->table('users')` дає вам плавний `QueryBuilder`. `Schema` виконує DDL через те саме з’єднання. `Model` — це тонка об’єктна обгортка навколо конструктора — ви можете повністю її ігнорувати, якщо віддаєте перевагу стилю конструктора запитів.

## 1. Підключення

Найчистіший спосіб: побудувати `Connection` один раз і покласти його в контейнер.

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

SQLite (чудово для прототипів і тестів):

```php
Connection::fromConfig([
    'driver'   => 'sqlite',
    'database' => __DIR__ . '/../database.sqlite',   // або ':memory:'
]);
```

Потім впроваджуйте будь-де:

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

Або побудуйте одне напряму, коли DI поки не потрібен:

```php
$db = new Connection('sqlite::memory:');
```

Режим помилок PDO встановлено в `ERRMODE_EXCEPTION`, а емульовані prepare вимкнено — збої викидають `PDOException` / `RuntimeException` зі справжнім повідомленням драйвера.

### Кілька з’єднань

`DatabaseManager` тримає іменовані з’єднання лінивими:

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

$users    = $db->table('users')->get();              // за замовчуванням = main
$events   = $db->table('events', 'analytics')->count();
```

Лише перший виклик `table('…', 'analytics')` відкриває другий PDO.

## 2. Конструктор запитів — читання

Почніть запит із `$db->table('foo')`. Кожен метод повертає `$this`, тож зчіплюйте їх.

```php
$users = $db->table('users')
    ->select('id', 'name', 'email')
    ->where('active', 1)
    ->where('age', '>=', 18)
    ->orderBy('name')
    ->limit(20)
    ->get();              // [['id' => 1, …], …]
```

### Вибірка

```php
->select('id', 'name')
->addSelect('email')         // додати ще стовпці
->distinct()
```

За замовчуванням `SELECT *`.

### Where-клаузи

```php
->where('status', 'active')                 // status = 'active'  (2-аргументна форма)
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

`where('column', null)` — це скорочення для `whereNull('column')`. Підтримувані оператори — `=`, `<`, `>`, `<=`, `>=`, `<>`, `!=`, `LIKE`, `NOT LIKE`, `ILIKE` — некоректні викидають `InvalidArgumentException` (що запобігає SQL-ін’єкції через аргумент оператора).

> **Ніколи** не інтерполюйте користувацький ввід в імена стовпців/таблиць. Значення автоматично стають прив’язаними параметрами; ідентифікатори проходять через `Grammar::wrap()`. Звичайні імена (`users`, `u.name`) екрануються; усе інше трактується як сирий вираз, щоб `COUNT(*)` і псевдоніми продовжували працювати.
>
> **Починаючи з 1.2.1:** `Grammar::wrap()` відхиляє сирий вираз, що містить роздільник операторів (`;`), SQL-коментар (`--`, `/* */`), NUL-байт або переведення рядка, з `InvalidArgumentException`. Це ловить поширену помилку передавання користувацького вводу як імені стовпця чи `orderBy()` — але це страхувальна сітка, а не заміна валідації ідентифікаторів за вашим власним списком дозволених.

### JOIN'и

```php
$db->table('orders')
    ->select('orders.id', 'orders.total', 'users.email')
    ->join     ('users', 'orders.user_id', '=', 'users.id')
    ->leftJoin ('addresses', 'orders.address_id', '=', 'addresses.id')
    ->rightJoin('payments',  'orders.id',         '=', 'payments.order_id')
    ->where('orders.status', 'paid')
    ->get();
```

### Групування / сортування / посторінковий вивід

```php
->groupBy('status', 'country')
->having('count', '>', 5)

->orderBy('created_at', 'DESC')
->orderByDesc('id')
->latest('created_at')   // ORDER BY created_at DESC
->oldest('created_at')   // ORDER BY created_at ASC

->limit(20)
->offset(40)
->take(20)               // псевдонім для limit
->skip(40)               // псевдонім для offset
```

### Отримання

```php
->get();              // масив рядків
->first();            // перший рядок або null
->value('email');     // одиночний скаляр із першого рядка
->pluck('email');     // масив одного стовпця з усіх збіглих рядків
->exists();           // bool
->doesntExist();      // bool

->count();            // int
->count('email');     // підрахунок не-null email
->sum('amount');
->avg('rating');
->min('price');
->max('price');
```

### Подивитися SQL, не виконуючи його

```php
$sql      = $db->table('users')->where('active', 1)->toSql();    // рядок
$bindings = $db->table('users')->where('active', 1)->getBindings(); // [1]
```

Чудово для налагодження й написання тестів, які не звертаються до БД.

## 3. Конструктор запитів — запис

```php
// INSERT — повертає останній вставлений ID (string|false)
$id = $db->table('users')->insert([
    'name'  => 'Alice',
    'email' => 'a@b.c',
]);

// Пакетний INSERT — один round-trip, без поверненого значення
$db->table('logs')->insertMany([
    ['level' => 'info',  'msg' => 'one'],
    ['level' => 'error', 'msg' => 'two'],
]);

// UPDATE — повертає кількість зачеплених рядків
$db->table('users')
    ->where('id', 42)
    ->update(['name' => 'Bobby', 'updated_at' => date('Y-m-d H:i:s')]);

// DELETE — повертає кількість зачеплених рядків
$db->table('sessions')->where('expires_at', '<', date('Y-m-d H:i:s'))->delete();
```

Виклик `update()` / `delete()` **без** будь-якого `where()` зачепить кожен рядок. Завжди перевіряйте.

## 4. Пагінація

```php
$page = $db->table('posts')
    ->where('published', 1)
    ->orderBy('created_at', 'DESC')
    ->paginate(page: 2, perPage: 15, path: '/posts');

return Response::json($page);
```

Повертає `Paginator`, що реалізує `JsonSerializable`, тож передавання його в `Response::json()` виробляє:

```json
{
  "data":         [ /* 15 рядків */ ],
  "total":        324,
  "per_page":     15,
  "current_page": 2,
  "last_page":    22,
  "from":         16,
  "to":           30
}
```

Інші методи:

```php
$page->items();          // сирий масив рядків
$page->total();
$page->currentPage();
$page->lastPage();
$page->hasMorePages();
$page->onFirstPage();
$page->links();          // проста HTML-панель пагінації з «Prev / 1 2 … / Next»
```

`$page->links()` навмисно мінімальний — рендеріть власний HTML, якщо хочете вишуканіший контрол.

## 5. Чанкінг — великі набори результатів

Коли ви не можете завантажити все в ОЗП:

```php
$db->table('users')
    ->orderBy('id')
    ->chunk(500, function (array $rows, int $page) use ($mailer) {
        foreach ($rows as $row) {
            $mailer->send($row['email'], 'Newsletter');
        }
        // поверніть false, щоб зупинитися раніше
    });
```

Lift завантажує по 500 рядків за раз і викликає ваш колбек. Тримайте `orderBy('id')` (або інший стабільний стовпець) — інакше ви пропускатимете / переоброблятимете рядки, коли БД переупорядкує результати.

### cursor() — потокова ітерація

`cursor()` повертає генератор, який вибирає **по одному рядку за раз** з бази даних. На відміну від `chunk()`, колбека немає — використовуйте звичайний `foreach`. Пам’ять залишається майже постійною незалежно від розміру таблиці.

```php
foreach ($db->table('events')->where('processed', 0)->orderBy('id')->cursor() as $row) {
    processEvent($row);
}
```

Коли віддати перевагу `cursor()` над `chunk()`:

| | `chunk()` | `cursor()` |
|---|---|---|
| API | на основі колбека | генератор `foreach` |
| Пам’ять на крок | N рядків (розмір чанка) | 1 рядок |
| Ранній вихід | `return false` у колбеку | `break` у `foreach` |
| Підходить, коли | потрібні операції на рівні чанка | построковий стримінг |

## 6. Транзакції

Форма із замиканням рекомендована — вона фіксує за успіху й відкочує за будь-якого винятку:

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

Ручна форма, коли потрібен тонший контроль:

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

## 7. Песимістичне блокування

Коли два процеси можуть змагатися за ті самі рядки (черга, лічильник, …):

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

| Метод                        | SQL                                                |
|------------------------------|----------------------------------------------------|
| `forUpdate()`                | `... FOR UPDATE`                                   |
| `forUpdate(skipLocked: true)`| `... FOR UPDATE SKIP LOCKED`                       |
| `sharedLock()`               | `... FOR SHARE` (PG) / `LOCK IN SHARE MODE` (MySQL)|

> На SQLite клауза блокування мовчки опускається — SQLite усе одно блокує всю БД під час транзакції запису.

## 8. Advisory (іменовані) блокування

Для «лише один процес має виконувати це за раз» без блокувань таблиць:

```php
// Блокувати до отримання або таймауту в секундах
$db->withAdvisoryLock('daily-report', function (Connection $db) {
    generateReport($db);
}, timeout: 30);

// Вручну
if ($db->advisoryLock('export', timeout: 10)) {
    try {
        // …
    } finally {
        $db->advisoryUnlock('export');
    }
}
```

Підтримується на MySQL і PostgreSQL. SQLite викидає `RuntimeException`.

## 9. Сирі запити

Коли конструктор не може виразити те, що вам потрібно:

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

Завжди передавайте користувацький ввід як прив’язані параметри (плейсхолдери `?` + масив `$bindings`). **Ніколи** `"WHERE id = " . $_GET['id']`.

## 10. Слухач запитів (налагодження / метрики)

Підпишіться на кожен виконаний запит:

```php
$db->onQuery(function (string $sql, array $bindings, float $ms): void {
    error_log(sprintf('[%.1f ms] %s | %s', $ms, $sql, json_encode($bindings)));
});
```

Панель налагодження використовує це внутрішньо. Сповіщення про повільні запити — два рядки зверху.

## 11. Схема та міграції

Дві частини:
- `Schema` — помічник DDL (CREATE / ALTER / DROP).
- `Migrator` — версіонований раннер змін.

### Схема (без міграцій)

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

### Типи стовпців

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
$table->foreignId($n);  // беззнакове big int, придатне для FK
```

Модифікатори на стовпець:

```php
->nullable()
->default($value)
->index()
->unique()
->primary()
->after('email')                 // лише MySQL
->foreign('users', 'id')         // FK → users.id  (налаштовує обмеження)
->onDelete('cascade')->onUpdate('cascade')
```

### Міграції

Кожна міграція — це один PHP-файл, що повертає екземпляр `Migration`:

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

Ім’я файлу (без `.php`) стає іменем міграції і тим, що зберігається в таблиці `migrations`.

Запустіть їх:

```php
use Lift\Database\Migrator;

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');

$migrator->migrate();     // виконати всі очікувані — повертає масив імен
$migrator->rollback();    // відкотити останній пакет
$migrator->rollback(3);   // відкотити останні 3 пакети
$migrator->reset();       // відкотити все
$migrator->fresh();       // reset + migrate (почати заново)
$migrator->status();      // [['migration'=>'...','ran'=>bool,'batch'=>int|null], …]
```

Через CLI (`vendor/bin/lift`):

```bash
lift make:migration create_posts_table
lift migrate
lift migrate:rollback
lift migrate:status
lift migrate:fresh
```

`Migrator` створює таблицю `migrations` під час першого запуску — окремого кроку налаштування немає.

> Найкраща практика: тримайте міграції **лише доповнюваними** в main. Ніколи не редагуйте міграцію, яку вже задеплоєно; пишіть нову.

## 12. Модель (active record)

Опційна тонка обгортка. Повністю пропустіть цей розділ, якщо віддаєте перевагу стилю конструктора запитів.

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

// Запит
$users  = User::query()->where('active', 1)->get();   // сирі рядки
$active = User::query()->where('active', 1)->first();

// Пакетно
foreach ($users as $row) {
    $user = User::hydrate($row);     // обгорнути рядок у Model без повторного запиту
}

// Відстеження змін
$user->set('name', 'X');
$user->dirty();                       // ['name' => 'X']
$user->save();                        // оновлює лише `name`
```

### Безпека масового присвоєння

Або список дозволених (`$fillable`), або список заборонених (`$guarded`) — ніколи обидва:

```php
protected array $fillable = ['name', 'email'];   // ЛИШЕ ці доступні для масового присвоєння

// АБО (взаємовиключно):
protected array $guarded = ['id', 'is_admin'];   // усе інше ДОСТУПНЕ для масового присвоєння
```

Виклик `new User($request->json())` копіює лише дозволені ключі; решта мовчки відкидаються.

### Касти атрибутів

Оголосіть `$casts`, щоб автоматично перетворювати сирі значення бази даних на типізовані значення PHP.

```php
final class Post extends Model
{
    protected static string $table = 'posts';
    protected array $fillable      = ['title', 'body', 'meta', 'published', 'published_at'];

    protected array $casts = [
        'published'    => 'bool',
        'view_count'   => 'int',
        'score'        => 'float',
        'meta'         => 'json',         // масив ↔ рядок JSON
        'published_at' => 'datetime',     // рядок ↔ DateTimeImmutable
        'expires_on'   => 'date',         // лише частина з датою
        'expires_ts'   => 'timestamp',    // Unix int ↔ DateTimeImmutable
    ];
}
```

Кастинг відбувається **прозоро**:

```php
$post = Post::find(1);

$post->get('published');    // bool — не "1" / "0"
$post->get('meta');         // ['key' => 'val'] — не '{"key":"val"}'
$post->get('published_at'); // DateTimeImmutable — не '2026-05-15 10:00:00'

// Запис — серіалізує назад автоматично
$post->set('meta', ['theme' => 'dark']);   // зберігається як '{"theme":"dark"}'
$post->set('published_at', new \DateTimeImmutable('now'));  // зберігається як '2026-05-15 …'
$post->save();

// toArray() і вивід JSON також відображають касти
Response::json($post);   // 'meta' — об’єкт, 'published' — true/false
```

Підтримувані типи кастів:

| Тип | Тип читання PHP | Серіалізація запису |
|---|---|---|
| `int` / `integer` | `int` | — |
| `float` / `double` | `float` | — |
| `string` | `string` | — |
| `bool` / `boolean` | `bool` | — |
| `array` / `json` | `array` | `json_encode()` |
| `datetime` | `DateTimeImmutable` | `Y-m-d H:i:s` |
| `date` | `DateTimeImmutable` (північ) | `Y-m-d H:i:s` |
| `timestamp` | `DateTimeImmutable` | Unix int |

Значення `null` пропускаються без кастингу.

### Локальні області (scopes)

Визначте методи `scope{Name}`, щоб об’єднати повторно використовувані фільтри:

```php
class Post extends Model
{
    public function scopePublished(QueryBuilder $q): void
    {
        $q->where('published', 1)->whereNotNull('published_at');
    }
}

Post::published()->where('author_id', 7)->get();    // викликає scopePublished, потім зчіплює
```

### Відношення

Використовуйте помічники зсередини методів моделі, які ви визначаєте самі:

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

**Багато-до-багатьох** через зведену таблицю:

```php
class User extends Model
{
    // зведена таблиця: role_user (алфавітний порядок, автовиведено)
    // стовпці:         user_id, role_id
    public function roles(): array { return $this->belongsToMany(Role::class); }
}

class Role extends Model
{
    public function users(): array { return $this->belongsToMany(User::class); }
}

$user  = User::find(1);
$roles = $user->roles();   // Role[]
```

Власні імена зведеної таблиці або зовнішніх ключів:

```php
// belongsToMany(related, pivotTable, thisFk, relatedFk)
$this->belongsToMany(Role::class, 'user_roles', 'uid', 'rid');
```

Ім’я зведеної таблиці за замовчуванням — це два snake_case-імені моделі в **алфавітному порядку**: `User ↔ Role` → `role_user`, `Post ↔ Tag` → `post_tag`.

Помічники виконують **окремий запит на кожен виклик** — нормально для простого випадку, але стережіться N+1 у циклах. Для патерну N+1 опустіться до сирого join:

```php
$rows = $db->table('posts')
    ->join('users', 'posts.user_id', '=', 'users.id')
    ->select('posts.*', 'users.email as author_email')
    ->get();
```

### Події життєвого циклу моделі

Під’єднуйтеся до створення / оновлення / видалення через диспетчер подій:

```php
use Lift\Database\Events\ModelCreating;

Model::setEventDispatcher($app->events());

$app->events()->listen(ModelCreating::class, function (ModelCreating $e) {
    if ($e->model instanceof User && empty($e->model->get('uuid'))) {
        $e->model->set('uuid', Uuid::v7());
    }
});
```

Події `Model{Creating, Created, Updating, Updated, Deleting, Deleted}`. `*ing`-події *перервні* — викличте `$e->stopPropagation()`, щоб скасувати.

### М’яке видалення

Опційний трейт. Встановлює `deleted_at` замість видалення; автоматично обмежує запити для виключення м’яко видалених:

```php
use Lift\Database\Model;
use Lift\Database\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    protected static string $table = 'posts';
}

$post = Post::find(1);
$post->delete();                // встановлює deleted_at; рядок залишається
Post::find(1);                  // null — м’яко видалене виключено

Post::withTrashed()->get();     // включити м’яко видалені
Post::onlyTrashed()->get();     // лише м’яко видалені

$post->restore();               // очистити deleted_at
$post->forceDelete();           // остаточно DELETE FROM …
$post->trashed();               // bool
```

Не забудьте додати стовпець у вашу міграцію:

```php
$table->softDeletes();   // додає nullable-мітку часу `deleted_at`
```

## 13. Вивід JSON

І `Model`, і `Paginator` реалізують `JsonSerializable`. Поверніть їх з обробника, і Lift автоматично загорне їх у JSON:

```php
$app->get('/users/{id}', fn($req) => User::find((int) $req->param('id')));
// → 200 JSON або 204, якщо модель null
```

Щоб сформувати вивід (приховати паролі, перейменувати поля), загорніть у [JsonResource](json-resources).

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `Database connection failed: SQLSTATE[…]` під час завантаження | Невірний DSN / БД недоступна | Роздрукуйте `getMessage()`, перевірте облікові дані, мережу. |
| `Invalid WHERE operator: [contains]` | Використано не-SQL оператор | Дотримуйтеся переліку операторів; використовуйте `LIKE` для підрядка. |
| Update зачіпає 0 рядків, хоча я очікував 1 | Ваш `where()` не збігся | Перевірте ідентифікатори; приведіть типи (`(int)$id`). |
| Пакетна вставка мовчки нічого не робить | Порожній масив | `insertMany([])` — це no-op; фреймворк не видає помилку. |
| Величезний `UPDATE` виконався без `WHERE` | Ви забули `->where(...)` | Завжди спершу зчіплюйте `where`; у code review вимагайте його. |
| `N+1` запити (один на ітерацію циклу) | `Model::hasMany()` усередині циклу | Використовуйте один JOIN або передзавантажте ідентифікатори й згрупуйте вручну. |
| Порядок міграцій випадковий | Порядок файлової системи не гарантований | Lift сортує файли за іменем — завжди префіксуйте міткою часу `YYYY_MM_DD_HHMMSS_`. |
| `lastInsertId()` повертає `0` | PostgreSQL + немає послідовності | Використовуйте `RETURNING id` через `selectOne` або задайте послідовність. |

## Шпаргалка

```php
// Підключення
$db = Connection::fromConfig([...]);

// Читання
$rows = $db->table('users')->where('active', 1)->orderBy('id')->get();
$one  = $db->table('users')->where('id', 42)->first();

// Запис
$id = $db->table('users')->insert([...]);
$db->table('users')->where('id', $id)->update([...]);
$db->table('users')->where('id', $id)->delete();

// Транзакція
$db->transaction(fn($db) => /* … */);

// Пагінація
$page = $db->table('users')->paginate(1, 20, '/users');

// Стримінг по одному рядку за раз (постійна пам’ять)
foreach ($db->table('events')->cursor() as $row) { … }

// Сирий
$rows = $db->select('SELECT … WHERE x = ?', [$v]);

// Схема
(new Schema($db))->create('t', fn($t) => $t->id());

// Міграція
(new Migrator($db, __DIR__ . '/db/migrations'))->migrate();

// Модель
class User extends Model {
    protected static string $table = 'users';
    protected array $fillable      = ['name', 'email'];
    protected array $casts         = ['active' => 'bool', 'meta' => 'json', 'created_at' => 'datetime'];
}
User::setConnection($db);
$user = User::find(1);
$user->roles();   // belongsToMany(Role::class) — через зведену таблицю role_user
```

[Валідація →](validation)
