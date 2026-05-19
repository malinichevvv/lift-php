---
layout: page
title: Консоль (CLI)
nav_order: 30
---

# Консоль (CLI)

Lift постачає крихітний, дружній до PSR CLI-фреймворк — `Lift\Console\Application` — і бінарник `vendor/bin/lift`, який іде з генераторами (`make:controller`, `make:model`, …), інструментами бази даних (`migrate`, `migrate:rollback`) і воркером черг.

> Ментальна модель: CLI-застосунок — це просто колекція об’єктів `Command` із ключами за іменем. `Application` парсить argv, знаходить збіглу команду, викликає її метод `execute(Input, Output): int`.

## Використання `vendor/bin/lift`

Після `composer require malinichevvv/lift-php` бінарник доступний у PATH для будь-якого проєкту:

```bash
vendor/bin/lift                          # список усіх команд
vendor/bin/lift list                     # те саме
vendor/bin/lift help <command>           # довідка з однієї команди
vendor/bin/lift version                  # показати версію фреймворку
```

Налаштуйте аліас оболонки, якщо часто його набиратимете:

```bash
alias lift='vendor/bin/lift'
lift list
```

## Вбудовані команди

| Група       | Команда                          | Призначення                                            |
|-------------|----------------------------------|--------------------------------------------------------|
| **make**    | `make:controller <Name>`         | Згенерувати клас контролера                            |
|             | `make:request    <Name>`         | Згенерувати підклас `FormRequest`                      |
|             | `make:resource   <Name>`         | Згенерувати підклас `JsonResource`                     |
|             | `make:model      <Name>`         | Згенерувати active-record модель                       |
|             | `make:middleware <Name>`         | Згенерувати middleware за PSR-15                       |
|             | `make:command    <Name>`         | Згенерувати підклас `Command`                          |
|             | `make:job        <Name>`         | Згенерувати задачу черги                               |
|             | `make:event      <Name>`         | Згенерувати клас події                                 |
|             | `make:test       <Name>`         | Згенерувати підклас `TestCase`                         |
|             | `make:migration  <name>`         | Згенерувати файл міграції з міткою часу                |
| **migrate** | `migrate`                        | Виконати всі очікувані міграції                        |
|             | `migrate:rollback [--steps=N]`   | Відкотити останні N пакетів (за замовчуванням 1)       |
|             | `migrate:reset`                  | Відкотити кожну міграцію                               |
|             | `migrate:fresh`                  | `reset` + `migrate`                                    |
|             | `migrate:status`                 | Табличний стан кожної міграції                         |
| **queue**   | `queue:work [--queue=...] [--sleep=N] [--max-jobs=N]` | Запустити воркер черги (див. [Черги](queues#running-a-worker)) |
|             | `queue:table`                    | Вивести SQL/міграцію для створення таблиці черги в БД  |
| **routes**  | `routes:list [--bootstrap=...]`  | Перелічити кожен зареєстрований маршрут у таблиці       |
| **app**     | `serve [--port=8000]`            | Запустити `php -S` на `public/`                        |
|             | `key:generate`                   | Вивести випадковий `APP_KEY` (32 байти в base64)       |
|             | `repl`                           | Запустити інтерактивний PHP REPL із контекстом застосунку |

Більшість генераторів приймають ці прапори:

```bash
lift make:controller AdminController --namespace=App\\Admin --path=src/Admin
```

| Прапор           | За замовчуванням | Призначення                              |
|------------------|------------------|------------------------------------------|
| `--namespace=…`  | `App`            | PHP-простір імен згенерованого класу     |
| `--path=…`       | `src`            | Каталог запису (відносно CWD)            |
| `--force`        | вимк.            | Перезаписувати наявні файли              |

Згенеровані файли навмисно мінімальні — це відправні точки, редагуйте вільно.

## `make:test` — генерація тестових класів

```bash
lift make:test UserTest
# → src/Tests/UserTest.php

lift make:test Feature/OrderFlowTest --namespace=Tests\\Feature
# → src/Tests/Feature/OrderFlowTest.php
```

Згенерована заготовка успадковує `Lift\Testing\TestCase`:

```php
final class UserTest extends TestCase
{
    public function testExample(): void
    {
        $this->assertTrue(true);
    }
}
```

`TestCase` постачає HTTP-помічники (`$this->get(...)`, `$this->post(...)`, `$this->assertStatus(200)`) — див. [Тестування](testing) для повного API.

## `repl` — інтерактивний PHP REPL

`lift repl` поміщає вас у живий PHP-інтерпретатор із уже завантаженим застосунком:

```
$ lift repl
Lift REPL — type PHP and press Enter. Type exit or Ctrl+D to quit.
$app is available.

>>> $app->configuration()->get('app.name')
"My App"

>>> $app->db()->table('users')->count()
42

>>> $u = new App\Models\User(); $u->name = 'Alice'
>>> $u
App\Models\User {"name":"Alice"}

>>> exit
Bye!
```

**Як це працює**

1. Lift шукає файли початкового завантаження в такому порядку:
   - `bootstrap/app.php`
   - `app/bootstrap.php`
   - `app.php`

   Якщо знайдено, він під’єднує файл і робить повернене значення доступним як `$app`.
2. Кожен рядок спершу пробується як вираз (`return (…);`). Якщо він парситься, повернене значення друкується в компактному вигляді у стилі var_export. Якщо не парситься (присвоєння, потік керування тощо), рядок виконується як оператор.
3. Змінні зберігаються між ітераціями — задайте `$x = 5` на одному рядку, використовуйте `$x` на наступному.
4. Багаторядковий ввід: завершіть рядок `\`, щоб продовжити на наступному.

```
>>> $users = $app->db()
...   ->table('users')
...   ->where('active', true)
...   ->get() \
... ->pluck('email')
["alice@example.com","bob@example.com"]
```

**Прапори**

| Прапор | Призначення |
|---|---|
| `--bootstrap=path` | Явний файл початкового завантаження (перевизначає автовизначення) |

Приклад:
```bash
lift repl --bootstrap=app/bootstrap.php
lift repl --bootstrap=/var/www/myapp/bootstrap/app.php
```

**Історія** зберігається в `~/.lift_repl_history` і завантажується під час наступного запуску, тож ви можете стрілкою вгору гортати попередні сесії.

**Вимоги**: має бути встановлено PHP-розширення `readline` (`php-readline` у більшості дистрибутивів Linux). REPL повідомить вам, якщо воно відсутнє.

---

### Практичні приклади REPL

#### Перевірка конфігурації

```
>>> $app->configuration()->get('app.name')
"My App"

>>> $app->configuration()->get('database.connections.mysql.host')
"localhost"

>>> $app->environment()
"local"
```

#### Запит до бази даних

```
>>> $app->db()->table('users')->count()
42

>>> $app->db()->table('users')->where('active', true)->get()
[{"id":1,"email":"alice@example.com","name":"Alice"}, ...]

>>> $app->db()->table('orders')->where('status', 'pending')->count()
7
```

#### Тестування моделі

```
>>> $user = $app->container()->get(\App\Models\User::class)
>>> $user->find(1)
App\Models\User {"id":1,"email":"alice@example.com","name":"Alice","active":true}

>>> $user->find(9999)
null
```

#### Перевірка маршрутів

```
>>> $app->router()->getRoutes()
[{"path":"/","method":"GET","handler":"App\Http\Controllers\HomeController@index","name":"home"}, ...]

>>> $app->router()->match('GET', '/users/42')
{"path":"/users/{id}","handler":"App\Http\Controllers\UserController@show","params":{"id":"42"}}
```

#### Робота з кешем

```
>>> $cache = $app->container()->get(\Psr\SimpleCache\CacheInterface::class)
>>> $cache->get('user:42:profile')
{"id":42,"name":"Alice","role":"admin"}

>>> $cache->set('test_key', 'test_value', 60)
true
>>> $cache->get('test_key')
"test_value"
```

#### Разова задача — створити користувача

```
>>> $app->db()->table('users')->insert([
...   'email' => 'admin@example.com',
...   'name' => 'Admin',
...   'password_hash' => password_hash('secret', PASSWORD_BCRYPT),
...   'created_at' => time(),
... ])
true

>>> $app->db()->table('users')->where('email', 'admin@example.com')->first()
{"id":43,"email":"admin@example.com","name":"Admin",...}
```

#### Тестування сервісу

```
>>> $payment = $app->container()->get(\App\Services\PaymentService::class)
>>> $payment->charge(1000, 'tok_visa')
{"id":"ch_1234","amount":1000,"status":"succeeded"}
```

#### Перевірка прив’язок контейнера

```
>>> $app->container()->has(\App\Services\PaymentService::class)
true

>>> $app->container()->make(\Lift\Http\Request::class)
Lift\Http\Request {...}
```

#### Налагодження змінної

```
>>> $data = ['foo' => 'bar', 'nested' => ['a' => 1, 'b' => 2]]
>>> $data
{"foo":"bar","nested":{"a":1,"b":2}}
```

#### Спробувати виклик API

```
>>> $client = $app->container()->get(\Lift\Http\HttpClient::class)
>>> $client->get('https://api.github.com/repos/malinichevvv/lift-php')->json()
{"id":123456789,"name":"lift-php","full_name":"malinichevvv/lift-php",...}
```

---

### Коли використовувати REPL vs альтернативи

| Задача | REPL | CLI-команда | Тест | Скрипт |
|--------|------|-------------|------|--------|
| Швидкий експеримент з API | ✅ Найкраще | ❌ Надмірно | ❌ Повільно | ❌ Шаблонний код |
| Разове виправлення даних | ✅ Добре | ✅ Краще, якщо повторно використовуване | ❌ Ні | ✅ Добре для складного |
| Налагодження продакшену (обережно) | ✅ Можливо | ✅ Безпечніше | ❌ Ні | ❌ Ні |
| Повторювана задача | ❌ Немає історії | ✅ Ідеально | ❌ Ні | ✅ Так |
| Складна логіка | ❌ Немає скасування | ✅ Версіонується | ✅ Перевірено | ✅ Версіонується |

---

### Обмеження REPL

- **Немає скасування** — якщо ви видалите дані через `$app->db()->table('x')->delete()`, вони зникли. Будьте обережні в продакшені.
- **Немає персистентності** — сесії REPL не зберігають стан. Перезавантажте, щоб почати заново.
- **Глобальний стан** — зміни зачіпають лише процес PHP. Інші воркери/процеси їх не побачать.
- **Довгоживучий стан** — якщо ви відкриваєте транзакцію й забуваєте зафіксувати/відкотити її, вона залишається відкритою до виходу з REPL.

## Додавання власних команд

`Lift\Console\Application` приймає будь-який підклас `Lift\Console\Command`. Покладіть файл у `bin/`:

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lift\Console\Application;
use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;

final class CleanCacheCommand extends Command
{
    public function getName(): string        { return 'cache:clear'; }
    public function getDescription(): string { return 'Wipe the application cache'; }

    public function execute(Input $input, Output $output): int
    {
        $output->write('Clearing cache… ');
        // …власне робота…
        $output->success('done');
        return 0;
    }
}

$app = require __DIR__ . '/../app/bootstrap.php';   // ваш застосунок Lift

$cli = new Application('myapp', '1.0.0');
$cli->register($app->make(CleanCacheCommand::class));     // розв’язано через DI
exit($cli->run());
```

Зробіть його виконуваним: `chmod +x bin/myapp`. Запустіть: `./bin/myapp cache:clear`.

### Угода: давайте командам простори імен

Використовуйте `:` в імені команди, щоб групувати команди в `list`. `lift list` надрукує їх під заголовками:

```
 cache
  cache:clear                    Wipe the application cache
  cache:warmup                   Pre-build the page cache

 db
  db:seed                        Run the seeders
```

## Базовий клас `Command`

```php
abstract class Command
{
    abstract public function getName(): string;            // наприклад, 'cache:clear'
    abstract public function getDescription(): string;     // однорядкове резюме
    abstract public function execute(Input $i, Output $o): int;   // код виходу

    public function getHelp(): string { return $this->getDescription(); }  // необов’язкова довга довідка
}
```

Повертайте `0` з `execute()` за успіху, ненульове значення за невдачі. Код виходу — це те, що повертає `Application::run()` — ідеально для shell-скриптів:

```bash
lift migrate || { echo 'migration failed'; exit 1; }
```

## `Input` — читання argv

```php
$input->getCommand();                    // 'migrate'
$input->getArgument(0, 'default');       // перший позиційний аргумент
$input->getArguments();                  // усі позиційні аргументи (окрім команди)
$input->getOption('queue', 'default');   // --queue=foo або 'default'
$input->hasOption('force');              // чи було передано --force?
```

Правила парсингу argv:

- `--name=value` → опція `name` із рядковим значенням.
- `--name` → опція `name` зі значенням `true`.
- `-X` (один символ) → опція `X` зі значенням `true`.
- Усе інше, за порядком, стає командою, а потім позиційними аргументами.

Навмисно **немає оголошень обов’язкових аргументів** — читайте потрібні аргументи, відкочуйтеся до значень за замовчуванням, падайте з корисним повідомленням:

```php
public function execute(Input $i, Output $o): int
{
    $name = $i->getArgument(0);
    if ($name === '') {
        $o->error('Usage: lift make:foo <name>');
        return 1;
    }
    // …
}
```

## `Output` — запис у stdout/stderr з кольором

Теги у стилі розмітки всередині рядків — `<green>`, `<yellow>`, `<red>`, `<cyan>`, `<bold>`, `<grey>` — перетворюються на ANSI-escape лише коли stdout це TTY. У пайпі (`lift foo | grep …`) або в тестах теги вирізаються.

```php
$o->writeln('Hello');
$o->writeln('<green>Success</green> in <bold>0.4s</bold>');
$o->write('Working… ');           // без переведення рядка
$o->writeln('done');

$o->success('All clear.');         // зелений
$o->warn('Slow query detected');   // жовтий
$o->error('Boom');                 // червоний, → stderr
$o->info('Heads up');              // блакитний
```

### Таблиці

```php
$o->table(
    headers: ['ID', 'Email', 'Active'],
    rows:    [
        [1, 'alice@example.com', 'yes'],
        [2, 'bob@example.com',   'no'],
    ],
);
```

Автоматично підганяє стовпці під найширшу клітинку. Використовуйте для виводу у стилі `migrate:status`.

## Автономний CLI (без застосунку Lift)

`Lift\Console\Application` не залежить від `Lift\App`. Ви можете використовувати його окремо:

```php
use Lift\Console\Application;

$cli = new Application('mytool', '0.1.0');
$cli->register(new GenerateReadmeCommand());
$cli->register(new CheckLinksCommand());
exit($cli->run());
```

Чудово для специфічного для проєкту інструментарію без HTTP-стека.

## Реальний приклад — щоденне cron-завдання

`bin/daily.php`:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

use Lift\Console\Application;
use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;

$app = require __DIR__ . '/../app/bootstrap.php';

final class PurgeOldSessions extends Command
{
    public function __construct(private readonly \Lift\Database\Connection $db) {}

    public function getName(): string        { return 'purge:sessions'; }
    public function getDescription(): string { return 'Delete sessions older than 30 days'; }

    public function execute(Input $i, Output $o): int
    {
        $days = (int) $i->getOption('days', 30);
        $cut  = time() - 86400 * $days;

        $n = $this->db->execute('DELETE FROM sessions WHERE last_activity < ?', [$cut]);
        $o->success("Purged {$n} stale session(s).");
        return 0;
    }
}

$cli = new Application('daily', '1.0.0');
$cli->register($app->make(PurgeOldSessions::class));
exit($cli->run());
```

Crontab:

```
0 3 * * *  cd /var/www/myapp && php bin/daily.php purge:sessions --days=30
```

## Тестування команд

`Output` приймає власні потокові ресурси, тож тести можуть захоплювати stdout/stderr без форка процесу:

```php
public function testItPrintsHello(): void
{
    $out = fopen('php://memory', 'r+');
    $err = fopen('php://memory', 'r+');

    $cmd    = new MyCommand();
    $exit   = $cmd->execute(new Input(['arg']), new Output($out, $err));

    rewind($out);
    self::assertSame(0, $exit);
    self::assertStringContainsString('Hello', stream_get_contents($out));
}
```

Кольорові теги автоматично вирізаються, коли `Output` не вважає, що він на TTY — ваші твердження залишаються вільними від ANSI-escape.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `Command 'foo' not found` | Забули `register()` її | Додайте її в початкове завантаження вашого CLI. |
| Кольори показуються буквально як `<green>...` | Output не виявляє TTY (пайп, перенаправлення) | Очікувано — кольори показуються лише в реальних терміналах. |
| Вивід довгої команди не скидається | `fwrite()` у stdout рядково-буферизований | `fflush(STDOUT)` після запису — або використовуйте `Output::writeln()`, який пише цілі рядки. |
| `Cannot resolve parameter $db` під час реєстрації | Початкове завантаження CLI забуло під’єднати DI | Будуйте команди через контейнер: `$cli->register($app->make(MyCmd::class))`. |
| `lift queue:work` одразу виходить із кодом 0 | Драйвер черги не налаштовано ⇒ `SyncQueue::pop()` завжди повертає `null`, і `sleep` тікає вічно — але він продовжує працювати. Симптом — «нічого не відбувається» | Налаштуйте справжній драйвер (Redis, БД). |
| `pcntl_signal not available` у воркері | Скомпільований PHP без pcntl | Установіть `php-pcntl`; некоректне завершення все одно працює. |

## Шпаргалка

```php
// Створити команду
final class MyCmd extends Command
{
    public function getName(): string        { return 'my:cmd'; }
    public function getDescription(): string { return 'Does X'; }
    public function execute(Input $i, Output $o): int { /* … */ return 0; }
}

// Читання вводу
$i->getCommand() / getArgument(0) / getArguments() / getOption('name') / hasOption('force');

// Запис виводу
$o->writeln('plain');
$o->success('green'); $o->warn('yellow'); $o->error('red, → stderr'); $o->info('cyan');
$o->table(['a','b'], [[1,2]]);

// Запустити CLI
$cli = new Application('myapp', '1.0.0');
$cli->register($cmd);
exit($cli->run());

// Вбудовані команди
vendor/bin/lift list / version / help <cmd>
vendor/bin/lift make:controller|request|resource|model|middleware|command|job|event|test <Name>
vendor/bin/lift make:migration <name>
vendor/bin/lift migrate / migrate:rollback / migrate:fresh / migrate:status
vendor/bin/lift queue:work
vendor/bin/lift routes:list
vendor/bin/lift key:generate
vendor/bin/lift serve --port=8000
vendor/bin/lift repl [--bootstrap=path/to/app.php]
```

[Локалізація →](localization)
