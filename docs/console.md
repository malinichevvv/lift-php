---
layout: page
title: Console (CLI)
nav_order: 16
---

# Console (CLI)

Lift ships a console application with built-in commands for serving, migrating, generating code, and running queue workers. You can also write your own commands and register them with the application.

---

## Running commands

### Via `vendor/bin/lift`

```bash
php vendor/bin/lift <command> [arguments] [--options]
```

### Via Composer scripts (shorter)

After installation the `lift` script is registered in `composer.json`:

```bash
composer lift serve
composer lift migrate
composer lift make:controller UserController
```

### Built-in PHP server shortcut

```bash
composer lift serve           # http://localhost:8000
composer lift serve -- --host=0.0.0.0 --port=9000
```

---

## Built-in commands

### `serve`

Start the built-in PHP development server.

```bash
php vendor/bin/lift serve
php vendor/bin/lift serve --host=0.0.0.0 --port=9000
php vendor/bin/lift serve --root=public/
```

| Option | Default | Description |
|--------|---------|-------------|
| `--host` | `127.0.0.1` | Hostname to listen on. |
| `--port` | `8000` | Port number. |
| `--root` | `public/` | Document root directory. |

---

### `migrate`

Run all pending database migrations.

```bash
php vendor/bin/lift migrate
php vendor/bin/lift migrate --path=database/migrations
```

| Option | Default | Description |
|--------|---------|-------------|
| `--path` | `database/migrations` | Directory containing migration files. |

---

### `migrate:rollback`

Roll back the most recently run migration batch.

```bash
php vendor/bin/lift migrate:rollback
php vendor/bin/lift migrate:rollback --steps=3
```

| Option | Default | Description |
|--------|---------|-------------|
| `--steps` | `1` | Number of batches to roll back. |
| `--path` | `database/migrations` | Migrations directory. |

---

### `migrate:reset`

Roll back **all** migration batches (undo every migration).

```bash
php vendor/bin/lift migrate:reset
```

---

### `migrate:fresh`

Drop all tables by resetting all migrations, then run all migrations from scratch. Useful for development.

```bash
php vendor/bin/lift migrate:fresh
```

---

### `migrate:status`

Show the status of every migration file.

```bash
php vendor/bin/lift migrate:status
```

Example output:

```
Batch | Ran | Migration
------+-----+----------------------------------------------
1     | ✓   | 2024_01_10_000000_create_users_table
1     | ✓   | 2024_01_11_000000_create_posts_table
      | ✗   | 2024_06_01_000000_add_avatar_to_users_table
```

---

### `make:migration`

Create a new timestamped migration file. The stub content is inferred from the name:

| Name pattern | Generated stub |
|-------------|----------------|
| `create_{table}_table` | `Schema::create()` with `id()`, `timestamps()` |
| `add_{columns}_to_{table}` | `Schema::alter()` for adding columns |
| anything else | blank `up()` / `down()` methods |

```bash
php vendor/bin/lift make:migration create_products_table
php vendor/bin/lift make:migration add_avatar_to_users
php vendor/bin/lift make:migration drop_temp_table
php vendor/bin/lift make:migration create_orders_table --path=src/Migrations
```

| Option | Default | Description |
|--------|---------|-------------|
| `--path` | `database/migrations` | Directory to write the migration file. |

---

### `key:generate`

Generate a new `APP_KEY` and write it to `.env`. The key is a base64-encoded random 32-byte value. If `APP_KEY` already exists in the file, it is replaced in place; otherwise, it is appended.

```bash
php vendor/bin/lift key:generate
php vendor/bin/lift key:generate --env=.env.local
```

| Option | Default | Description |
|--------|---------|-------------|
| `--env` | `.env` (in `cwd`) | Path to the env file to write. |

Example output:

```
Application key set: base64:XvM0P9...
Written to /home/user/myapp/.env
```

---

### `list-routes`

Display all registered routes with their method, path, name, and handler.

```bash
php vendor/bin/lift list-routes
```

---

### `make:controller`

Generate a controller class skeleton.

```bash
php vendor/bin/lift make:controller UserController
php vendor/bin/lift make:controller Api/PostController --namespace=App\\Http\\Controllers --path=src
```

| Option | Default | Description |
|--------|---------|-------------|
| `--namespace` | `App\Http\Controllers` | PHP namespace for the generated class. |
| `--path` | `src` | Base directory (namespace is mapped to a subdirectory). |

---

### `make:model`

```bash
php vendor/bin/lift make:model Post
php vendor/bin/lift make:model Post --namespace=App\\Models
```

---

### `make:request`

```bash
php vendor/bin/lift make:request StorePostRequest
```

---

### `make:resource`

```bash
php vendor/bin/lift make:resource PostResource
```

---

### `make:middleware`

```bash
php vendor/bin/lift make:middleware AuthMiddleware
```

---

### `make:command`

