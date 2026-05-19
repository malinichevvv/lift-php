---
layout: page
title: Генератор OpenAPI
nav_order: 33
---

# Генератор OpenAPI

`Lift\OpenApi\Generator` рефлексирует ваши контроллеры и производит спецификацию **OpenAPI 3.0.3** — JSON-документ, который потребляют инструменты вроде Swagger UI, Redoc, Postman и генераторы кода. Спецификация строится из тех же атрибутов `#[Get]` / `#[Post]` / …, которые вы уже используете для [маршрутизации через атрибуты](attribute-routing), плюс горстки необязательных атрибутов, специфичных для OpenAPI.

> Ментальная модель: атрибуты маршрутов описывают, **как подключить** эндпоинт; атрибуты OpenAPI описывают, **что рассказать людям и машинам** о нём. Lift генерирует спецификацию на этапе сборки / загрузки, вы отдаёте её как JSON.

## Когда стоит заморачиваться

- У вашего API есть внешние потребители (мобильное приложение, сторонние интеграции).
- Вам нужна бесплатная, всегда актуальная страница Swagger UI.
- Ваша команда использует генератор кода для создания типизированных SDK.

Когда **не стоит**: небольшой внутренний API, где документации в README достаточно.

## Пример за 30 секунд

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

// В начальной загрузке или CLI-команде:
$gen = new Generator(
    title:       'My API',
    version:     '1.0.0',
    description: 'JSON API for everything',
    serverUrl:   'https://api.example.com',
);
$gen->addController(UserController::class);

