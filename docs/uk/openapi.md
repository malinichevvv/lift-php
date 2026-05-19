---
layout: page
title: Генератор OpenAPI
nav_order: 33
---

# Генератор OpenAPI

`Lift\OpenApi\Generator` рефлексує ваші контролери й виробляє специфікацію **OpenAPI 3.0.3** — JSON-документ, який споживають інструменти на кшталт Swagger UI, Redoc, Postman і генератори коду. Специфікація будується з тих самих атрибутів `#[Get]` / `#[Post]` / …, які ви вже використовуєте для [маршрутизації через атрибути](attribute-routing), плюс жменьки необов’язкових атрибутів, специфічних для OpenAPI.

> Ментальна модель: атрибути маршрутів описують, **як під’єднати** ендпоінт; атрибути OpenAPI описують, **що розповісти людям і машинам** про нього. Lift генерує специфікацію на етапі збірки / завантаження, ви віддаєте її як JSON.

## Коли варто перейматися

- У вашого API є зовнішні споживачі (мобільний застосунок, сторонні інтеграції).
- Вам потрібна безкоштовна, завжди актуальна сторінка Swagger UI.
- Ваша команда використовує генератор коду для створення типізованих SDK.

Коли **не варто**: невеликий внутрішній API, де документації в README достатньо.

## Приклад за 30 секунд

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

// У початковому завантаженні або CLI-команді:
$gen = new Generator(
    title:       'My API',
    version:     '1.0.0',
    description: 'JSON API for everything',
    serverUrl:   'https://api.example.com',
);
$gen->addController(UserController::class);

file_put_contents(__DIR__ . '/../public/openapi.json', $gen->toJson());
```

Тепер Swagger UI / Redoc можуть вказувати на `/openapi.json` і рендерити документацію.

## API генератора

```php
$gen = new Generator(
    title:       'My API',          // обов’язково
    version:     '1.0.0',           // обов’язково
    description: '…',               // опційно
    serverUrl:   'https://api.example.com',
);

$gen->addController(UserController::class);
$gen->addController(OrderController::class);

$gen->addSchema(UserDTO::class);                 // для components/schemas

$gen->addSecurityScheme('bearerAuth', [
    'type'         => 'http',
    'scheme'       => 'bearer',
    'bearerFormat' => 'JWT',
]);

$spec = $gen->generate();        // масив
$json = $gen->toJson();          // JSON-рядок, за замовчуванням гарний
```

`generate()` повертає специфікацію як звичайний асоціативний масив. `toJson()` — синтаксичний цукор над `json_encode`.

## Атрибути OpenAPI

Усі живуть під `Lift\OpenApi\Attribute\`. Вони **окремі** від атрибутів маршрутизації — можна використовувати один набір без іншого.

### На рівні класу

| Атрибут       | Призначення                                          |
|---------------|------------------------------------------------------|
| `#[ApiTag]`   | Згрупувати шляхи всіх методів під тегом у документації |
| `#[ApiSecurity]` | Безпека за замовчуванням, застосовувана до всіх методів класу |

```php
#[ApiTag('Users', description: 'Account management')]
#[ApiSecurity(scheme: 'bearerAuth')]
final class UserController { … }
```

### На рівні методу

| Атрибут         | Призначення                                          |
|-----------------|------------------------------------------------------|
| `#[ApiOperation]` | Summary, description, operationId, теги на метод   |
| `#[ApiParam]`     | Документувати параметр query / path / header / body |
| `#[ApiResponse]`  | Документувати один код відповіді з необов’язковою схемою |
| `#[ApiSecurity]`  | Перевизначити / додати безпеку для цього методу    |

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

Параметри шляху, оголошені в URL, автоматично включаються навіть без `#[ApiParam]` — генератор витягує їх із шаблонів `{id:\d+}`. Обмеження через двокрапку (`:\d+`) автоматично вирізається під час видачі специфікації, тож ви отримуєте `/users/{id}`, а не буквальний regex.

## Компоненти та схеми

Для складних тіл відповіді/запиту оголосіть PHP-клас DTO й посилайтеся на нього:

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

Зареєструйте й посилайтеся:

```php
$gen->addSchema(UserDTO::class);

// У контролері:
#[ApiResponse(200, schema: UserDTO::class)]
```

У згенерованій специфікації відповідь стає:

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

Генератор оглядає публічні властивості й зіставляє типи PHP з типами OpenAPI:

