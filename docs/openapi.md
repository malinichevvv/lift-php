---
layout: page
title: OpenAPI generator
nav_order: 33
---

# OpenAPI generator

`Lift\OpenApi\Generator` reflects your controllers and produces a **OpenAPI 3.0.3** specification — a JSON document that tools like Swagger UI, Redoc, Postman, and code generators consume. The spec is built from the same `#[Get]` / `#[Post]` / … attributes you already use for [attribute routing](attribute-routing), plus a handful of optional OpenAPI-specific attributes.

> Mental model: route attributes describe **how to wire** the endpoint; OpenAPI attributes describe **what to tell humans and machines** about it. Lift generates the spec at build time / boot time, you serve it as JSON.

## When to bother

- Your API has external consumers (mobile app, third-party integrations).
- You want a free, always-up-to-date Swagger UI page.
- Your team uses a code generator to produce typed SDKs.

When **not**: a small internal-only API where docs in the README are fine.

## 30-second example

```php
use Lift\OpenApi\Generator;
use Lift\OpenApi\Attribute\{ApiOperation, ApiResponse, ApiParam, ApiTag};
use Lift\Attribute\{Get, Post, Group};

#[Group('/api/v1')]
#[ApiTag('Users')]
final class UserController
{
    #[Get('/users/{id:\d+}')]
    #[ApiOperation(summary: 'Fetch one user')]
    #[ApiResponse(200, description: 'OK')]
    #[ApiResponse(404, description: 'Not found')]
    public function show(Request $req): Response { /* … */ }

    #[Post('/users')]
    #[ApiOperation(summary: 'Create user')]
    #[ApiParam('email', in: 'body', type: 'string', description: 'User email')]
    #[ApiResponse(201, description: 'Created')]
    public function store(Request $req): Response { /* … */ }
}

// In bootstrap or a CLI command:
$gen = new Generator(
    title:       'My API',
    version:     '1.0.0',
    description: 'JSON API for everything',
    serverUrl:   'https://api.example.com',
);
$gen->addController(UserController::class);

file_put_contents(__DIR__ . '/../public/openapi.json', $gen->toJson());
```

Now Swagger UI / Redoc can point at `/openapi.json` and render the docs.

## Generator API

```php
$gen = new Generator(
    title:       'My API',          // required
    version:     '1.0.0',           // required
    description: '…',               // optional
    serverUrl:   'https://api.example.com',
);

$gen->addController(UserController::class);
$gen->addController(OrderController::class);

$gen->addSchema(UserDTO::class);                 // for components/schemas

$gen->addSecurityScheme('bearerAuth', [
    'type'         => 'http',
    'scheme'       => 'bearer',
    'bearerFormat' => 'JWT',
]);

$spec = $gen->generate();        // array
$json = $gen->toJson();          // JSON string, pretty by default
```

`generate()` returns the spec as a plain associative array. `toJson()` is a sugar wrapper around `json_encode`.

## The OpenAPI attributes

All live under `Lift\OpenApi\Attribute\`. They're **separate** from routing attributes — you can use one set without the other.

### Class-level

| Attribute     | Purpose                                              |
|---------------|------------------------------------------------------|
| `#[ApiTag]`   | Group every method's path under a tag in the docs    |
| `#[ApiSecurity]` | Default security applied to all methods in the class |

```php
#[ApiTag('Users', description: 'Account management')]
#[ApiSecurity(scheme: 'bearerAuth')]
final class UserController { … }
```

### Method-level

| Attribute       | Purpose                                              |
|-----------------|------------------------------------------------------|
| `#[ApiOperation]` | Summary, description, operationId, per-method tags |
| `#[ApiParam]`     | Document a query / path / header / body parameter |
| `#[ApiResponse]`  | Document one response code with optional schema   |
| `#[ApiSecurity]`  | Override / add security for this one method       |