file_put_contents(__DIR__ . '/../public/openapi.json', $gen->toJson());
```

Теперь Swagger UI / Redoc могут указывать на `/openapi.json` и рендерить документацию.

## API генератора

```php
$gen = new Generator(
    title:       'My API',          // обязательно
    version:     '1.0.0',           // обязательно
    description: '…',               // опционально
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

$spec = $gen->generate();        // массив
$json = $gen->toJson();          // JSON-строка, по умолчанию красивая
```

`generate()` возвращает спецификацию как обычный ассоциативный массив. `toJson()` — синтаксический сахар над `json_encode`.

## Атрибуты OpenAPI

Все живут под `Lift\OpenApi\Attribute\`. Они **отдельны** от атрибутов маршрутизации — можно использовать один набор без другого.

### На уровне класса

| Атрибут       | Назначение                                           |
|---------------|------------------------------------------------------|
| `#[ApiTag]`   | Сгруппировать пути всех методов под тегом в документации |
| `#[ApiSecurity]` | Безопасность по умолчанию, применяемая ко всем методам класса |

```php
#[ApiTag('Users', description: 'Account management')]
#[ApiSecurity(scheme: 'bearerAuth')]
final class UserController { … }
```

### На уровне метода

| Атрибут         | Назначение                                           |
|-----------------|------------------------------------------------------|
| `#[ApiOperation]` | Summary, description, operationId, теги на метод   |
| `#[ApiParam]`     | Документировать параметр query / path / header / body |
| `#[ApiResponse]`  | Документировать один код ответа с необязательной схемой |
| `#[ApiSecurity]`  | Переопределить / добавить безопасность для этого метода |

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

Параметры пути, объявленные в URL, автоматически включаются даже без `#[ApiParam]` — генератор извлекает их из шаблонов `{id:\d+}`. Ограничение через двоеточие (`:\d+`) автоматически вырезается при выдаче спецификации, так что вы получаете `/users/{id}`, а не буквальный regex.

## Компоненты и схемы

Для сложных тел ответа/запроса объявите PHP-класс DTO и ссылайтесь на него:

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

Зарегистрируйте и ссылайтесь:

```php
$gen->addSchema(UserDTO::class);

// В контроллере:
#[ApiResponse(200, schema: UserDTO::class)]
```

В сгенерированной спецификации ответ становится:

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

Генератор осматривает публичные свойства и сопоставляет типы PHP с типами OpenAPI:

| PHP                    | OpenAPI                |
|------------------------|------------------------|
| `int` / `integer`      | `{"type": "integer"}`  |
| `float` / `double`     | `{"type": "number", "format": "float"}` |
| `string`               | `{"type": "string"}`   |
| `bool` / `boolean`     | `{"type": "boolean"}`  |
| `array`                | `{"type": "array", "items": {"type": "string"}}` (считайте за TODO) |

Для более тонкого контроля (вложенные объекты, массивы ссылок, перечисления) передайте сырую JSON-схему как строку:

```php
#[ApiResponse(200, schema: '{"type":"array","items":{"$ref":"#/components/schemas/User"}}')]
```

## Схемы безопасности

OpenAPI разделяет «какие схемы существуют» и «какая схема применяется к какой операции». Объявите схемы один раз, затем ссылайтесь на них на контроллер/метод.

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

Затем примените:

```php
#[ApiSecurity(scheme: 'bearerAuth')]
final class UserController { /* применяется к каждому методу */ }

#[ApiSecurity(scheme: 'apiKey')]
#[Post('/webhooks/incoming')]
public function webhook(Request $req): Response { /* только этот метод */ }
```

Можно складывать несколько схем (семантика ИЛИ — достаточно любой).

## Отдача спецификации

Два подхода.

### 1. Статически — генерировать на этапе сборки

Самый лёгкий вариант. Добавьте CLI-команду, которая пишет файл, и запускайте её при деплое:

```bash
vendor/bin/lift make:openapi --output=public/openapi.json
```

Легко кэшировать. Нулевая стоимость в рантайме.

### 2. Динамически — генерировать на запрос

Если спецификация зависит от рантайм-конфигурации (разные схемы на арендатора, защищённые маршруты), генерируйте её на лету:

```php
$app->get('/openapi.json', function () use ($gen) {
    return Response::json($gen->generate())
        ->withHeader('Cache-Control', 'public, max-age=300');
});
```

Кэшируйте на несколько минут — рефлексия не бесплатна, но это всего несколько мс.

## Рендеринг — Swagger UI / Redoc

Положите статический HTML куда-нибудь под `public/`:

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

Та же идея с Redoc (один тег `<script>`).

## Проработанный пример — полный контроллер

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

Генератор превращает это в полную спецификацию `/api/v1/users` / `/api/v1/users/{id}` с параметрами, схемами ответов и требованием JWT на каждой операции — без единого файла YAML.

## Ограничения

- **Схемы тела запроса** за пределами простого `#[ApiParam(in: 'body')]` не выражаются нативно. Для сложных тел зарегистрируйте DTO `#[ApiSchema]` и ссылайтесь на него из собственного `requestBody`, добавляемого пост-обработкой сгенерированного массива.
- **Полиморфные / oneOf / discriminator** схемы нуждаются в форме `schema:` с сырой строкой на `#[ApiResponse]`.
- Генератор рефлексирует **только публичные свойства**. Моделям с геттерами нужен DTO.
- **Правила валидации** (`'required|email|max:255'`) не переводятся в ограничения OpenAPI автоматически. Две системы живут бок о бок; копируйте ограничения в `#[ApiParam]`, когда вам это важно.

Это намеренные компромиссы простоты — генератор чисто покрывает 80% случаев и убирается с дороги для остального.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Метод отсутствует в спецификации | На нём нет атрибута маршрута (`#[Get]`/…) | Генератор OpenAPI включает только маршруты с атрибутами — императивный `$app->get('/x', ...)` для него невидим. |
| Путь буквально показывает `{id:\d+}` | Старая версия генератора | Lift вырезает regex-ограничения из путей OpenAPI автоматически — убедитесь, что вы на текущем релизе. |
| У схем всё типа `string` | У DTO нет типизированных свойств | Добавьте типы (`public int $id;`) на DTO. |
| Swagger UI говорит «no operations» | URL спецификации вернул 404 / неверный CORS | Обратитесь к нему через `curl` — URL должен быть доступен по CORS, если Swagger на другом источнике. |
| Спецификация регенерируется на каждом запросе | Вы используете динамический маршрут + рефлексию | Кэшируйте вывод JSON (`Cache-Control` или запись на диск при сборке). |
| Безопасность показывается на операциях, которым она не нужна | `#[ApiSecurity]` был на уровне класса | Переопределите на метод через `#[ApiSecurity(scheme: '')]` — или реструктурируйте контроллер. |

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

[Отладочная панель →](debug)
