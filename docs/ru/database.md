---
layout: page
title: База данных
nav_order: 18
---

# База данных

Lift поставляет небольшой, но настоящий слой базы данных поверх PDO: текучий построитель запросов, схему/миграции, опциональную модель active-record, мягкое удаление, пагинацию и поддержку нескольких соединений. **MySQL, PostgreSQL и SQLite** поддерживаются «из коробки».

> Ментальная модель: всё начинается с `Connection` (одно на базу данных). `$db->table('users')` даёт вам текучий `QueryBuilder`. `Schema` выполняет DDL через то же соединение. `Model` — это тонкая объектная обёртка вокруг построителя — вы можете полностью её игнорировать, если предпочитаете стиль построителя запросов.

## 1. Подключение

Самый чистый способ: построить `Connection` один раз и положить его в контейнер.

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

SQLite (отлично для прототипов и тестов):

```php
Connection::fromConfig([
    'driver'   => 'sqlite',
    'database' => __DIR__ . '/../database.sqlite',   // или ':memory:'
]);
```

Затем внедряйте где угодно:

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

Или постройте одно напрямую, когда DI пока не нужен:

```php
$db = new Connection('sqlite::memory:');
```

Режим ошибок PDO установлен в `ERRMODE_EXCEPTION`, а эмулируемые prepare выключены — сбои выбрасывают `PDOException` / `RuntimeException` с настоящим сообщением драйвера.

### Несколько соединений

`DatabaseManager` держит именованные соединения ленивыми:

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

$users    = $db->table('users')->get();              // по умолчанию = main
$events   = $db->table('events', 'analytics')->count();
```

Только первый вызов `table('…', 'analytics')` открывает второй PDO.

## 2. Построитель запросов — чтение

Начните запрос с `$db->table('foo')`. Каждый метод возвращает `$this`, так что сцепляйте их.

```php
$users = $db->table('users')
    ->select('id', 'name', 'email')
    ->where('active', 1)
    ->where('age', '>=', 18)
    ->orderBy('name')
    ->limit(20)
    ->get();              // [['id' => 1, …], …]
```

### Выборка

```php
->select('id', 'name')
->addSelect('email')         // добавить ещё столбцы
->distinct()
```

По умолчанию `SELECT *`.

### Where-клаузы

```php
->where('status', 'active')                 // status = 'active'  (2-аргументная форма)
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

`where('column', null)` — это сокращение для `whereNull('column')`. Поддерживаемые операторы — `=`, `<`, `>`, `<=`, `>=`, `<>`, `!=`, `LIKE`, `NOT LIKE`, `ILIKE` — некорректные выбрасывают `InvalidArgumentException` (что предотвращает SQL-инъекцию через аргумент оператора).

> **Никогда** не интерполируйте пользовательский ввод в имена столбцов/таблиц. Значения автоматически становятся привязанными параметрами; идентификаторы проходят через `Grammar::wrap()`. Обычные имена (`users`, `u.name`) экранируются; всё остальное трактуется как сырое выражение, чтобы `COUNT(*)` и псевдонимы продолжали работать.
>
> **Начиная с 1.2.1:** `Grammar::wrap()` отклоняет сырое выражение, содержащее разделитель операторов (`;`), SQL-комментарий (`--`, `/* */`), NUL-байт или перевод строки, с `InvalidArgumentException`. Это ловит распространённую ошибку передачи пользовательского ввода как имени столбца или `orderBy()` — но это страховочная сетка, а не замена валидации идентификаторов по вашему собственному списку разрешённых.

### JOIN'ы

```php
$db->table('orders')
    ->select('orders.id', 'orders.total', 'users.email')
    ->join     ('users', 'orders.user_id', '=', 'users.id')
    ->leftJoin ('addresses', 'orders.address_id', '=', 'addresses.id')
    ->rightJoin('payments',  'orders.id',         '=', 'payments.order_id')
    ->where('orders.status', 'paid')
    ->get();
```

### Группировка / сортировка / постраничный вывод

