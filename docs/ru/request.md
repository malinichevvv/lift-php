---
layout: page
title: Request
nav_order: 7
---

# Request

`Lift\Http\Request` — это неизменяемый объект HTTP-запроса. Он реализует `Psr\Http\Message\ServerRequestInterface` (поэтому совместим с любым когда-либо написанным PSR-15 middleware) и добавляет поверх более дружелюбные сокращения.

> Ментальная модель: `Request` — это снимок одного входящего HTTP-вызова. Он *неизменяем*. Любой метод, который «меняет» его (`with*`), возвращает **новый** объект — оригинал остаётся нетронутым. Это сделано намеренно и является тем же правилом, которому следует любая библиотека PSR-7.

## Как получить запрос

В продакшене вы почти никогда не создаёте Request сами. Просто **укажите его тип** в обработчике, и Lift его внедрит:

```php
use Lift\Http\Request;

$app->get('/users/{id}', function (Request $req) {
    return ['id' => $req->param('id')];
});
```

Другие способы — полезны для тестов или нестандартных точек входа:

```php
// Из суперглобалей PHP ($_SERVER, $_GET, $_POST, $_FILES, $_COOKIE, php://input)
$req = Request::fromGlobals();

// Вручную (отлично для тестов)
use Lift\Http\Uri;
$req = new Request('GET', new Uri('http://localhost/users/1'));
```

## Чтение входных данных

| Источник                          | Метод                                 |
|-----------------------------------|---------------------------------------|
| Параметр маршрута `/users/{id}`   | `$req->param('id')`                   |
| Строка запроса `?page=2`          | `$req->query('page')`                 |
| Поле тела формы/JSON              | `$req->input('name')`                 |
| Всё тело JSON                     | `$req->json()` (возвращает массив)    |
| Всё разобранное тело              | `$req->getParsedBody()` (PSR-7)       |
| Cookie                            | `$req->cookie('session')`             |
| Загруженный файл                  | `$req->file('avatar')`                |
| Значение заголовка                | `$req->getHeaderLine('Accept')`       |
| Метод (`GET`, `POST`, …)          | `$req->getMethod()`                   |
| Полный объект URI                 | `$req->getUri()`                      |
| Только путь                       | `$req->getUri()->getPath()`           |
| Атрибут middleware                | `$req->getAttribute('user')`          |
| Все серверные переменные          | `$req->getServerParams()` (≈ `$_SERVER`) |

### Параметры маршрута

```php
// Маршрут: /users/{id}
$id  = $req->param('id');            // '42' (всегда строка)
$id  = (int) $req->param('id');      // 42  (приводите тип сами)
$id  = $req->param('missing', 0);    // запасное значение по умолчанию
$all = $req->params();               // ['id' => '42']

// Более низкоуровнево: сырой ассоциативный массив всех совпавших параметров маршрута
$raw = $req->getRouteParams();       // ['id' => '42']

// Полезно в тестах — построить запрос с конкретными параметрами маршрута
$req = $req->withRouteParams(['id' => '42', 'slug' => 'hello']);
```

### Строка запроса

```php
// URL: /search?q=lift&page=2
$q    = $req->query('q');             // 'lift'
$page = (int) $req->query('page', 1); // 2
$all  = $req->getQueryParams();       // ['q' => 'lift', 'page' => '2']
```

### Тело запроса

Для запросов `POST`, `PUT` и `PATCH` Lift разбирает тело автоматически на основе `Content-Type`:

- `application/json` → разбирается в массив, доступен через `$req->json()` **и** `$req->input(...)`.
- Всё остальное → используется `$_POST` (то есть `application/x-www-form-urlencoded` и `multipart/form-data`).

```php
// Form POST:  name=Alice&email=alice@example.com
$name = $req->input('name');

// JSON POST:  {"name":"Alice","email":"alice@example.com"}
$name  = $req->input('name');     // работает так же
$email = $req->json()['email'];   // и прямой доступ к массиву тоже
```

Чтобы прочитать **сырое** тело (например, подписи вебхуков, которым нужны неразобранные байты):

```php
$raw = (string) $req->getBody();
```

> Если тело нужно несколько раз или после того, как его прочитал middleware, перемотайте поток: `$req->getBody()->rewind();`.

### Загруженные файлы

```php
$avatar = $req->file('avatar');   // ?Psr\Http\Message\UploadedFileInterface

if ($avatar !== null && $avatar->getError() === UPLOAD_ERR_OK) {
    $avatar->moveTo(__DIR__ . '/../storage/uploads/' . $avatar->getClientFilename());
}
```

Доступная информация из объекта файла:

```php
$avatar->getSize();                  // байты
$avatar->getClientFilename();        // 'me.png'
$avatar->getClientMediaType();       // 'image/png'
$avatar->getError();                 // UPLOAD_ERR_OK и т. д.
$avatar->getStream();                // поток PSR-7
```

Несколько файлов под одним полем (`<input type="file" name="docs[]" multiple>`):

```php
// $req->getUploadedFiles() возвращает нормализованное дерево
foreach ($req->getUploadedFiles()['docs'] ?? [] as $file) {
    /* ... */
}
```

### Cookie

```php
$session = $req->cookie('session');
$all     = $req->getCookieParams();      // ['session' => '...', 'lang' => 'en']
```

