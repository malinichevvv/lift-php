---
layout: page
title: Request
nav_order: 7
---

# Request

`Lift\Http\Request` — це незмінний об’єкт HTTP-запиту. Він реалізує `Psr\Http\Message\ServerRequestInterface` (тому сумісний із будь-яким коли-небудь написаним PSR-15 middleware) і додає поверх дружніші скорочення.

> Ментальна модель: `Request` — це знімок одного вхідного HTTP-виклику. Він *незмінний*. Будь-який метод, який «змінює» його (`with*`), повертає **новий** об’єкт — оригінал залишається недоторканим. Це зроблено навмисно і є тим самим правилом, якого дотримується будь-яка бібліотека PSR-7.

## Як отримати запит

У продакшені ви майже ніколи не створюєте Request самі. Просто **вкажіть його тип** в обробнику, і Lift його впровадить:

```php
use Lift\Http\Request;

$app->get('/users/{id}', function (Request $req) {
    return ['id' => $req->param('id')];
});
```

Інші способи — корисні для тестів або нестандартних точок входу:

```php
// Із суперглобалей PHP ($_SERVER, $_GET, $_POST, $_FILES, $_COOKIE, php://input)
$req = Request::fromGlobals();

// Вручну (чудово для тестів)
use Lift\Http\Uri;
$req = new Request('GET', new Uri('http://localhost/users/1'));
```

## Читання вхідних даних

| Джерело                            | Метод                                 |
|------------------------------------|---------------------------------------|
| Параметр маршруту `/users/{id}`    | `$req->param('id')`                   |
| Рядок запиту `?page=2`             | `$req->query('page')`                 |
| Поле тіла форми/JSON               | `$req->input('name')`                 |
| Усе тіло JSON                      | `$req->json()` (повертає масив)       |
| Усе розібране тіло                 | `$req->getParsedBody()` (PSR-7)       |
| Cookie                             | `$req->cookie('session')`             |
| Завантажений файл                  | `$req->file('avatar')`                |
| Значення заголовка                 | `$req->getHeaderLine('Accept')`       |
| Метод (`GET`, `POST`, …)           | `$req->getMethod()`                   |
| Повний об’єкт URI                  | `$req->getUri()`                      |
| Лише шлях                          | `$req->getUri()->getPath()`           |
| Атрибут middleware                 | `$req->getAttribute('user')`          |
| Усі серверні змінні                | `$req->getServerParams()` (≈ `$_SERVER`) |

### Параметри маршруту

```php
// Маршрут: /users/{id}
$id  = $req->param('id');            // '42' (завжди рядок)
$id  = (int) $req->param('id');      // 42  (приводьте тип самі)
$id  = $req->param('missing', 0);    // запасне значення за замовчуванням
$all = $req->params();               // ['id' => '42']

// Нижчого рівня: сирий асоціативний масив усіх збіглих параметрів маршруту
$raw = $req->getRouteParams();       // ['id' => '42']

// Корисно в тестах — побудувати запит із конкретними параметрами маршруту
$req = $req->withRouteParams(['id' => '42', 'slug' => 'hello']);
```

### Рядок запиту

```php
// URL: /search?q=lift&page=2
$q    = $req->query('q');             // 'lift'
$page = (int) $req->query('page', 1); // 2
$all  = $req->getQueryParams();       // ['q' => 'lift', 'page' => '2']
```

### Тіло запиту

Для запитів `POST`, `PUT` і `PATCH` Lift розбирає тіло автоматично на основі `Content-Type`:

- `application/json` → розбирається у масив, доступний через `$req->json()` **і** `$req->input(...)`.
- Усе інше → використовується `$_POST` (тобто `application/x-www-form-urlencoded` і `multipart/form-data`).

```php
// Form POST:  name=Alice&email=alice@example.com
$name = $req->input('name');

// JSON POST:  {"name":"Alice","email":"alice@example.com"}
$name  = $req->input('name');     // працює так само
$email = $req->json()['email'];   // і прямий доступ до масиву теж
```

Щоб прочитати **сире** тіло (наприклад, підписи вебхуків, яким потрібні нерозібрані байти):

```php
$raw = (string) $req->getBody();
```

> Якщо тіло потрібне кілька разів або після того, як його прочитав middleware, перемотайте потік: `$req->getBody()->rewind();`.

### Завантажені файли

```php
$avatar = $req->file('avatar');   // ?Psr\Http\Message\UploadedFileInterface

if ($avatar !== null && $avatar->getError() === UPLOAD_ERR_OK) {
    $avatar->moveTo(__DIR__ . '/../storage/uploads/' . $avatar->getClientFilename());
}
```

Доступна інформація з об’єкта файлу:

```php
$avatar->getSize();                  // байти
$avatar->getClientFilename();        // 'me.png'
$avatar->getClientMediaType();       // 'image/png'
$avatar->getError();                 // UPLOAD_ERR_OK тощо
$avatar->getStream();                // потік PSR-7
```

Кілька файлів під одним полем (`<input type="file" name="docs[]" multiple>`):

```php
// $req->getUploadedFiles() повертає нормалізоване дерево
foreach ($req->getUploadedFiles()['docs'] ?? [] as $file) {
    /* ... */
}
```

### Cookie

```php
$session = $req->cookie('session');
$all     = $req->getCookieParams();      // ['session' => '...', 'lang' => 'en']
```

