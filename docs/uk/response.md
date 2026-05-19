---
layout: page
title: Response
nav_order: 8
---

# Response

`Lift\Http\Response` — це незмінний об’єкт HTTP-відповіді. Він реалізує `Psr\Http\Message\ResponseInterface` і надає фабричні методи для частих випадків (JSON, HTML, текст, редирект, без вмісту), а також помічники для cookie та плавний білдер.

> Ментальна модель: зберіть `Response`, поверніть його з обробника — Lift надішле його клієнту. Як і `Request`, він незмінний — кожен метод `with*()` повертає **новий** екземпляр.

## Найкоротша можлива відповідь

```php
$app->get('/', fn() => Response::json(['hello' => 'world']));
```

Ось і все. Якщо вам не потрібно задавати власні заголовки чи коди стану, фабричні методи — найчистіший API.

## Фабричні методи

### `Response::json($data, $status = 200, $flags = ...)`

Надсилає масив/об’єкт як JSON із `Content-Type: application/json; charset=utf-8`.

```php
Response::json(['status' => 'ok']);              // 200 OK
Response::json(['error' => 'Conflict'], 409);    // власний статус
Response::json($data, 200, JSON_PRETTY_PRINT);   // власні прапори кодування
```

Прапори за замовчуванням включають `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` (вони майже завжди вам потрібні). Помилки кодування викидають `JsonException` — ніколи не дають мовчки зіпсований вивід.

### `Response::html($content, $status = 200)`

```php
Response::html('<h1>Hello</h1>');
Response::html($view->render('home'), 200);
```

`Content-Type: text/html; charset=utf-8` встановлюється автоматично.

### `Response::text($content, $status = 200)`

```php
Response::text('pong');
Response::text("Hello, $name", 200);
```

`Content-Type: text/plain; charset=utf-8`.

### `Response::redirect($url, $status = 302, $headers = [])`

```php
Response::redirect('/login');                    // 302 Found
Response::redirect('/new-home', 301);            // 301 Moved Permanently
Response::redirect('/after-post', 303);          // 303 See Other  (патерн POST → GET)
Response::redirect('/short-cache', 307);         // 307 Temporary Redirect (зберігає метод)
Response::redirect('/forever', 308);             // 308 Permanent Redirect (зберігає метод)
```

Третій аргумент `$headers` підмішує додаткові заголовки у відповідь-редирект:

```php
// Редирект і очищення cookie за один раз
Response::redirect('/login', 302, ['Clear-Site-Data' => '"cookies"']);

// Редирект із власним керуванням кешем
Response::redirect('/new-home', 301, ['Cache-Control' => 'no-store']);
```

Можна також зчепити `->withHeader(...)` / `->withCookie(...)` на результаті:

```php
return Response::redirect('/dashboard')
    ->withCookie('flash', 'Welcome back!');
```

### `Response::noContent()`

```php
return Response::noContent();   // 204, порожнє тіло
```

Використовуйте, коли DELETE / PUT тощо успішні, але повертати нічого.

## Плавний білдер (у стилі PSR-7)

Для всього, що не покривають фабрики, використовуйте ланцюжки `with*()`. **Кожен виклик повертає новий екземпляр**:

```php
return (new Response())
    ->withStatus(201)
    ->withHeader('Location', '/users/42')
    ->withHeader('X-Request-Id', $id)
    ->withJson(['id' => 42]);          // задає тіло + Content-Type, зберігає статус
    // ->withJson(['id' => 42], 201);  // необов’язковий другий аргумент перевизначає код статусу
```

Тонкий, але частий баг:

```php
// ❌ НЕПРАВИЛЬНО — withHeader повертає новий об’єкт; цей код його викидає.
$res = Response::json($data);
$res->withHeader('X-Custom', 'value');
return $res;

// ✅ ПРАВИЛЬНО
$res = Response::json($data)->withHeader('X-Custom', 'value');
return $res;
```

## Автоматичне перетворення

Якщо обробник повертає щось, що не є `Response`, Lift перетворює це за вас:

| Повернене значення    | Що ви отримуєте назад           |
|-----------------------|---------------------------------|
| `Response`            | передається без змін            |
| `array`, `object`     | `Response::json(...)`           |
| `string`              | `Response::html(...)`           |
| `null`                | `Response::noContent()` (204)   |
| будь-що інше          | `Response::text((string) $v)`   |

Тож ці два обробники ідентичні:

```php
fn() => ['ok' => true]
fn() => Response::json(['ok' => true])
```

Обирайте те, що читається краще. Підказка: явний `Response::json(...)` сяє щоразу, коли вам також потрібен код статусу або заголовок — вони все одно змушують використовувати `Response`.

## Cookie

Відповідь Lift несе першокласні помічники для cookie — вам не потрібен `setcookie()` PHP.

```php
return Response::json($user)
    ->withCookie('remember_token', $token, [
        'max_age'   => 86400 * 30,   // 30 днів
        'http_only' => true,         // за замовчуванням true
        'same_site' => 'Lax',        // за замовчуванням 'Lax'
        'secure'    => true,         // надсилати лише через HTTPS
        'path'      => '/',          // за замовчуванням '/'
        'domain'    => 'example.com',// необов’язково
    ]);
```

Швидко видалити cookie:

```php
return Response::noContent()->withoutCookie('remember_token');
```