| PHP                    | OpenAPI                |
|------------------------|------------------------|
| `int` / `integer`      | `{"type": "integer"}`  |
| `float` / `double`     | `{"type": "number", "format": "float"}` |
| `string`               | `{"type": "string"}`   |
| `bool` / `boolean`     | `{"type": "boolean"}`  |
| `array`                | `{"type": "array", "items": {"type": "string"}}` (вважайте за TODO) |

Для тоншого контролю (вкладені об’єкти, масиви посилань, переліки) передайте сиру JSON-схему як рядок:

```php
#[ApiResponse(200, schema: '{"type":"array","items":{"$ref":"#/components/schemas/User"}}')]
```

## Схеми безпеки

OpenAPI розділяє «які схеми існують» і «яка схема застосовується до якої операції». Оголосіть схеми один раз, потім посилайтеся на них на контролер/метод.

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

Потім застосуйте:

```php
#[ApiSecurity(scheme: 'bearerAuth')]
final class UserController { /* застосовується до кожного методу */ }

#[ApiSecurity(scheme: 'apiKey')]
#[Post('/webhooks/incoming')]
public function webhook(Request $req): Response { /* лише цей метод */ }
```

Можна складати кілька схем (семантика АБО — достатньо будь-якої).

## Віддача специфікації

Два підходи.

### 1. Статично — генерувати на етапі збірки

Найлегший варіант. Додайте CLI-команду, яка пише файл, і запускайте її під час деплою:

```bash
vendor/bin/lift make:openapi --output=public/openapi.json
```

Легко кешувати. Нульова вартість у рантаймі.

### 2. Динамічно — генерувати на запит

Якщо специфікація залежить від рантайм-конфігурації (різні схеми на орендаря, захищені маршрути), генеруйте її на льоту:

```php
$app->get('/openapi.json', function () use ($gen) {
    return Response::json($gen->generate())
        ->withHeader('Cache-Control', 'public, max-age=300');
});
```

Кешуйте на кілька хвилин — рефлексія не безкоштовна, але це всього кілька мс.

## Рендеринг — Swagger UI / Redoc

Покладіть статичний HTML кудись під `public/`:

```html
<!-- public/docs.html — Swagger UI через CDN -->
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

Та сама ідея з Redoc (один тег `<script>`).

## Опрацьований приклад — повний контролер

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

Генератор перетворює це на повну специфікацію `/api/v1/users` / `/api/v1/users/{id}` з параметрами, схемами відповідей і вимогою JWT на кожній операції — без жодного файлу YAML.

## Обмеження

- **Схеми тіла запиту** поза простим `#[ApiParam(in: 'body')]` не виражаються нативно. Для складних тіл зареєструйте DTO `#[ApiSchema]` й посилайтеся на нього з власного `requestBody`, доданого постобробкою згенерованого масиву.
- **Поліморфні / oneOf / discriminator** схеми потребують форми `schema:` із сирим рядком на `#[ApiResponse]`.
- Генератор рефлексує **лише публічні властивості**. Моделям із гетерами потрібен DTO.
- **Правила валідації** (`'required|email|max:255'`) не перекладаються в обмеження OpenAPI автоматично. Дві системи живуть пліч-о-пліч; копіюйте обмеження в `#[ApiParam]`, коли вам це важливо.

Це навмисні компроміси простоти — генератор чисто покриває 80% випадків і прибирається з дороги для решти.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Метод відсутній у специфікації | На ньому немає атрибута маршруту (`#[Get]`/…) | Генератор OpenAPI включає лише маршрути з атрибутами — імперативний `$app->get('/x', ...)` для нього невидимий. |
| Шлях буквально показує `{id:\d+}` | Стара версія генератора | Lift вирізає regex-обмеження зі шляхів OpenAPI автоматично — переконайтеся, що ви на поточному релізі. |
| У схем усе типу `string` | У DTO немає типізованих властивостей | Додайте типи (`public int $id;`) на DTO. |
| Swagger UI каже «no operations» | URL специфікації повернув 404 / невірний CORS | Зверніться до нього через `curl` — URL має бути доступний за CORS, якщо Swagger на іншому джерелі. |
| Специфікація регенерується на кожному запиті | Ви використовуєте динамічний маршрут + рефлексію | Кешуйте вивід JSON (`Cache-Control` або запис на диск під час збірки). |
| Безпека показується на операціях, яким вона не потрібна | `#[ApiSecurity]` був на рівні класу | Перевизначте на метод через `#[ApiSecurity(scheme: '')]` — або реструктуруйте контролер. |

## Шпаргалка

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

[Панель налагодження →](debug)