```php
#[Get('/users/{id:\d+}')]
#[ApiOperation(
    summary:     'Get user',
    description: 'Returns one user by ID',
    operationId: 'getUserById',
)]
#[ApiParam('id', in: 'path', type: 'integer', description: 'User ID')]
#[ApiParam('include', in: 'query', type: 'string', required: false)]
#[ApiResponse(200, description: 'OK', schema: UserResource::class)]
#[ApiResponse(404, description: 'Not found')]
public function show(Request $req): Response { … }
```

Path params declared in the URL are auto-included even without `#[ApiParam]` — the generator extracts them from `{id:\d+}` patterns. The colon-constraint (`:\d+`) is stripped automatically when the spec is emitted, so you get `/users/{id}` rather than the literal regex.

## Components & schemas

For complex response/request bodies, declare a PHP DTO class and reference it:

```php
use Lift\OpenApi\Attribute\ApiSchema;

#[ApiSchema(name: 'User', description: 'Public user representation')]
final class UserDTO
{
    public int $id;
    public string $email;
    public ?string $name;
    public bool $active;
}
```

Register and reference:

```php
$gen->addSchema(UserDTO::class);

// In a controller:
#[ApiResponse(200, schema: UserDTO::class)]
```

In the generated spec the response becomes:

```json
{
  "responses": {
    "200": {
      "description": "OK",
      "content": {
        "application/json": { "schema": { "$ref": "#/components/schemas/User" } }
      }
    }
  }
}
```

The generator inspects public properties and maps PHP types to OpenAPI types:

| PHP                    | OpenAPI                |
|------------------------|------------------------|
| `int` / `integer`      | `{"type": "integer"}`  |
| `float` / `double`     | `{"type": "number", "format": "float"}` |
| `string`               | `{"type": "string"}`   |
| `bool` / `boolean`     | `{"type": "boolean"}`  |
| `array`                | `{"type": "array", "items": {"type": "string"}}` (treat as TODO) |

For finer control (nested objects, arrays of refs, enums), pass a raw JSON schema as a string:

```php
#[ApiResponse(200, schema: '{"type":"array","items":{"$ref":"#/components/schemas/User"}}')]
```

## Security schemes

OpenAPI separates "what schemes exist" from "which scheme applies to which operation". Declare schemes once, then reference them per controller/method.

```php
$gen->addSecurityScheme('bearerAuth', [
    'type'         => 'http',
    'scheme'       => 'bearer',
    'bearerFormat' => 'JWT',
]);

$gen->addSecurityScheme('apiKey', [
    'type' => 'apiKey',
    'in'   => 'header',
    'name' => 'X-API-Key',
]);
```

Then apply:

```php
#[ApiSecurity(scheme: 'bearerAuth')]
final class UserController { /* applies to every method */ }

#[ApiSecurity(scheme: 'apiKey')]
#[Post('/webhooks/incoming')]
public function webhook(Request $req): Response { /* this method only */ }
```

You can stack multiple schemes (OR semantics — any one suffices).

## Serving the spec

Two approaches.

### 1. Static — generate at build time

The leanest option. Add a CLI command that writes the file, and run it during deploy:

```bash
vendor/bin/lift make:openapi --output=public/openapi.json
```

Easy to cache. Zero runtime cost.

### 2. Dynamic — generate per request

If the spec depends on runtime config (different schemas per tenant, gated routes), generate it on the fly:

```php
$app->get('/openapi.json', function () use ($gen) {
    return Response::json($gen->generate())
        ->withHeader('Cache-Control', 'public, max-age=300');
});
```

Cache for a few minutes — reflection isn't free, but it's only a few ms.

## Rendering — Swagger UI / Redoc

Drop the static HTML somewhere under `public/`:

```html
<!-- public/docs.html — Swagger UI via CDN -->
<!doctype html><html><head>
  <title>API Docs</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
</head><body>
  <div id="ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    SwaggerUIBundle({ url: '/openapi.json', dom_id: '#ui' });
  </script>
</body></html>
```

Same idea with Redoc (single `<script>` tag).

## Worked example — a complete controller