> Прочитайте значення на наступному запиті через [`$req->cookie('remember_token')`](request#cookies).

### Довідник опцій cookie

| Ключ        | Тип    | За замовчуванням | Ефект |
|-------------|--------|------------------|-------|
| `max_age`   | int    | —                | `Max-Age=N` секунд. Рекомендується замість `expires`. |
| `expires`   | int    | —                | Unix-мітка часу. Ігнорується, коли задано `max_age`. |
| `path`      | string | `/`              | Префікс URL, до якого застосовується cookie. |
| `domain`    | string | —                | Домен cookie (контроль піддоменів). |
| `secure`    | bool   | `false`          | Додає прапор `Secure` (лише HTTPS). |
| `http_only` | bool   | `true`           | Додає прапор `HttpOnly` (без доступу з JS). |
| `same_site` | string | `Lax`            | `Strict` / `Lax` / `None`. |

## Власні коди стану

```php
return Response::json(['error' => 'I refuse to brew coffee.'], 418);

// Власна фраза причини
return (new Response())->withStatus(418, "I'm a teapot");
```

Lift знає стандартні фрази (`200 OK`, `404 Not Found` тощо) — фразу ви передаєте, лише якщо хочете її перевизначити.

## Доступ до тіла / зміна тіла

```php
$stream  = $res->getBody();              // Psr\Http\Message\StreamInterface
$content = (string) $res->getBody();     // рядок

// Замінити тіло
$newRes  = $res->withBody(\Lift\Http\Stream::fromString('hello'));
```

Більшість коду ніколи не торкається тіла напряму — фабричні методи + `withJson()` покривають 99% випадків.

## Встановлення заголовків

```php
$res = Response::json($data)
    ->withHeader('Cache-Control', 'public, max-age=3600')
    ->withHeader('X-Total-Count', '42')
    ->withAddedHeader('Set-Cookie', 'a=1')   // додати (не замінювати)
    ->withAddedHeader('Set-Cookie', 'b=2');
```

`withHeader()` **замінює** будь-яке наявне значення; `withAddedHeader()` **додає** (використовуйте, коли заголовок законно з’являється більше одного разу, як `Set-Cookie`).

## Стримінг і Server-Sent Events

Для довгоживучих відповідей (Server-Sent Events, читання хвоста логів тощо) використовуйте `SseResponse` — див. [Server-Sent Events](sse).

## Надсилання власних бінарних / файлових відповідей

Lift не постачає помічник `Response::file()` (це мікрофреймворк, а не CMS), але це однорядковий код:

```php
use Lift\Http\Stream;

$path = '/storage/exports/report.csv';

return (new Response())
    ->withHeader('Content-Type', 'text/csv')
    ->withHeader('Content-Disposition', 'attachment; filename="report.csv"')
    ->withHeader('Content-Length', (string) filesize($path))
    ->withBody(Stream::fromFile($path));
```

(Див. `Lift\Http\Stream` для фабричних методів — `fromString`, `fromFile`, `fromInput`, `empty`.)

## Шпаргалка з кодів стану

| Код  | Використовувати для                                                    |
|------|------------------------------------------------------------------------|
| 200  | OK — будь-що з тілом, що не створило ресурс                            |
| 201  | Created — POST, що створив ресурс                                      |
| 202  | Accepted — поставлено в чергу на асинхронну обробку                    |
| 204  | No Content — успішний DELETE / PUT, якому нема чого повертати           |
| 301  | Moved Permanently — старий URL, назавжди                               |
| 302  | Found — тимчасовий редирект (браузери можуть змінити метод на GET)     |
| 303  | See Other — редирект POST → GET після надсилання форми                 |
| 307  | Temporary Redirect — як 302, але зберігає HTTP-метод                   |
| 308  | Permanent Redirect — як 301, але зберігає HTTP-метод                   |
| 400  | Bad Request — некоректний запит                                        |
| 401  | Unauthorized — відсутні/невірні облікові дані                          |
| 403  | Forbidden — автентифікований, але не дозволено                         |
| 404  | Not Found                                                              |
| 405  | Method Not Allowed                                                     |
| 409  | Conflict — наприклад, дублікат унікального обмеження                    |
| 422  | Unprocessable Entity — валідація не пройшла (за замовчуванням для `ValidationException` Lift) |
| 429  | Too Many Requests — перевищено ліміт частоти                           |
| 500  | Internal Server Error                                                  |
| 502  | Bad Gateway — збій вищележного сервісу                                 |
| 503  | Service Unavailable — обслуговування / перевантаження                  |

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Заголовки не з’являються | Ви викликали `withHeader()`, але не захопили результат | Присвойте назад: `$res = $res->withHeader(...);`. |
| `JSON_THROW_ON_ERROR` зривається | Не-UTF-8 рядок у корисному навантаженні | Очистіть ввід; або `Response::json($data, 200, JSON_INVALID_UTF8_IGNORE)`. |
| Браузер ігнорує `Set-Cookie` | Невірні атрибути cookie (`Secure` по HTTP, неузгоджений домен) | Приберіть `secure` для локальної розробки, перевірте `domain`/`path`. |
| Порожній JSON `{}` замість масиву `[]` | `json_encode([])` коректно; трапляється, коли ви передаєте порожній асоціативний масив | Передавайте `list<...>` (наприклад, `array_values($items)`), коли хочете `[]`. |
| Текст статусу нічого не каже | Ви передали порожню фразу причини | Або не передавайте нічого (Lift підставить), або вкажіть свій рядок. |

## Шпаргалка

```php
// Фабрики
Response::json($data, $status?, $flags?);
Response::html($html, $status?);
Response::text($text, $status?);
Response::redirect($url, $status?);
Response::noContent();

// Плавний стиль
(new Response())
    ->withStatus(201)
    ->withHeader('X-Foo', 'bar')
    ->withAddedHeader('Set-Cookie', '...')
    ->withJson($data);

// Cookie
$res->withCookie($name, $value, [...]);
$res->withoutCookie($name);

// Тіло
$res->getBody();           // StreamInterface
(string) $res->getBody();
$res->withBody(Stream::fromString($html));
```

[DI-контейнер →](container)
