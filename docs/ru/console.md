---
layout: page
title: Консоль (CLI)
nav_order: 30
---

# Консоль (CLI)

Lift поставляет крошечный, дружелюбный к PSR CLI-фреймворк — `Lift\Console\Application` — и бинарник `vendor/bin/lift`, который идёт с генераторами (`make:controller`, `make:model`, …), инструментами базы данных (`migrate`, `migrate:rollback`) и воркером очередей.

> Ментальная модель: CLI-приложение — это просто коллекция объектов `Command` с ключами по имени. `Application` парсит argv, находит совпадающую команду, вызывает её метод `execute(Input, Output): int`.

## Использование `vendor/bin/lift`

После `composer require malinichevvv/lift-php` бинарник доступен в PATH для любого проекта:

```bash
vendor/bin/lift                          # список всех команд
vendor/bin/lift list                     # то же самое
vendor/bin/lift help <command>           # справка по одной команде
vendor/bin/lift version                  # показать версию фреймворка
```

Настройте алиас оболочки, если будете часто его набирать:

```bash
alias lift='vendor/bin/lift'
lift list
```

## Встроенные команды

| Группа      | Команда                          | Назначение                                             |
|-------------|----------------------------------|--------------------------------------------------------|
| **make**    | `make:controller <Name>`         | Сгенерировать класс контроллера                        |
|             | `make:request    <Name>`         | Сгенерировать подкласс `FormRequest`                   |
|             | `make:resource   <Name>`         | Сгенерировать подкласс `JsonResource`                  |
|             | `make:model      <Name>`         | Сгенерировать active-record модель                     |
|             | `make:middleware <Name>`         | Сгенерировать middleware по PSR-15                     |
|             | `make:command    <Name>`         | Сгенерировать подкласс `Command`                       |
|             | `make:job        <Name>`         | Сгенерировать задачу очереди                           |
|             | `make:event      <Name>`         | Сгенерировать класс события                            |
|             | `make:test       <Name>`         | Сгенерировать подкласс `TestCase`                      |
|             | `make:migration  <name>`         | Сгенерировать файл миграции с меткой времени           |
| **migrate** | `migrate`                        | Выполнить все ожидающие миграции                       |
|             | `migrate:rollback [--steps=N]`   | Откатить последние N пакетов (по умолчанию 1)          |
|             | `migrate:reset`                  | Откатить каждую миграцию                               |
|             | `migrate:fresh`                  | `reset` + `migrate`                                    |
|             | `migrate:status`                 | Табличное состояние каждой миграции                    |
| **queue**   | `queue:work [--queue=...] [--sleep=N] [--max-jobs=N]` | Запустить воркер очереди (см. [Очереди](queues#running-a-worker)) |
|             | `queue:table`                    | Вывести SQL/миграцию для создания таблицы очереди в БД |
| **routes**  | `routes`                         | Перечислить каждый зарегистрированный маршрут в таблице |
| **app**     | `serve [--port=8000]`            | Запустить `php -S` на `public/`                        |
|             | `key:generate`                   | Вывести случайный `APP_KEY` (32 байта в base64)        |
|             | `repl`                           | Запустить интерактивный PHP REPL с контекстом приложения |

Большинство генераторов принимают эти флаги:

```bash
lift make:controller AdminController --namespace=App\\Admin --path=src/Admin
```

| Флаг             | По умолчанию   | Назначение                               |
|------------------|----------------|------------------------------------------|
| `--namespace=…`  | `App`          | PHP-пространство имён сгенерированного класса |
| `--path=…`       | `src`          | Каталог записи (относительно CWD)        |
| `--force`        | выкл.          | Перезаписывать существующие файлы        |

Сгенерированные файлы намеренно минимальны — это отправные точки, редактируйте свободно.

## `make:test` — генерация тестовых классов

```bash
lift make:test UserTest
# → src/Tests/UserTest.php

lift make:test Feature/OrderFlowTest --namespace=Tests\\Feature
# → src/Tests/Feature/OrderFlowTest.php
```

Сгенерированная заготовка наследует `Lift\Testing\TestCase`:

```php
final class UserTest extends TestCase
{
    public function testExample(): void
    {
        $this->assertTrue(true);
    }
}
```

`TestCase` поставляет HTTP-помощники (`$this->get(...)`, `$this->post(...)`, `$this->assertStatus(200)`) — см. [Тестирование](testing) для полного API.

## `repl` — интерактивный PHP REPL

`lift repl` помещает вас в живой PHP-интерпретатор с уже загруженным приложением:

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

**Как это работает**

1. Lift ищет файлы начальной загрузки в таком порядке:
   - `bootstrap/app.php`
   - `app/bootstrap.php`
   - `app.php`

   Если найден, он подключает файл и делает возвращаемое значение доступным как `$app`.
2. Каждая строка сначала пробуется как выражение (`return (…);`). Если она парсится, возвращаемое значение печатается в компактном виде в стиле var_export. Если не парсится (присваивание, поток управления и т. д.), строка выполняется как оператор.
3. Переменные сохраняются между итерациями — задайте `$x = 5` на одной строке, используйте `$x` на следующей.
4. Многострочный ввод: завершите строку `\`, чтобы продолжить на следующей.

```
>>> $users = $app->db()
...   ->table('users')
...   ->where('active', true)
...   ->get() \
... ->pluck('email')
["alice@example.com","bob@example.com"]
```

**Флаги**

| Флаг | Назначение |
|---|---|
| `--bootstrap=path` | Явный файл начальной загрузки (переопределяет автоопределение) |

Пример:
```bash
lift repl --bootstrap=app/bootstrap.php
lift repl --bootstrap=/var/www/myapp/bootstrap/app.php
```

**История** сохраняется в `~/.lift_repl_history` и загружается при следующем запуске, так что вы можете стрелкой вверх пролистывать предыдущие сессии.

**Требования**: должно быть установлено PHP-расширение `readline` (`php-readline` в большинстве дистрибутивов Linux). REPL сообщит вам, если оно отсутствует.

---

### Практические примеры REPL

#### Проверка конфигурации

```
>>> $app->configuration()->get('app.name')
"My App"

>>> $app->configuration()->get('database.connections.mysql.host')
"localhost"

>>> $app->environment()
"local"
```

#### Запрос к базе данных

```
>>> $app->db()->table('users')->count()
42

>>> $app->db()->table('users')->where('active', true)->get()
[{"id":1,"email":"alice@example.com","name":"Alice"}, ...]

>>> $app->db()->table('orders')->where('status', 'pending')->count()
7
```

#### Тестирование модели

```
>>> $user = $app->container()->get(\App\Models\User::class)
>>> $user->find(1)
App\Models\User {"id":1,"email":"alice@example.com","name":"Alice","active":true}

>>> $user->find(9999)
null
```

#### Проверка маршрутов

```
>>> $app->router()->getRoutes()
[{"path":"/","method":"GET","handler":"App\Http\Controllers\HomeController@index","name":"home"}, ...]

>>> $app->router()->match('GET', '/users/42')
{"path":"/users/{id}","handler":"App\Http\Controllers\UserController@show","params":{"id":"42"}}
```

#### Работа с кэшем

```
>>> $cache = $app->container()->get(\Psr\SimpleCache\CacheInterface::class)
>>> $cache->get('user:42:profile')
{"id":42,"name":"Alice","role":"admin"}

>>> $cache->set('test_key', 'test_value', 60)
true
>>> $cache->get('test_key')
"test_value"
```

#### Разовая задача — создать пользователя

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

#### Тестирование сервиса

```
>>> $payment = $app->container()->get(\App\Services\PaymentService::class)
>>> $payment->charge(1000, 'tok_visa')
{"id":"ch_1234","amount":1000,"status":"succeeded"}
```

#### Проверка привязок контейнера

```
>>> $app->container()->has(\App\Services\PaymentService::class)
true

>>> $app->container()->make(\Lift\Http\Request::class)
Lift\Http\Request {...}
```

#### Отладка переменной

```
>>> $data = ['foo' => 'bar', 'nested' => ['a' => 1, 'b' => 2]]
>>> $data
{"foo":"bar","nested":{"a":1,"b":2}}
```

#### Попробовать вызов API

```
>>> $client = $app->container()->get(\Lift\Http\HttpClient::class)
>>> $client->get('https://api.github.com/repos/malinichevvv/lift-php')->json()
{"id":123456789,"name":"lift-php","full_name":"malinichevvv/lift-php",...}
```

---

### Когда использовать REPL vs альтернативы

| Задача | REPL | CLI-команда | Тест | Скрипт |
|--------|------|-------------|------|--------|
| Быстрый эксперимент с API | ✅ Лучше всего | ❌ Избыточно | ❌ Медленно | ❌ Шаблонный код |
| Разовое исправление данных | ✅ Хорошо | ✅ Лучше, если переиспользуемо | ❌ Нет | ✅ Хорошо для сложного |
| Отладка продакшена (осторожно) | ✅ Возможно | ✅ Безопаснее | ❌ Нет | ❌ Нет |
| Повторяющаяся задача | ❌ Нет истории | ✅ Идеально | ❌ Нет | ✅ Да |
| Сложная логика | ❌ Нет отмены | ✅ Версионируется | ✅ Проверено | ✅ Версионируется |

---

### Ограничения REPL

- **Нет отмены** — если вы удалите данные через `$app->db()->table('x')->delete()`, они пропали. Будьте осторожны в продакшене.
- **Нет персистентности** — сессии REPL не сохраняют состояние. Перезагрузите, чтобы начать заново.
- **Глобальное состояние** — изменения затрагивают только процесс PHP. Другие воркеры/процессы их не увидят.
- **Долгоживущее состояние** — если вы открываете транзакцию и забываете зафиксировать/откатить её, она остаётся открытой до выхода из REPL.

## Добавление собственных команд

`Lift\Console\Application` принимает любой подкласс `Lift\Console\Command`. Положите файл в `bin/`:

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
        // …собственно работа…
        $output->success('done');
        return 0;
    }
}

$app = require __DIR__ . '/../app/bootstrap.php';   // ваше приложение Lift

$cli = new Application('myapp', '1.0.0');
$cli->register($app->make(CleanCacheCommand::class));     // разрешено через DI
exit($cli->run());
```

Сделайте его исполняемым: `chmod +x bin/myapp`. Запустите: `./bin/myapp cache:clear`.

### Соглашение: давайте командам пространства имён

Используйте `:` в имени команды, чтобы группировать команды в `list`. `lift list` напечатает их под заголовками:

```
 cache
  cache:clear                    Wipe the application cache
  cache:warmup                   Pre-build the page cache

 db
  db:seed                        Run the seeders
```

## Базовый класс `Command`

```php
abstract class Command
{
    abstract public function getName(): string;            // например, 'cache:clear'
    abstract public function getDescription(): string;     // однострочное резюме
    abstract public function execute(Input $i, Output $o): int;   // код выхода

    public function getHelp(): string { return $this->getDescription(); }  // необязательная длинная справка
}
```

Возвращайте `0` из `execute()` при успехе, ненулевое значение при неудаче. Код выхода — это то, что возвращает `Application::run()` — идеально для shell-скриптов:

```bash
lift migrate || { echo 'migration failed'; exit 1; }
```

## `Input` — чтение argv

```php
$input->getCommand();                    // 'migrate'
$input->getArgument(0, 'default');       // первый позиционный аргумент
$input->getArguments();                  // все позиционные аргументы (кроме команды)
$input->getOption('queue', 'default');   // --queue=foo или 'default'
$input->hasOption('force');              // был ли передан --force?
```

Правила парсинга argv:

- `--name=value` → опция `name` со строковым значением.
- `--name` → опция `name` со значением `true`.
- `-X` (один символ) → опция `X` со значением `true`.
- Всё остальное, по порядку, становится командой, а затем позиционными аргументами.

Намеренно **нет объявлений обязательных аргументов** — читайте нужные аргументы, откатывайтесь к значениям по умолчанию, падайте с полезным сообщением:

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

## `Output` — запись в stdout/stderr с цветом

Теги в стиле разметки внутри строк — `<green>`, `<yellow>`, `<red>`, `<cyan>`, `<bold>`, `<grey>` — преобразуются в ANSI-escape только когда stdout это TTY. В пайпе (`lift foo | grep …`) или в тестах теги вырезаются.

```php
$o->writeln('Hello');
$o->writeln('<green>Success</green> in <bold>0.4s</bold>');
$o->write('Working… ');           // без перевода строки
$o->writeln('done');

$o->success('All clear.');         // зелёный
$o->warn('Slow query detected');   // жёлтый
$o->error('Boom');                 // красный, → stderr
$o->info('Heads up');              // голубой
```

### Таблицы

```php
$o->table(
    headers: ['ID', 'Email', 'Active'],
    rows:    [
        [1, 'alice@example.com', 'yes'],
        [2, 'bob@example.com',   'no'],
    ],
);
```

Автоматически подгоняет столбцы под самую широкую ячейку. Используйте для вывода в стиле `migrate:status`.

## Автономный CLI (без приложения Lift)

`Lift\Console\Application` не зависит от `Lift\App`. Вы можете использовать его отдельно:

```php
use Lift\Console\Application;

$cli = new Application('mytool', '0.1.0');
$cli->register(new GenerateReadmeCommand());
$cli->register(new CheckLinksCommand());
exit($cli->run());
```

Отлично для специфичного для проекта инструментария без HTTP-стека.

## Реальный пример — ежедневное cron-задание

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

## Тестирование команд

`Output` принимает собственные потоковые ресурсы, так что тесты могут захватывать stdout/stderr без форка процесса:

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

Цветовые теги автоматически вырезаются, когда `Output` не считает, что он на TTY — ваши утверждения остаются свободными от ANSI-escape.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `Command 'foo' not found` | Забыли `register()` её | Добавьте её в начальную загрузку вашего CLI. |
| Цвета показываются буквально как `<green>...` | Output не обнаруживает TTY (пайп, перенаправление) | Ожидаемо — цвета показываются только в реальных терминалах. |
| Вывод длинной команды не сбрасывается | `fwrite()` в stdout строчно-буферизирован | `fflush(STDOUT)` после записи — или используйте `Output::writeln()`, который пишет целые строки. |
| `Cannot resolve parameter $db` при регистрации | Начальная загрузка CLI забыла подключить DI | Стройте команды через контейнер: `$cli->register($app->make(MyCmd::class))`. |
| `lift queue:work` сразу выходит с кодом 0 | Драйвер очереди не настроен ⇒ `SyncQueue::pop()` всегда возвращает `null`, и `sleep` тикает вечно — но он продолжает работать. Симптом — «ничего не происходит» | Настройте настоящий драйвер (Redis, БД). |
| `pcntl_signal not available` в воркере | Скомпилированный PHP без pcntl | Установите `php-pcntl`; некорректное завершение всё равно работает. |

## Шпаргалка

```php
// Создать команду
final class MyCmd extends Command
{
    public function getName(): string        { return 'my:cmd'; }
    public function getDescription(): string { return 'Does X'; }
    public function execute(Input $i, Output $o): int { /* … */ return 0; }
}

// Чтение ввода
$i->getCommand() / getArgument(0) / getArguments() / getOption('name') / hasOption('force');

// Запись вывода
$o->writeln('plain');
$o->success('green'); $o->warn('yellow'); $o->error('red, → stderr'); $o->info('cyan');
$o->table(['a','b'], [[1,2]]);

// Запустить CLI
$cli = new Application('myapp', '1.0.0');
$cli->register($cmd);
exit($cli->run());

// Встроенные команды
vendor/bin/lift list / version / help <cmd>
vendor/bin/lift make:controller|request|resource|model|middleware|command|job|event|test <Name>
vendor/bin/lift make:migration <name>
vendor/bin/lift migrate / migrate:rollback / migrate:fresh / migrate:status
vendor/bin/lift queue:work
vendor/bin/lift routes
vendor/bin/lift key:generate
vendor/bin/lift serve --port=8000
vendor/bin/lift repl [--bootstrap=path/to/app.php]
```

[Локализация →](localization)
