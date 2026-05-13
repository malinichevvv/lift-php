---
title: Application foundation
nav_order: 18
---

# Application foundation

Lift keeps the core small, but includes optional base classes for common application structure: controllers, form requests, JSON resources, sessions, models, and CLI skeleton generators.

## Controllers

Controllers are optional. You can keep using closures or invokable classes, or extend `Lift\Http\Controller` for response helpers.

```php
use Lift\Http\Controller;
use Lift\Http\Request;
use Lift\Http\Response;

final class UserController extends Controller
{
    public function show(Request $request): Response
    {
        return $this->json(['id' => $request->param('id')]);
    }
}
```

## Form requests

`FormRequest` validates input and gives controllers a typed place for request rules.

```php
use Lift\Http\FormRequest;

final class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'name' => 'required|string|max:255',
        ];
    }
}

$form = StoreUserRequest::fromRequest($request);
$email = $form->string('email');
```

## JSON resources

Resources shape models or arrays before JSON output.

```php
use Lift\Http\JsonResource;

final class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id' => $this->value('id'),
            'email' => $this->value('email'),
        ];
    }
}

return new UserResource($user);
```

## Sessions

Use `SessionMiddleware` to start a driver-backed session and expose a `Session` object on the request.

```php
use Lift\Http\Session\FileSessionStore;
use Lift\Http\Session\Session;
use Lift\Http\Session\SessionMiddleware;

$store = new FileSessionStore(__DIR__ . '/../storage/sessions');
$app->use(new SessionMiddleware(new Session($store)));

$app->get('/me', function ($request) {
    $session = $request->getAttribute('session');
    return ['user_id' => $session->get('user_id')];
});
```

Built-in stores:

- `ArraySessionStore` for tests and short-lived development processes.
- `FileSessionStore` for simple persistent local storage.
- `DatabaseSessionStore` for SQL-backed sessions.
- `RedisSessionStore` for high-throughput distributed sessions.
- `MemcachedSessionStore` for distributed sessions through ext-memcached.

Session classes live under `Lift\Http\Session`. The previous `Lift\Http\Session`,
`Lift\Http\FileSessionStore`, and similar names remain available as deprecated
compatibility aliases.

The `SessionStoreInterface` contract can be implemented for any custom backend.

## Environment files

Lift includes a small dependency-free `.env` loader and typed environment reader.

```php
use Lift\Config\Env;

$app->loadEnv(__DIR__ . '/../.env');

$debug = Env::bool('APP_DEBUG', false);
$port = Env::int('APP_PORT', 8000);
$name = Env::string('APP_NAME', 'Lift');
$environment = $app->environment();
```

Supported `.env` syntax:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_NAME="Lift App"
export APP_PORT=8080
```

Existing process variables are not overwritten unless `overwrite: true` is passed.

## Models

`Model` is a lightweight active-record style helper over the existing query builder.

```php
use Lift\Database\Model;

final class User extends Model
{
    protected static string $table = 'users';
    protected array $fillable = ['name', 'email'];
}

User::setConnection($db);
$user = User::find(1);
$user->set('name', 'Updated')->save();
$user->delete();
```

For complex SQL, keep using `Connection::table()` and `QueryBuilder` directly.

## Database manager

`DatabaseManager` keeps named connections lazy. PDO connections are created only
when first used.

```php
use Lift\Database\DatabaseManager;

$db = DatabaseManager::fromConfig([
    'default' => 'main',
    'connections' => [
        'main' => ['driver' => 'sqlite', 'database' => __DIR__ . '/../database.sqlite'],
        'analytics' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'database' => 'analytics'],
    ],
]);

$users = $db->table('users')->get();
$events = $db->table('events', 'analytics')->count();
```

## Migrations

Migrations are explicit PHP classes returning a `Migration` instance.

```php
use Lift\Database\Migration;

return new class($db) extends Migration {
    public function up(): void
    {
        $this->db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT)');
    }

    public function down(): void
    {
        $this->db->execute('DROP TABLE users');
    }
};
```

Run them through `Migrator` or register `MigrateCommand` in your CLI bootstrap:

```php
$migrator = new Migrator($db, __DIR__ . '/../database/migrations');
$migrator->migrate();
$migrator->rollback();
```

For database sessions:

```php
$migrator->createSessionsTable();
```

## CLI skeletons

The `lift` binary includes generators:

```bash
lift make:controller UserController
lift make:request StoreUserRequest
lift make:resource UserResource
lift make:model User
lift make:middleware AuthMiddleware
```

Options:

```bash
lift make:controller AdminController --namespace=App\\Admin --path=src
```

Generated files are intentionally plain PHP and can be edited freely.