Щоб **встановити** cookie, див. [Cookie у Response](response#cookies).

### Заголовки

```php
$accept = $req->getHeaderLine('Accept');       // 'application/json'
$lines  = $req->getHeader('Accept');           // ['application/json'] (форма списку)
$has    = $req->hasHeader('Authorization');    // bool
$all    = $req->getHeaders();                  // ['Accept' => [...], ...]
```

Імена заголовків регістронезалежні (`'Accept'` і `'accept'` обидва працюють).

## Помічники / скорочення

```php
$req->isJson();             // Content-Type містить application/json
$req->wantsJson();          // Accept містить application/json
$req->isMethod('POST');     // перевірка методу (регістронезалежна)
$req->getMethod();          // 'GET' | 'POST' | …
$req->getUri()->getPath();  // '/users/42'
```

Типове їх використання:

```php
$app->get('/users/{id}', function (Request $req) use ($repo) {
    $user = $repo->find((int) $req->param('id'));

    if ($req->wantsJson()) {
        return Response::json($user);
    }
    return Response::html($view->render('users.show', ['user' => $user]));
});
```

## Валідація — спосіб в один рядок

`Request::validate()` об’єднує параметри запиту + тіло + параметри маршруту, проганяє їх через [Валідатор](validation) і або повертає валідовані дані, або викидає `ValidationException` (який типовий обробник помилок Lift перетворює на HTTP 422):

```php
$app->post('/users', function (Request $req) use ($repo) {
    $data = $req->validate([
        'name'  => 'required|string|min:2|max:255',
        'email' => 'required|email|unique:users,email',
        'age'   => 'integer|min:13',
    ]);

    return Response::json($repo->create($data), 201);
});
```

Власні повідомлення про помилки:

```php
$data = $req->validate(
    ['email' => 'required|email'],
    ['email.required' => 'Нам потрібен ваш email, щоб надіслати посилання.'],
);
```

Передайте [Translator](localization) для локалізованих повідомлень:

```php
$data = $req->validate($rules, [], $translator);
```

Див. [Валідацію](validation) для повного переліку правил.

## Атрибути middleware — передавання даних униз конвеєром

Middleware може прикріплювати довільні значення до запиту, а обробник їх читає. Загальноприйнятий носій для «автентифікованого користувача», «claims із JWT», «ідентифікатора запиту» тощо.

```php
// У middleware:
$req = $req->withAttribute('user', $authenticatedUser);
return $handler->handle($req);

// В обробнику:
$user = $req->getAttribute('user');           // null, якщо не встановлено
$user = $req->getAttribute('user', $default); // зі значенням за замовчуванням
$all  = $req->getAttributes();
```

Атрибути — *на кожен запит*, ніколи не розділяються і зникають, коли запит завершується. Вони **не** є шиною подій і **не** персистентні — для цього використовуйте [Події](events) або [Сесії](sessions).

## Незмінність PSR-7

Найчастіша помилка на початку роботи з PSR-7:

```php
// ❌ НЕПРАВИЛЬНО — нічого не робить. with*() повертає НОВИЙ об’єкт.
$req->withHeader('X-Foo', 'bar');
$req->withAttribute('user', $user);

// ✅ ПРАВИЛЬНО — захопіть новий екземпляр.
$req = $req->withHeader('X-Foo', 'bar');
$req = $req->withAttribute('user', $user);
```

Якщо ви викликаєте `$req->withFoo(...)` й ігноруєте повернене значення, **нічого не змінюється**, бо нижчележний об’єкт незмінний. Це правило застосовне до кожного методу PSR-7, не лише до методів Lift.

Плавний ланцюжок працює чудово, бо кожен виклик повертає новий екземпляр:

```php
$req = $req
    ->withHeader('X-Trace', $traceId)
    ->withAttribute('user', $user)
    ->withAttribute('start', microtime(true));
```

## Менш поширені методи PSR-7, які можуть знадобитися

```php
$req->getProtocolVersion();        // '1.1', '2.0'
$req->getRequestTarget();          // '/users/42?page=1'
$req->withMethod('POST');
$req->withUri($newUri);            // повертає клон із новим URI
$req->withQueryParams(['x' => 1]); // замінити query
$req->withParsedBody($newBody);    // замінити тіло
$req->withoutAttribute('user');
```

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `$req->json()` повертає `[]` для тіла JSON | Невірний/відсутній заголовок `Content-Type: application/json` | Змусьте клієнт надсилати правильний заголовок. |
| `$req->input('name')` дорівнює `null`, але поле є в URL | `input()` читає лише тіло — використовуйте `query('name')` | Використовуйте правильний читач. |
| Виклик `withFoo(...)` «не працює» | Ви не присвоїли результат назад | `$req = $req->withFoo(...)`. |
| `$req->file('avatar')` дорівнює `null` | У формі відсутній `enctype="multipart/form-data"` | Додайте enctype. |
| `$req->param('id')` дорівнює `'42'`, а не `42` | Усі параметри маршруту — рядки | Приведіть тип: `(int) $req->param('id')`. |
| Читання тіла двічі дає порожнечу | Потік `input` PHP неперемотуваний у деяких SAPI | Або закешуйте `$raw = (string) $req->getBody();` один раз і повторно використовуйте, або використовуйте `$req->json()` Lift (він перечитує внутрішньо). |

## Шпаргалка

```php
// Ввід
$req->param('id');         // маршрут
$req->query('page', 1);    // рядок запиту
$req->input('name');       // поле тіла (форма або JSON)
$req->json();              // усе тіло JSON як масив
$req->file('avatar');      // ?UploadedFileInterface
$req->cookie('session');

// Огляд
$req->getMethod();
$req->getUri()->getPath();
$req->getHeaderLine('Authorization');
$req->isJson() / wantsJson() / isMethod('POST');

// Валідація
$data = $req->validate(['email' => 'required|email']);

// Middleware → обробник
$req = $req->withAttribute('user', $user);
$user = $req->getAttribute('user');

// PSR-7 (завжди присвоюйте результат!)
$req = $req->withMethod('POST')->withHeader('X-Foo', 'bar');
```

[Response →](response)