```php
->groupBy('status', 'country')
->having('count', '>', 5)

->orderBy('created_at', 'DESC')
->orderByDesc('id')
->latest('created_at')   // ORDER BY created_at DESC
->oldest('created_at')   // ORDER BY created_at ASC

->limit(20)
->offset(40)
->take(20)               // псевдоним для limit
->skip(40)               // псевдоним для offset
```

### Получение

```php
->get();              // массив строк
->first();            // первая строка или null
->value('email');     // одиночный скаляр из первой строки
->pluck('email');     // массив одного столбца из всех совпавших строк
->exists();           // bool
->doesntExist();      // bool

->count();            // int
->count('email');     // подсчёт не-null email
->sum('amount');
->avg('rating');
->min('price');
->max('price');
```

### Посмотреть SQL, не выполняя его

```php
$sql      = $db->table('users')->where('active', 1)->toSql();    // строка
$bindings = $db->table('users')->where('active', 1)->getBindings(); // [1]
```

Отлично для отладки и написания тестов, которые не обращаются к БД.

## 3. Построитель запросов — запись

```php
// INSERT — возвращает последний вставленный ID (string|false)
$id = $db->table('users')->insert([
    'name'  => 'Alice',
    'email' => 'a@b.c',
]);

// Пакетный INSERT — один round-trip, без возвращаемого значения
$db->table('logs')->insertMany([
    ['level' => 'info',  'msg' => 'one'],
    ['level' => 'error', 'msg' => 'two'],
]);

// UPDATE — возвращает количество затронутых строк
$db->table('users')
    ->where('id', 42)
    ->update(['name' => 'Bobby', 'updated_at' => date('Y-m-d H:i:s')]);

// DELETE — возвращает количество затронутых строк
$db->table('sessions')->where('expires_at', '<', date('Y-m-d H:i:s'))->delete();
```

Вызов `update()` / `delete()` **без** какого-либо `where()` затронет каждую строку. Всегда перепроверяйте.

## 4. Пагинация

```php
$page = $db->table('posts')
    ->where('published', 1)
    ->orderBy('created_at', 'DESC')
    ->paginate(page: 2, perPage: 15, path: '/posts');

return Response::json($page);
```

Возвращает `Paginator`, реализующий `JsonSerializable`, так что передача его в `Response::json()` производит:

```json
{
  "data":         [ /* 15 строк */ ],
  "total":        324,
  "per_page":     15,
  "current_page": 2,
  "last_page":    22,
  "from":         16,
  "to":           30
}
```

Другие методы:

```php
$page->items();          // сырой массив строк
$page->total();
$page->currentPage();
$page->lastPage();
$page->hasMorePages();
$page->onFirstPage();
$page->links();          // простая HTML-панель пагинации с «Prev / 1 2 … / Next»
```

`$page->links()` намеренно минимален — рендерьте собственный HTML, если хотите более изящный контрол.

## 5. Чанкинг — большие наборы результатов

Когда вы не можете загрузить всё в ОЗУ:

```php
$db->table('users')
    ->orderBy('id')
    ->chunk(500, function (array $rows, int $page) use ($mailer) {
        foreach ($rows as $row) {
            $mailer->send($row['email'], 'Newsletter');
        }
        // верните false, чтобы остановиться раньше
    });
```

Lift загружает по 500 строк за раз и вызывает ваш колбэк. Держите `orderBy('id')` (или другой стабильный столбец) — иначе вы будете пропускать / переобрабатывать строки, когда БД переупорядочит результаты.

### cursor() — потоковая итерация

`cursor()` возвращает генератор, который выбирает **по одной строке за раз** из базы данных. В отличие от `chunk()`, колбэка нет — используйте обычный `foreach`. Память остаётся почти постоянной независимо от размера таблицы.

```php
foreach ($db->table('events')->where('processed', 0)->orderBy('id')->cursor() as $row) {
    processEvent($row);
}
```

Когда предпочесть `cursor()` вместо `chunk()`:

| | `chunk()` | `cursor()` |
|---|---|---|
| API | на основе колбэка | генератор `foreach` |
| Память на шаг | N строк (размер чанка) | 1 строка |
| Ранний выход | `return false` в колбэке | `break` в `foreach` |
| Подходит, когда | нужны операции на уровне чанка | построчный стриминг |

## 6. Транзакции

