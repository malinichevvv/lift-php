---
layout: page
title: Console (CLI)
nav_order: 30
---

# Console (CLI)

Lift ships a tiny PSR-friendly CLI framework — `Lift\Console\Application` — and the `vendor/bin/lift` binary, which comes with generators (`make:controller`, `make:model`, …), database tools (`migrate`, `migrate:rollback`), and the queue worker.

> Mental model: a CLI app is just a collection of `Command` objects keyed by name. `Application` parses argv, finds the matching command, calls its `execute(Input, Output): int` method.

## Using `vendor/bin/lift`

After `composer require malinichevvv/lift-php`, the binary is on PATH for any project:

```bash
vendor/bin/lift                          # list all commands
vendor/bin/lift list                     # same thing
vendor/bin/lift help <command>           # help for one command
vendor/bin/lift version                  # show framework version
```

Set up a shell alias if you'll be typing it a lot:

```bash
alias lift='vendor/bin/lift'
lift list
```

## Built-in commands

| Group       | Command                          | Purpose                                                |
|-------------|----------------------------------|--------------------------------------------------------|
| **make**    | `make:controller <Name>`         | Generate a controller class                            |
|             | `make:request    <Name>`         | Generate a `FormRequest` subclass                      |
|             | `make:resource   <Name>`         | Generate a `JsonResource` subclass                     |
|             | `make:model      <Name>`         | Generate an active-record model                        |
|             | `make:middleware <Name>`         | Generate a PSR-15 middleware                           |
|             | `make:command    <Name>`         | Generate a `Command` subclass                          |
|             | `make:job        <Name>`         | Generate a queue job                                   |
|             | `make:event      <Name>`         | Generate an event class                                |
|             | `make:test       <Name>`         | Generate a `TestCase` subclass                         |
|             | `make:migration  <name>`         | Generate a timestamped migration file                  |
| **migrate** | `migrate`                        | Run all pending migrations                             |
|             | `migrate:rollback [--steps=N]`   | Roll back the last N batches (default 1)               |
|             | `migrate:reset`                  | Roll back every migration                              |
|             | `migrate:fresh`                  | `reset` + `migrate`                                    |
|             | `migrate:status`                 | Tabular state of every migration                       |
| **queue**   | `queue:work [--queue=...] [--sleep=N] [--max-jobs=N]` | Start a queue worker (see [Queues](queues#running-a-worker)) |
|             | `queue:table`                    | Print SQL/migration to create the database-queue table |
| **routes**  | `routes`                         | List every registered route in a table                 |
| **app**     | `serve [--port=8000]`            | Boot `php -S` on `public/`                             |
|             | `key:generate`                   | Print a random `APP_KEY` (base64-encoded 32 bytes)     |
|             | `repl`                           | Start an interactive PHP REPL with app context         |

Most generators accept these flags:

```bash
lift make:controller AdminController --namespace=App\\Admin --path=src/Admin
```

| Flag             | Default        | Purpose                                  |
|------------------|----------------|------------------------------------------|
| `--namespace=…`  | `App`          | PHP namespace of the generated class     |
| `--path=…`       | `src`          | Directory written to (relative to CWD)   |
| `--force`        | off            | Overwrite existing files                 |

Generated files are intentionally minimal — they're starting points, edit freely.

## `make:test` — generate test classes

```bash
lift make:test UserTest
# → src/Tests/UserTest.php

lift make:test Feature/OrderFlowTest --namespace=Tests\\Feature
# → src/Tests/Feature/OrderFlowTest.php
```

The generated stub extends `Lift\Testing\TestCase`:

```php
final class UserTest extends TestCase
{
    public function testExample(): void
    {
        $this->assertTrue(true);
    }
}
```

`TestCase` ships HTTP helpers (`$this->get(...)`, `$this->post(...)`, `$this->assertStatus(200)`) — see [Testing](testing) for the full API.

## `repl` — interactive PHP REPL

`lift repl` drops you into a live PHP interpreter with your app already loaded:

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

**How it works**

1. Lift looks for `bootstrap/app.php` (then `app.php`) in the current directory. If found, it requires the file and makes the returned value available as `$app`.
2. Each line is attempted as an expression first (`return (…);`). If it parses, the return value is printed using compact var_export-style output. If it doesn't parse (assignment, control flow, etc.), the line is executed as a statement.
3. Variables persist across iterations — set `$x = 5` on one line, use `$x` on the next.
4. Multi-line input: end a line with `\` to continue on the next line.

```
>>> $users = $app->db()
...   ->table('users')
...   ->where('active', true)
...   ->get() \
... ->pluck('email')
["alice@example.com","bob@example.com"]
```

**Flags**

| Flag | Purpose |
|---|---|
| `--bootstrap=path` | Explicit bootstrap file (overrides auto-detection) |

**History** is saved to `~/.lift_repl_history` and loaded on next launch, so you can arrow-up through previous sessions.

**Requirements**: The `readline` PHP extension must be installed (`php-readline` on most Linux distros). The REPL will tell you if it's missing.

## Adding your own commands

`Lift\Console\Application` accepts any subclass of `Lift\Console\Command`. Drop a file in `bin/`:

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
        // …actual work…
        $output->success('done');
        return 0;
    }
}

$app = require __DIR__ . '/../app/bootstrap.php';   // your Lift app

$cli = new Application('myapp', '1.0.0');
$cli->register($app->make(CleanCacheCommand::class));     // DI-resolved
exit($cli->run());
```

Make it executable: `chmod +x bin/myapp`. Run it: `./bin/myapp cache:clear`.

### Convention: namespace your commands

Use `:` in the command name to group commands in `list`. `lift list` will print them under headings:

```
 cache
  cache:clear                    Wipe the application cache
  cache:warmup                   Pre-build the page cache

 db
  db:seed                        Run the seeders
```

## The `Command` base class

```php
abstract class Command
{
    abstract public function getName(): string;            // e.g. 'cache:clear'
    abstract public function getDescription(): string;     // one-line summary
    abstract public function execute(Input $i, Output $o): int;   // exit code

    public function getHelp(): string { return $this->getDescription(); }  // optional long help
}
```

Return `0` from `execute()` on success, non-zero on failure. The exit code is what `Application::run()` returns — perfect for shell scripts:

```bash
lift migrate || { echo 'migration failed'; exit 1; }
```

## `Input` — reading argv

```php
$input->getCommand();                    // 'migrate'
$input->getArgument(0, 'default');       // first positional argument
$input->getArguments();                  // all positional args (excluding command)
$input->getOption('queue', 'default');   // --queue=foo or 'default'
$input->hasOption('force');              // --force was passed?
```

Argv parsing rules:

- `--name=value` → option `name` with string value.
- `--name` → option `name` with `true` value.
- `-X` (single char) → option `X` with `true`.
- Anything else, in order, becomes the command and then positional arguments.

There are intentionally **no required-argument declarations** — read the args you need, fall back to defaults, fail with a useful message:

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

## `Output` — writing to stdout/stderr with colour

Markup-style tags inside strings — `<green>`, `<yellow>`, `<red>`, `<cyan>`, `<bold>`, `<grey>` — are converted to ANSI escapes only when stdout is a TTY. In a pipe (`lift foo | grep …`) or in tests, the tags are stripped.

```php
$o->writeln('Hello');
$o->writeln('<green>Success</green> in <bold>0.4s</bold>');
$o->write('Working… ');           // no newline
$o->writeln('done');

$o->success('All clear.');         // green
$o->warn('Slow query detected');   // yellow
$o->error('Boom');                 // red, → stderr
$o->info('Heads up');              // cyan
```

### Tables

```php
$o->table(
    headers: ['ID', 'Email', 'Active'],
    rows:    [
        [1, 'alice@example.com', 'yes'],
        [2, 'bob@example.com',   'no'],
    ],
);
```

Auto-sizes columns to the widest cell. Use it for `migrate:status`-style output.

## Standalone CLI (without Lift app)

`Lift\Console\Application` doesn't depend on `Lift\App`. You can use it on its own:

```php
use Lift\Console\Application;

$cli = new Application('mytool', '0.1.0');
$cli->register(new GenerateReadmeCommand());
$cli->register(new CheckLinksCommand());
exit($cli->run());
```

Great for project-specific tooling without the HTTP stack.

## Real-world example — daily cron job

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

## Testing commands

`Output` accepts custom stream resources, so tests can capture stdout/stderr without forking a process:

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

Color tags are auto-stripped when `Output` doesn't think it's on a TTY — your assertions stay free of ANSI escapes.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `Command 'foo' not found` | Forgot to `register()` it | Add it to your CLI bootstrap. |
| Colours show as `<green>...` literally | Output detects no TTY (piped, redirected) | Expected — colours show only on real terminals. |
| Long command output isn't flushed | `fwrite()` to stdout is line-buffered | `fflush(STDOUT)` after writing — or use `Output::writeln()` which writes whole lines. |
| `Cannot resolve parameter $db` when registering | The CLI bootstrap forgot to wire DI | Build commands through the container: `$cli->register($app->make(MyCmd::class))`. |
| `lift queue:work` exits with code 0 immediately | No queue driver configured ⇒ `SyncQueue::pop()` always returns `null` and `sleep` ticks forever — but it does keep going. Symptom is "nothing happens" | Configure a real driver (Redis, DB). |
| `pcntl_signal not available` in worker | Compiled PHP lacks pcntl | Install `php-pcntl`; ungraceful shutdown still works. |

## Cheat sheet

```php
// Make a command
final class MyCmd extends Command
{
    public function getName(): string        { return 'my:cmd'; }
    public function getDescription(): string { return 'Does X'; }
    public function execute(Input $i, Output $o): int { /* … */ return 0; }
}

// Read input
$i->getCommand() / getArgument(0) / getArguments() / getOption('name') / hasOption('force');

// Write output
$o->writeln('plain');
$o->success('green'); $o->warn('yellow'); $o->error('red, → stderr'); $o->info('cyan');
$o->table(['a','b'], [[1,2]]);

// Boot a CLI
$cli = new Application('myapp', '1.0.0');
$cli->register($cmd);
exit($cli->run());

// Built-in commands
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

[Localization →](localization)