Чтобы **установить** cookie, см. [Cookie в Response](response#cookies).

### Заголовки

```php
$accept = $req->getHeaderLine('Accept');       // 'application/json'
$lines  = $req->getHeader('Accept');           // ['application/json'] (форма списка)
$has    = $req->hasHeader('Authorization');    // bool
$all    = $req->getHeaders();                  // ['Accept' => [...], ...]
```

Имена заголовков регистронезависимы (`'Accept'` и `'accept'` оба работают).

## Помощники / сокращения

```php
$req->isJson();             // Content-Type содержит application/json
$req->wantsJson();          // Accept содержит application/json
$req->isMethod('POST');     // проверка метода (регистронезависимая)
$req->getMethod();          // 'GET' | 'POST' | …
$req->getUri()->getPath();  // '/users/42'
```

Типичное их использование:

```php
$app->get('/users/{id}', function (Request $req) use ($repo) {
    $user = $repo->find((int) $req->param('id'));

    if ($req->wantsJson()) {
        return Response::json($user);
    }
    return Response::html($view->render('users.show', ['user' => $user]));
});
```

## Валидация — способ в одну строку

`Request::validate()` объединяет параметры запроса + тело + параметры маршрута, прогоняет их через [Валидатор](validation) и либо возвращает валидированные данные, либо выбрасывает `ValidationException` (который обработчик ошибок Lift по умолчанию превращает в HTTP 422):

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

Собственные сообщения об ошибках:

```php
$data = $req->validate(
    ['email' => 'required|email'],
    ['email.required' => 'Нам нужен ваш email, чтобы отправить ссылку.'],
);
```

Передайте [Translator](localization) для локализованных сообщений:

```php
$data = $req->validate($rules, [], $translator);
```

См. [Валидацию](validation) для полного списка правил.

## Атрибуты middleware — передача данных вниз по конвейеру

Middleware может прикреплять произвольные значения к запросу, а обработчик их читает. Общепринятый носитель для «аутентифицированного пользователя», «claims из JWT», «идентификатора запроса» и т. п.

```php
// В middleware:
$req = $req->withAttribute('user', $authenticatedUser);
return $handler->handle($req);

// В обработчике:
$user = $req->getAttribute('user');           // null, если не установлено
$user = $req->getAttribute('user', $default); // со значением по умолчанию
$all  = $req->getAttributes();
```

Атрибуты — *на каждый запрос*, никогда не разделяются и исчезают, когда запрос завершается. Они **не** являются шиной событий и **не** персистентны — для этого используйте [События](events) или [Сессии](sessions).

## Неизменяемость PSR-7

Самая частая ошибка при начале работы с PSR-7:

```php
// ❌ НЕПРАВИЛЬНО — ничего не делает. with*() возвращает НОВЫЙ объект.
$req->withHeader('X-Foo', 'bar');
$req->withAttribute('user', $user);

// ✅ ПРАВИЛЬНО — захватите новый экземпляр.
$req = $req->withHeader('X-Foo', 'bar');
$req = $req->withAttribute('user', $user);
```

Если вы вызываете `$req->withFoo(...)` и игнорируете возвращаемое значение, **ничего не меняется**, потому что нижележащий объект неизменяем. Это правило применимо к каждому методу PSR-7, не только к методам Lift.

Текучая цепочка работает прекрасно, потому что каждый вызов возвращает новый экземпляр:

```php
$req = $req
    ->withHeader('X-Trace', $traceId)
    ->withAttribute('user', $user)
    ->withAttribute('start', microtime(true));
```

## Менее распространённые методы PSR-7, которые могут понадобиться

```php
$req->getProtocolVersion();        // '1.1', '2.0'
$req->getRequestTarget();          // '/users/42?page=1'
$req->withMethod('POST');
$req->withUri($newUri);            // возвращает клон с новым URI
$req->withQueryParams(['x' => 1]); // заменить query
$req->withParsedBody($newBody);    // заменить тело
$req->withoutAttribute('user');
```

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `$req->json()` возвращает `[]` для тела JSON | Неверный/отсутствующий заголовок `Content-Type: application/json` | Заставьте клиент отправлять правильный заголовок. |
| `$req->input('name')` равно `null`, но поле есть в URL | `input()` читает только тело — используйте `query('name')` | Используйте правильный читатель. |
| Вызов `withFoo(...)` «не работает» | Вы не присвоили результат обратно | `$req = $req->withFoo(...)`. |
| `$req->file('avatar')` равно `null` | В форме отсутствует `enctype="multipart/form-data"` | Добавьте enctype. |
| `$req->param('id')` равно `'42'`, а не `42` | Все параметры маршрута — строки | Приведите тип: `(int) $req->param('id')`. |
| Чтение тела дважды даёт пустоту | Поток `input` PHP неперематываем в некоторых SAPI | Либо закешируйте `$raw = (string) $req->getBody();` один раз и переиспользуйте, либо используйте `$req->json()` Lift (он перечитывает внутренне). |

## Шпаргалка

```php
// Ввод
$req->param('id');         // маршрут
$req->query('page', 1);    // строка запроса
$req->input('name');       // поле тела (форма или JSON)
$req->json();              // всё тело JSON как массив
$req->file('avatar');      // ?UploadedFileInterface
$req->cookie('session');

// Осмотр
$req->getMethod();
$req->getUri()->getPath();
$req->getHeaderLine('Authorization');
$req->isJson() / wantsJson() / isMethod('POST');

// Валидация
$data = $req->validate(['email' => 'required|email']);

// Middleware → обработчик
$req = $req->withAttribute('user', $user);
$user = $req->getAttribute('user');

// PSR-7 (всегда присваивайте результат!)
$req = $req->withMethod('POST')->withHeader('X-Foo', 'bar');
```

[Response →](response)