Форма с замыканием рекомендуется — она фиксирует при успехе и откатывает при любом исключении:

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

Ручная форма, когда нужен более тонкий контроль:

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

## 7. Пессимистичная блокировка

Когда два процесса могут гоняться за одними строками (очередь, счётчик, …):

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

> На SQLite клауза блокировки молча опускается — SQLite всё равно блокирует всю БД во время транзакции записи.

## 8. Advisory (именованные) блокировки

Для «только один процесс должен выполнять это за раз» без блокировок таблиц:

```php
// Блокировать до получения или таймаута в секундах
$db->withAdvisoryLock('daily-report', function (Connection $db) {
    generateReport($db);
}, timeout: 30);

// Вручную
if ($db->advisoryLock('export', timeout: 10)) {
    try {
        // …
    } finally {
        $db->advisoryUnlock('export');
    }
}
```

Поддерживается на MySQL и PostgreSQL. SQLite выбрасывает `RuntimeException`.

## 9. Сырые запросы

Когда построитель не может выразить то, что вам нужно:

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

Всегда передавайте пользовательский ввод как привязанные параметры (плейсхолдеры `?` + массив `$bindings`). **Никогда** `"WHERE id = " . $_GET['id']`.

## 10. Слушатель запросов (отладка / метрики)

Подпишитесь на каждый выполненный запрос:

```php
$db->onQuery(function (string $sql, array $bindings, float $ms): void {
    error_log(sprintf('[%.1f ms] %s | %s', $ms, $sql, json_encode($bindings)));
});
```

Отладочная панель использует это внутренне. Оповещение о медленных запросах — две строки сверху.

## 11. Схема и миграции

Две части:
- `Schema` — помощник DDL (CREATE / ALTER / DROP).
- `Migrator` — версионируемый раннер изменений.

### Схема (без миграций)

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

### Типы столбцов

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
$table->foreignId($n);  // беззнаковое big int, подходящее для FK
```

Модификаторы на столбец:

```php
->nullable()
->default($value)
->index()
->unique()
->primary()
->after('email')                 // только MySQL
->foreign('users', 'id')         // FK → users.id  (настраивает ограничение)
->onDelete('cascade')->onUpdate('cascade')
```

### Миграции

Каждая миграция — это один PHP-файл, возвращающий экземпляр `Migration`:

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

Имя файла (без `.php`) становится именем миграции и тем, что хранится в таблице `migrations`.

Запустите их:

```php
use Lift\Database\Migrator;

$migrator = new Migrator($db, __DIR__ . '/../database/migrations');

$migrator->migrate();     // выполнить все ожидающие — возвращает массив имён
$migrator->rollback();    // откатить последний пакет
$migrator->rollback(3);   // откатить последние 3 пакета
$migrator->reset();       // откатить всё
$migrator->fresh();       // reset + migrate (начать заново)
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

`Migrator` создаёт таблицу `migrations` при первом запуске — отдельного шага настройки нет.

> Лучшая практика: держите миграции **только добавляемыми** в main. Никогда не редактируйте миграцию, которая уже задеплоена; пишите новую.

## 12. Модель (active record)

Опциональная тонкая обёртка. Полностью пропустите этот раздел, если предпочитаете стиль построителя запросов.

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

// Запрос
$users  = User::query()->where('active', 1)->get();   // сырые строки
$active = User::query()->where('active', 1)->first();

// Пакетно
foreach ($users as $row) {
    $user = User::hydrate($row);     // обернуть строку в Model без повторного запроса
}

// Отслеживание изменений
$user->set('name', 'X');
$user->dirty();                       // ['name' => 'X']
$user->save();                        // обновляет только `name`
```

### Безопасность массового присваивания

Либо список разрешённых (`$fillable`), либо список запрещённых (`$guarded`) — никогда оба:

```php
protected array $fillable = ['name', 'email'];   // ТОЛЬКО эти доступны для массового присваивания