```php
use Lift\Attribute\{Get, Post, Group};
use Lift\OpenApi\Attribute\{ApiOperation, ApiParam, ApiResponse, ApiSecurity, ApiTag};

#[Group('/api/v1')]
#[ApiTag('Users', description: 'Account management')]
#[ApiSecurity(scheme: 'bearerAuth')]
final class UserController
{
    public function __construct(private readonly UserRepository $repo) {}

    #[Get('/users')]
    #[ApiOperation(summary: 'List users')]
    #[ApiParam('page',     in: 'query', type: 'integer', description: 'Page number')]
    #[ApiParam('per_page', in: 'query', type: 'integer', description: 'Items per page (max 100)')]
    #[ApiResponse(200, schema: UserListResource::class)]
    public function index(Request $req): Paginator { … }

    #[Get('/users/{id:\d+}')]
    #[ApiOperation(summary: 'Get one user', operationId: 'getUser')]
    #[ApiResponse(200, schema: UserDTO::class)]
    #[ApiResponse(404, description: 'User not found')]
    public function show(Request $req): Response { … }

    #[Post('/users')]
    #[ApiOperation(summary: 'Create user')]
    #[ApiParam('email',    in: 'body', type: 'string', required: true)]
    #[ApiParam('password', in: 'body', type: 'string', required: true)]
    #[ApiResponse(201, schema: UserDTO::class)]
    #[ApiResponse(422, description: 'Validation failed')]
    public function store(Request $req): Response { … }
}
```

The generator turns this into a complete `/api/v1/users` / `/api/v1/users/{id}` spec with parameters, response schemas, and the JWT requirement on every operation — without you touching a single YAML file.

## Limitations

- **Request body schemas** beyond simple `#[ApiParam(in: 'body')]` aren't expressed natively. For complex bodies, register a `#[ApiSchema]` DTO and reference it from a custom `requestBody` you add by post-processing the generated array.
- **Polymorphic / oneOf / discriminator** schemas need the raw-string `schema:` form on `#[ApiResponse]`.
- The generator reflects **public properties only**. Models with getters need a DTO.
- **Validation rules** (`'required|email|max:255'`) aren't translated to OpenAPI constraints automatically. The two systems live side by side; copy-paste constraints into `#[ApiParam]` when you care.

These are intentional simplicity trade-offs — the generator covers the 80% case cleanly and gets out of your way for the rest.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Method missing from the spec | No route attribute (`#[Get]`/…) on it | OpenAPI generator only includes attributed routes — imperative `$app->get('/x', ...)` is invisible to it. |
| Path shows `{id:\d+}` literally | Old version of the generator | Lift strips regex constraints from OpenAPI paths automatically — make sure you're on the current release. |
| Schemas have all `string` type | DTO has no typed properties | Add types (`public int $id;`) on the DTO. |
| Swagger UI says "no operations" | Spec URL returned 404 / wrong CORS | Hit it with `curl` — the URL must be CORS-accessible if Swagger is on a different origin. |
| Spec regenerates on every request | You're using the dynamic route + reflection | Cache the JSON output (`Cache-Control` or write to disk on build). |
| Security shows on operations that don't need it | `#[ApiSecurity]` was class-level | Override per-method with `#[ApiSecurity(scheme: '')]` — or restructure the controller. |

## Cheat sheet

```php
use Lift\OpenApi\Attribute\{ApiOperation, ApiParam, ApiResponse, ApiSecurity, ApiSchema, ApiTag};

#[ApiTag('Users')]
#[ApiSecurity(scheme: 'bearerAuth')]
final class UserController
{
    #[Get('/users/{id:\d+}')]
    #[ApiOperation(summary: '…', operationId: 'getUser')]
    #[ApiParam('id', in: 'path', type: 'integer')]
    #[ApiResponse(200, schema: UserDTO::class)]
    #[ApiResponse(404, description: 'Not found')]
    public function show(): Response { … }
}

$gen = new Generator(title: 'My API', version: '1.0.0', serverUrl: '/');
$gen->addController(UserController::class);
$gen->addSchema(UserDTO::class);
$gen->addSecurityScheme('bearerAuth', ['type' => 'http', 'scheme' => 'bearer']);

file_put_contents('public/openapi.json', $gen->toJson());
```

[Debug toolbar →](debug)
