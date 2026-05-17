---
layout: page
title: Sessions
nav_order: 11
---

# Sessions

A **session** is a per-user, server-side bag of data that persists across requests. Lift's session system is:

- **Driver-based** — file, database, Redis, Memcached, or in-memory drivers.
- **Cookie-driven** — only an opaque session ID lives in the cookie; the data stays server-side.
- **PSR-15-friendly** — the session is exposed as a request attribute, so your handlers stay testable.

> Mental model: `SessionMiddleware` reads the session ID from a cookie, loads the data through a backing store, attaches the `Session` object to the request, then writes any changes back on the way out.

## The simplest setup

For prototypes — file-backed sessions, no database required:

```php
use Lift\App;
use Lift\Http\Session\FileSessionStore;
use Lift\Http\Session\Session;
use Lift\Http\Session\SessionMiddleware;

$app = new App();

$store   = new FileSessionStore(__DIR__ . '/../storage/sessions');
$session = new Session($store, lifetime: 7200, cookieName: 'my_session');

$app->use(new SessionMiddleware($session));

// In any handler:
$app->get('/me', function (Request $req) {
    $session = $req->getAttribute('session');   // Lift\Http\Session\Session
    return ['user_id' => $session->get('user_id')];
});
```

Make sure `storage/sessions/` exists and is writable by the web-server user.

## What the middleware does, per request

1. Reads the session ID from the `my_session` cookie (or generates a new one if missing).
2. Hydrates the session by calling `Store::read($id)`.
3. Attaches the `Session` object to the request as `'session'` attribute.
4. Calls your handler.
5. In a `finally` block: ages flash data, then writes via `Store::write($id, …, ttl)`.
6. Appends a `Set-Cookie` header so the browser keeps the same ID next time.

Even when your handler throws, the session is still persisted (step 5).

## Reading and writing

Once attached, the `Session` API is small and obvious:

```php
$session = $req->getAttribute('session');

$session->get('key', $default = null);
$session->set('key', $value);
$session->has('key');                  // bool
$session->pull('key');                 // get + delete in one call
$session->forget('key', 'another');    // delete one or more keys
$session->all();                       // entire data array
```

Chainable:

```php
$session
    ->set('user_id', 42)
    ->set('last_seen', time());
```

## Flash messages

A **flash message** is a value that lives exactly one extra request — perfect for *"action succeeded"* notifications shown after a redirect.

```php
// In the handler that processed a form POST:
$session->flash('notice', 'User created.');
return Response::redirect('/users');

// On the redirected /users page:
$notice = $session->pull('notice');     // 'User created.' on the first read, gone afterwards
```

How it works: `flash()` writes the value normally *and* marks the key in `_flash_new`. After the handler runs, `ageFlashData()` moves `_flash_new` → `_flash_old` so the value survives one more request. On the *next* `ageFlashData()` call, anything in `_flash_old` is deleted.

## Session ID regeneration

**Always** regenerate the session ID right after a privilege change (login, role escalation) to prevent session-fixation attacks:

```php
$app->post('/login', function (Request $req) {
    $session = $req->getAttribute('session');

    // …authenticate the user…

    $session->regenerate();                     // ID rotated, old session deleted from store
    $session->set('user_id', $user->id);

    return Response::redirect('/dashboard');
});
```

Pass `$deleteOldSession: false` if you want to keep the old data accessible elsewhere — almost never the right choice.

> **Since 1.2.1:** as a defence-in-depth measure, when the session ID comes from a client cookie and the store holds no session under it, `start()` mints a fresh ID instead of adopting the client-supplied value. This does not replace calling `regenerate()` on login — an attacker can still fixate a *valid* session before authentication — but it stops a fixed unknown ID from being adopted outright.

## Destroying a session (logout)

```php
$app->post('/logout', function (Request $req) {
    $req->getAttribute('session')->destroy();
    return Response::redirect('/');
});
```

`destroy()` clears the data and deletes the entry from the store.

## Available drivers

All drivers implement `SessionStoreInterface`. Pick one based on where you want the data.

### `FileSessionStore`

```php
new FileSessionStore(__DIR__ . '/../storage/sessions');
```

Stores one file per session ID. Good for single-server, low-traffic apps. Run a periodic GC task (`store->gc(7200)`) so expired files are reaped — or run it inline at the start of each request if you don't care about a few ms of latency.