// ИЛИ (взаимоисключающе):
protected array $guarded = ['id', 'is_admin'];   // всё остальное ДОСТУПНО для массового присваивания
```

Вызов `new User($request->json())` копирует только разрешённые ключи; остальные молча отбрасываются.

### Касты атрибутов

Объявите `$casts`, чтобы автоматически преобразовывать сырые значения базы данных в типизированные значения PHP.

```php
final class Post extends Model
{
    protected static string $table = 'posts';
    protected array $fillable      = ['title', 'body', 'meta', 'published', 'published_at'];

    protected array $casts = [
        'published'    => 'bool',
        'view_count'   => 'int',
        'score'        => 'float',
        'meta'         => 'json',         // массив ↔ строка JSON
        'published_at' => 'datetime',     // строка ↔ DateTimeImmutable
        'expires_on'   => 'date',         // только часть с датой
        'expires_ts'   => 'timestamp',    // Unix int ↔ DateTimeImmutable
    ];
}
```

Кастинг происходит **прозрачно**:

```php
$post = Post::find(1);

$post->get('published');    // bool — не "1" / "0"
$post->get('meta');         // ['key' => 'val'] — не '{"key":"val"}'
$post->get('published_at'); // DateTimeImmutable — не '2026-05-15 10:00:00'

// Запись — сериализует обратно автоматически
$post->set('meta', ['theme' => 'dark']);   // хранится как '{"theme":"dark"}'
$post->set('published_at', new \DateTimeImmutable('now'));  // хранится как '2026-05-15 …'
$post->save();

// toArray() и вывод JSON также отражают касты
Response::json($post);   // 'meta' — объект, 'published' — true/false
```

Поддерживаемые типы кастов:

| Тип | Тип чтения PHP | Сериализация записи |
|---|---|---|
| `int` / `integer` | `int` | — |
| `float` / `double` | `float` | — |
| `string` | `string` | — |
| `bool` / `boolean` | `bool` | — |
| `array` / `json` | `array` | `json_encode()` |
| `datetime` | `DateTimeImmutable` | `Y-m-d H:i:s` |
| `date` | `DateTimeImmutable` (полночь) | `Y-m-d H:i:s` |
| `timestamp` | `DateTimeImmutable` | Unix int |

Значения `null` пропускаются без кастинга.

### Локальные области (scopes)

Определите методы `scope{Name}`, чтобы объединить переиспользуемые фильтры:

```php
class Post extends Model
{
    public function scopePublished(QueryBuilder $q): void
    {
        $q->where('published', 1)->whereNotNull('published_at');
    }
}

Post::published()->where('author_id', 7)->get();    // вызывает scopePublished, затем сцепляет
```

### Отношения

Используйте помощники изнутри методов модели, которые вы определяете сами:

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

**Многие-ко-многим** через сводную таблицу:

```php
class User extends Model
{
    // сводная таблица: role_user (алфавитный порядок, автовыведено)
    // столбцы:         user_id, role_id
    public function roles(): array { return $this->belongsToMany(Role::class); }
}

class Role extends Model
{
    public function users(): array { return $this->belongsToMany(User::class); }
}

$user  = User::find(1);
$roles = $user->roles();   // Role[]
```

Собственные имена сводной таблицы или внешних ключей:

```php
// belongsToMany(related, pivotTable, thisFk, relatedFk)
$this->belongsToMany(Role::class, 'user_roles', 'uid', 'rid');
```

Имя сводной таблицы по умолчанию — это два snake_case-имени модели в **алфавитном порядке**: `User ↔ Role` → `role_user`, `Post ↔ Tag` → `post_tag`.

Помощники выполняют **отдельный запрос на каждый вызов** — нормально для простого случая, но остерегайтесь N+1 в циклах. Для паттерна N+1 опуститесь до сырого join:

```php
$rows = $db->table('posts')
    ->join('users', 'posts.user_id', '=', 'users.id')
    ->select('posts.*', 'users.email as author_email')
    ->get();
```

### События жизненного цикла модели

Подключайтесь к созданию / обновлению / удалению через диспетчер событий:

```php
use Lift\Database\Events\ModelCreating;

Model::setEventDispatcher($app->events());