Generate a console command skeleton.

```bash
php vendor/bin/lift make:command SendDailyReport
```

Generated stub:

```php
final class SendDailyReport extends Command
{
    public function getName(): string        { return 'send:daily-report'; }
    public function getDescription(): string { return ''; }

    public function execute(Input $input, Output $output): int
    {
        $output->writeln('Running SendDailyReport...');
        return 0;
    }
}
```

---

### `make:job`

Generate a queue job skeleton.

```bash
php vendor/bin/lift make:job SendWelcomeEmail
```

---

### `make:event`

Generate an event class skeleton.

```bash
php vendor/bin/lift make:event UserRegistered
```

---

### `make:test`

Generate a PHPUnit test skeleton.

```bash
php vendor/bin/lift make:test UserTest
php vendor/bin/lift make:test Feature/UserRegistrationTest --namespace=App\\Tests\\Feature
```

---

### `worker`

Start the queue worker. The worker polls the queue, processes jobs with retry logic, and handles graceful shutdown signals (requires `ext-pcntl`).

```bash
php vendor/bin/lift worker
php vendor/bin/lift worker --queue=emails --sleep=2 --max-jobs=500
```

| Option | Default | Description |
|--------|---------|-------------|
| `--queue` | `default` | Queue name to consume. |
| `--sleep` | `1` | Seconds to sleep between polls when queue is empty. |
| `--max-jobs` | `0` | Stop after processing N jobs (0 = run forever). |

---

## Writing custom commands

Extend `Command` and implement three methods:

```php
use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;

final class ClearCacheCommand extends Command
{
    public function getName(): string
    {
        return 'cache:clear';
    }

    public function getDescription(): string
    {
        return 'Clear the application cache';
    }

    public function execute(Input $input, Output $output): int
    {
        $prefix = $input->option('prefix', 'lift_');
        // ... clear cache ...
        $output->writeln("<info>Cache cleared (prefix: {$prefix})</info>");
        return 0; // 0 = success; non-zero = failure
    }
}
```

### Input helpers

```php
$input->argument('name');           // positional argument (first after command name)
$input->argument('name', 'Alice');  // with default
$input->option('path');             // --path=value or --path value
$input->option('verbose', false);   // with default
$input->hasOption('verbose');       // bool
```

### Output helpers

```php
$output->writeln('Hello');
$output->writeln('<info>Success</info>');     // green
$output->writeln('<comment>Note</comment>');  // yellow
$output->writeln('<error>Failed</error>');    // red
$output->table(['Column A', 'Column B'], [
    ['row1a', 'row1b'],
    ['row2a', 'row2b'],
]);
```

### Registering commands

Register commands when building the console application (e.g. in `bin/lift`):

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

use Lift\Console\Application;
use Lift\Database\Connection;
use App\Console\Commands\ClearCacheCommand;

$db  = new Connection(getenv('DB_DSN'));
$app = new Application('Lift', '1.0.0');

$app->register(new ClearCacheCommand());
// built-in commands
$app->register(new \Lift\Console\Commands\ServeCommand());
$app->register(new \Lift\Console\Commands\MigrateCommand($db));

exit($app->run());
```

---

## Bootstrap file (`bin/lift`)

Lift's own `bin/lift` entry point registers all built-in commands. Copy and adapt it as `bin/console` or `bin/artisan` for your project:

```php
#!/usr/bin/env php
<?php
require __DIR__ . '/../vendor/autoload.php';

use Lift\Console\Application;
use Lift\Console\Commands\{
    ServeCommand, ListRoutesCommand, WorkerCommand, KeyGenerateCommand,
    MakeCommand, MakeMigrationCommand,
    MigrateCommand, MigrateRollbackCommand, MigrateResetCommand,
    MigrateFreshCommand, MigrateStatusCommand,
};

// Optionally bootstrap your database
$db = isset($_ENV['DB_DSN']) ? new \Lift\Database\Connection($_ENV['DB_DSN']) : null;

$app = new Application('My App CLI', '1.0.0');

$app->register(new ServeCommand());
$app->register(new ListRoutesCommand($router ?? null));
$app->register(new KeyGenerateCommand());
$app->register(new MakeCommand('controller'));
$app->register(new MakeCommand('model'));
$app->register(new MakeCommand('middleware'));
$app->register(new MakeCommand('command'));
$app->register(new MakeCommand('job'));
$app->register(new MakeCommand('event'));
$app->register(new MakeCommand('test'));
$app->register(new MakeCommand('request'));
$app->register(new MakeCommand('resource'));

if ($db) {
    $app->register(new MakeMigrationCommand());
    $app->register(new MigrateCommand($db));
    $app->register(new MigrateRollbackCommand($db));
    $app->register(new MigrateResetCommand($db));
    $app->register(new MigrateFreshCommand($db));
    $app->register(new MigrateStatusCommand($db));
}

exit($app->run());
```
