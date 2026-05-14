---
layout: page
title: JSON resources
nav_order: 17
---

# JSON resources

A **JSON resource** is a thin class that decides *exactly which fields of a model leave your server* and how they look. Controllers stay focused on flow; resources own the wire format.

Use them when:

- The shape of the response differs from the database row (rename `created_at` → `createdAt`, omit `password`, etc.).
- The same model is rendered the same way in many places.
- You need consistent collection envelopes (`{"data": [...]}`).

> Mental model: think of a resource as the answer to *"what does a `User` look like over JSON?"* — declared once, reused everywhere.

## Smallest example

```php
use Lift\Http\JsonResource;

final class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return [
            'id'        => $this->value('id'),
            'email'     => $this->value('email'),
            'createdAt' => $this->value('created_at'),
        ];
    }
}
```

Use it from a handler:

```php
$app->get('/users/{id:\d+}', function (Request $req) use ($repo) {
    return new UserResource($repo->find((int) $req->param('id')));
});
```

Two things to notice:

1. The handler returned the resource object **directly** — the router calls `jsonSerialize()` on it, so it becomes JSON automatically.
2. `$this->value('field')` reads from whatever you wrapped (array, `ArrayAccess` object, or public property).

The response body is:

```json
{ "id": 1, "email": "alice@example.com", "createdAt": "2025-..." }
```

## What `value()` accepts

`$this->resource` can be:

| Wrapped type             | Read via                                          |
|--------------------------|---------------------------------------------------|
| `array`                  | `$resource[$key]`                                 |
| `ArrayAccess`            | `$resource[$key]`                                 |
| `object` (any class)     | `$resource->$key`                                 |

So the same resource works for an array row, a PDO `stdClass`, or a custom model.

```php
new UserResource(['id' => 1, 'email' => '...']);
new UserResource($model);          // any object with public properties
new UserResource(json_decode($json));
```

## Setting a status code

```php
$app->post('/users', function (Request $req) use ($repo) {
    $user = $repo->create($req->json());
    return (new UserResource($user))->response(201);
});
```

`->response($status)` returns a `Lift\Http\Response` with the JSON body and the given status.

## Collections

Return a list of resources via the static helper:

```php
$app->get('/users', function () use ($repo) {
    return UserResource::collection($repo->all());
});
```

`collection()` walks any `iterable` (array, generator, query result) and wraps each item in `new static(...)`. The router serialises the resulting array of resources to:

```json
[
  { "id": 1, "email": "..." },
  { "id": 2, "email": "..." }
]
```

### Wrapping in an envelope

Many APIs prefer `{"data": [...]}`. Wrap it explicitly:

```php
$app->get('/users', function () use ($repo) {
    return Response::json([
        'data' => UserResource::collection($repo->all()),
        'meta' => ['count' => count($repo->all())],
    ]);
});
```

…or build a custom `UserCollection` subclass:

```php
final class UserCollection
{
    public function __construct(private readonly iterable $items) {}

    public function toArray(): array
    {
        return [
            'data' => UserResource::collection($this->items),
            'meta' => ['count' => count((array) $this->items)],
        ];
    }
}

return new UserCollection($repo->all());
```

(Any object whose handler-returned shape is `array` becomes JSON — Lift doesn't care that it's not a `JsonResource`.)

## Conditional fields

Show admin-only fields, but only to admins:

```php
public function __construct(
    protected readonly mixed $resource,
    private readonly bool $includeAdminFields = false,
) {
    parent::__construct($resource);
}

public function toArray(): array
{
    $data = [
        'id'    => $this->value('id'),
        'email' => $this->value('email'),
    ];

    if ($this->includeAdminFields) {
        $data['isStaff']  = (bool) $this->value('is_staff');
        $data['lastIp']   = $this->value('last_login_ip');
    }

    return $data;
}

// Usage:
return new UserResource($user, includeAdminFields: $currentUser->isAdmin());
```

## Nested resources

A user has a profile? Include it via another resource:

```php
public function toArray(): array
{
    return [
        'id'      => $this->value('id'),
        'email'   => $this->value('email'),
        'profile' => $this->value('profile') ? new ProfileResource($this->value('profile')) : null,
    ];
}
```

The outer `jsonSerialize()` call recursively serialises every nested resource — they're each a `JsonSerializable`.

## Using a base date formatter

You'll quickly want consistent date formatting across resources. Extract a base:

```php
abstract class BaseResource extends JsonResource
{
    protected function date(string $key): ?string
    {
        $value = $this->value($key);
        if ($value === null) return null;
        $dt = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable($value);
        return $dt->format(\DateTimeInterface::ATOM);
    }
}

final class UserResource extends BaseResource
{
    public function toArray(): array {
        return [
            'id'        => $this->value('id'),
            'email'     => $this->value('email'),
            'createdAt' => $this->date('created_at'),
        ];
    }
}
```

## Generating with the CLI

```bash
vendor/bin/lift make:resource UserResource
```

Drops `src/Http/Resources/UserResource.php` with the right skeleton. See [Console](console).

## Compared with plain arrays

For a one-off `return ['id' => ..., 'email' => ...]`, a resource is overkill. Use one once you have **two or more endpoints** rendering the same thing, or **field-shaping logic** worth a name.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `Cannot access offset on object` | The wrapped resource doesn't implement `ArrayAccess` but you indexed it directly | Use `$this->value('key')` always, never `$this->resource['key']`. |
| Missing fields in output | Forgot to add them to `toArray()` | Edit the resource, not the controller. |
| Same shape but different field names | Two callers want camelCase vs snake_case | Make two resources (`UserResource`, `UserApiResource`) — composition over conditionals. |
| Nested model leaks all DB columns | You returned `$this->value('profile')` directly | Wrap it: `new ProfileResource($this->value('profile'))`. |
| `JsonException: malformed UTF-8` | Wrapped data has non-UTF-8 bytes (binary blob) | Don't include the blob, or `base64_encode` it first. |

## Cheat sheet

```php
// Define
final class UserResource extends JsonResource
{
    public function toArray(): array
    {
        return ['id' => $this->value('id'), 'email' => $this->value('email')];
    }
}

// Use
return new UserResource($user);                   // → 200 JSON
return (new UserResource($user))->response(201);  // custom status
return UserResource::collection($users);          // array of resources

// Read from wrapped value
$this->value('field');
$this->value('field', $default);
```

[Validation →](validation)