### `DatabaseSessionStore`

```php
use Lift\Database\Connection;
use Lift\Http\Session\DatabaseSessionStore;

$db = Connection::fromConfig([...]);

// Create the `sessions` table once (or run `lift migrate` if you generated a migration):
(new \Lift\Database\Migrator($db, '...'))->createSessionsTable();

new DatabaseSessionStore($db, table: 'sessions');
```

Survives across servers. Slowest of the four (every read/write is a SQL round-trip).

### `RedisSessionStore`

```php
use Lift\Http\Session\RedisSessionStore;
use Lift\Redis\RedisClient;

$redis = new RedisClient(host: 'redis', port: 6379);
new RedisSessionStore($redis, prefix: 'sess:');
```

Native TTL, sub-millisecond access. The default for any horizontally-scaled deployment.

### `MemcachedSessionStore`

```php
new MemcachedSessionStore($memcached);  // ext-memcached instance
```

Like Redis but uses Memcached. Has no persistence — fine for sessions, not for queues.

### `ArraySessionStore`

```php
new ArraySessionStore();
```

In-memory only, lost when the process dies. Perfect for [tests](testing).

## Custom stores

Implement `Lift\Http\Session\SessionStoreInterface`:

```php
interface SessionStoreInterface
{
    public function read(string $id): ?string;
    public function write(string $id, string $payload, int $ttl): void;
    public function destroy(string $id): void;
    public function gc(int $maxLifetime): void;
}
```

`$payload` is an opaque PHP-serialised string — your store treats it as a blob.

## Cookie attributes

When the middleware writes the cookie, it uses these defaults:

| Attribute    | Default                         | Override                                      |
|--------------|---------------------------------|-----------------------------------------------|
| `Path`       | `/`                             | hard-coded                                    |
| `HttpOnly`   | always                          | hard-coded                                    |
| `SameSite`   | `Lax`                           | hard-coded                                    |
| `Max-Age`    | `$lifetime` (default 7200 s)    | `new Session($store, lifetime: …)`           |
| `Secure`     | only on HTTPS                   | auto-detected from `$req->getUri()->getScheme()` |

If you need different cookie attributes (e.g. `SameSite=Strict`, a parent domain, etc.), build a custom middleware or subclass `SessionMiddleware`.

## Security checklist

- ✅ Always use HTTPS in production. The session cookie is the most security-critical part of your stack.
- ✅ Call `$session->regenerate()` on login / privilege change.
- ✅ Call `$session->destroy()` on logout.
- ✅ For sensitive data, **don't** put it in the session — only an opaque user ID. Look the rest up server-side on each request.
- ✅ Set a reasonable `lifetime`. 2 hours is the default; 30 minutes is safer for admin areas.
- ❌ Don't serialise objects with secrets into the session — pass the allowed-classes whitelist or store IDs only:
  ```php
  new Session($store, allowedClasses: false);          // no objects, scalars only
  new Session($store, allowedClasses: [Money::class]); // explicit allowlist
  ```

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Session is empty on every request | Middleware not registered, or wrong cookie name | `$app->use(new SessionMiddleware($session));` and check `cookieName`. |
| Login works locally but not in prod | Cookie's `Secure` flag is set, but you're on HTTP | Use HTTPS, or set up a TLS-terminating reverse proxy. |
| Data lost across two servers | File store + multiple app servers | Switch to Redis/DB. |
| `unserialize` security warnings | You stored an object whose class is no longer loadable | Use `allowedClasses: false` and store scalars only. |
| Flash message doesn't appear | You called `flash()` then read it on the **same** request | Flash is for *next* request — redirect first, read after. |
| Session "logged out" on POST | CSRF middleware regenerated the ID; or you re-used the old `$session` reference after `regenerate()` | Re-fetch with `$req->getAttribute('session')` after sensitive changes. |

## Cheat sheet

```php
// Boot
$store   = new FileSessionStore($path);             // or Redis/DB/Memcached
$session = new Session($store, lifetime: 7200);
$app->use(new SessionMiddleware($session));

// Use
$session = $req->getAttribute('session');
$session->set('user_id', 42);
$session->get('user_id');
$session->pull('flash');
$session->flash('notice', 'OK');
$session->regenerate();    // after login
$session->destroy();       // on logout
```

[Form requests →](form-requests)