$app->events()->listen(ModelCreating::class, function (ModelCreating $e) {
    if ($e->model instanceof User && empty($e->model->get('uuid'))) {
        $e->model->set('uuid', Uuid::v7());
    }
});
```

События `Model{Creating, Created, Updating, Updated, Deleting, Deleted}`. `*ing`-события *прерываемы* — вызовите `$e->stopPropagation()`, чтобы отменить.

### Мягкое удаление

Опциональный трейт. Устанавливает `deleted_at` вместо удаления; автоматически ограничивает запросы для исключения мягко удалённых:

```php
use Lift\Database\Model;
use Lift\Database\SoftDeletes;

class Post extends Model
{
    use SoftDeletes;

    protected static string $table = 'posts';
}

$post = Post::find(1);
$post->delete();                // устанавливает deleted_at; строка остаётся
Post::find(1);                  // null — мягко удалённое исключено

Post::withTrashed()->get();     // включить мягко удалённые
Post::onlyTrashed()->get();     // только мягко удалённые

$post->restore();               // очистить deleted_at
$post->forceDelete();           // окончательно DELETE FROM …
$post->trashed();               // bool
```

Не забудьте добавить столбец в вашу миграцию:

```php
$table->softDeletes();   // добавляет nullable-метку времени `deleted_at`
```

## 13. Вывод JSON

И `Model`, и `Paginator` реализуют `JsonSerializable`. Верните их из обработчика, и Lift автоматически обернёт их в JSON:

```php
$app->get('/users/{id}', fn($req) => User::find((int) $req->param('id')));
// → 200 JSON или 204, если модель null
```

Чтобы сформировать вывод (скрыть пароли, переименовать поля), оберните в [JsonResource](json-resources).

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `Database connection failed: SQLSTATE[…]` при загрузке | Неверный DSN / БД недоступна | Распечатайте `getMessage()`, проверьте учётные данные, сеть. |
| `Invalid WHERE operator: [contains]` | Использован не-SQL оператор | Придерживайтесь списка операторов; используйте `LIKE` для подстроки. |
| Update затрагивает 0 строк, хотя я ожидал 1 | Ваш `where()` не совпал | Перепроверьте идентификаторы; приведите типы (`(int)$id`). |
| Пакетная вставка молча ничего не делает | Пустой массив | `insertMany([])` — это no-op; фреймворк не выдаёт ошибку. |
| Огромный `UPDATE` выполнился без `WHERE` | Вы забыли `->where(...)` | Всегда сначала сцепляйте `where`; в code review требуйте его. |
| `N+1` запросы (один на итерацию цикла) | `Model::hasMany()` внутри цикла | Используйте один JOIN или предзагрузите идентификаторы и сгруппируйте вручную. |
| Порядок миграций случайный | Порядок файловой системы не гарантирован | Lift сортирует файлы по имени — всегда префиксуйте меткой времени `YYYY_MM_DD_HHMMSS_`. |
| `lastInsertId()` возвращает `0` | PostgreSQL + нет последовательности | Используйте `RETURNING id` через `selectOne` или задайте последовательность. |

## Шпаргалка

```php
// Подключение
$db = Connection::fromConfig([...]);

// Чтение
$rows = $db->table('users')->where('active', 1)->orderBy('id')->get();
$one  = $db->table('users')->where('id', 42)->first();

// Запись
$id = $db->table('users')->insert([...]);
$db->table('users')->where('id', $id)->update([...]);
$db->table('users')->where('id', $id)->delete();

// Транзакция
$db->transaction(fn($db) => /* … */);

// Пагинация
$page = $db->table('users')->paginate(1, 20, '/users');

// Стриминг по одной строке за раз (постоянная память)
foreach ($db->table('events')->cursor() as $row) { … }

// Сырой
$rows = $db->select('SELECT … WHERE x = ?', [$v]);

// Схема
(new Schema($db))->create('t', fn($t) => $t->id());

// Миграция
(new Migrator($db, __DIR__ . '/db/migrations'))->migrate();

// Модель
class User extends Model {
    protected static string $table = 'users';
    protected array $fillable      = ['name', 'email'];
    protected array $casts         = ['active' => 'bool', 'meta' => 'json', 'created_at' => 'datetime'];
}
User::setConnection($db);
$user = User::find(1);
$user->roles();   // belongsToMany(Role::class) — через сводную таблицу role_user
```

[Валидация →](validation)
