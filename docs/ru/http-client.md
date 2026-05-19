---
layout: page
title: HTTP-клиент
nav_order: 15
---

# HTTP-клиент

`Lift\Http\HttpClient` — это небольшой, неизменяемый, текучий клиент для выполнения **исходящих** HTTP-запросов. Он использует cURL, когда тот доступен, откатывается к stream-обёрткам PHP, когда нет, и даёт вам помощники для JSON, повторные попытки, таймауты, basic-аутентификацию и bearer-токены «из коробки».

> Ментальная модель: каждый `with*()` возвращает *новый* клиент, так что вы можете настроить базовый клиент один раз (таймауты, аутентификация, базовые заголовки) и переиспользовать его между вызовами. Методы-глаголы (`->get()`, `->post()` и т. д.) фактически запускают запрос и возвращают `HttpClientResponse`.

## Демо за пять секунд

```php
use Lift\Http\HttpClient;

$client = HttpClient::new()->timeout(10);

$response = $client->get('https://api.example.com/users/1');

if ($response->ok()) {
    $user = $response->json();
}
```

`HttpClient::new()` — это фабрика. `$response` — это `HttpClientResponse`.

## Настройка клиента

Все конфигураторы возвращают **клон** — они не мутируют оригинал:

```php
$base = HttpClient::new()
    ->timeout(10)
    ->retry(3)                              // повторять 5xx до 3 попыток
    ->asJson()                              // Content-Type и Accept = application/json
    ->withHeaders(['User-Agent' => 'MyApp/1.0'])
    ->withToken('Bearer', $jwt);            // Authorization: Bearer <token>
```

| Метод                             | Эффект                                          |
|-----------------------------------|-------------------------------------------------|
| `timeout(int $seconds)`           | Жёсткий таймаут (соединение + чтение). По умолчанию 30 с. |
| `retry(int $times)`               | Повтор при 5xx — всего попыток, включая первую. |
| `asJson()`                        | Устанавливает `Content-Type` и `Accept` в JSON. |
| `withHeaders(array $headers)`     | Слить заголовки в базовый набор.                |
| `withToken('Bearer', $token)`     | `Authorization: Bearer <token>`.                |
| `withBasicAuth($user, $pass)`     | HTTP Basic-аутентификация.                      |
| `withoutVerifying()`              | Пропустить проверку TLS-сертификата (ТОЛЬКО DEV). |
| `withoutRedirecting()`            | Не следовать редиректам `Location:`.            |

> Класс неизменяем — `$base->timeout(5)` не меняет `$base`. Переприсвойте или сцепляйте:
> `$client = $base->timeout(5);`

## Отправка запросов

Методы в форме глаголов делают ровно то, что вы и ожидаете:

```php
$client->get('https://api.example.com/users');
$client->get('https://api.example.com/users', query: ['page' => 2]); // /users?page=2

$client->post  ('https://api.example.com/users', ['name' => 'Alice']);   // кодирует тело в JSON
$client->put   ('https://api.example.com/users/1', ['name' => 'Bobby']);
$client->patch ('https://api.example.com/users/1', ['name' => 'Carol']);
$client->delete('https://api.example.com/users/1');
$client->head  ('https://api.example.com/users/1');
```

Аргументы тела:

| Тип           | Отправляется как                                     |
|---------------|------------------------------------------------------|
| `array`/`object` | `application/json` (авто-кодируется)             |
| `string`      | Сырые байты (вы задаёте `Content-Type` сами)         |
| `null`        | Без тела                                             |

Собственные заголовки на вызов (сливаются поверх базовых заголовков клиента):

```php
$client->post($url, $payload, headers: ['X-Idempotency-Key' => $key]);
```

## Чтение ответа

```php
$response = $client->get($url);

$response->status();              // 200
$response->body();                // сырая строка тела ответа
$response->json();                // декодированный массив (выбрасывает RuntimeException при не-JSON)
$response->header('X-Foo');       // первое значение, регистронезависимо
$response->headerValues('X-Foo'); // все значения
$response->headers();             // полная карта

$response->ok();          // 2xx
$response->failed();      // 4xx или 5xx
$response->clientError(); // 4xx
$response->serverError(); // 5xx

$response->throw();       // выбросить RuntimeException при 4xx/5xx (сцепляемо)
```

`throw()` + `json()` — аккуратная идиома:

```php
$user = $client->get($url)->throw()->json();
```

## Повторные попытки

```php
$client = HttpClient::new()->retry(4);   // до 4 попыток всего при 5xx
```

Поведение:

- Срабатывает при `serverError()` (статус ≥ 500).
- Ждёт `100 мс` между попытками.
- **Не** повторяет при 4xx — это ошибки клиента, повтор не поможет.
- **Не** повторяет при ошибках соединения, выбрасывающих `RuntimeException` (ошибки cURL, DNS и т. д.). Для них оборачивайте в собственный try/catch.

Для экспоненциального backoff, jitter или circuit breaking наслаивайте собственный цикл:

```php
$attempt = 0;
$max = 5;
while (true) {
    try {
        $response = $client->get($url);
        if ($response->ok()) break;
    } catch (\Throwable $e) {
        if (++$attempt >= $max) throw $e;
        usleep(min(60_000_000, 100_000 * (2 ** $attempt)) + random_int(0, 50_000));
    }
}
```

## Аутентификация

```php
// Bearer JWT / API-ключ
$client->withToken('Bearer', $jwt);

// Basic
$client->withBasicAuth('user', 'pass');

// Собственная схема
$client->withHeaders(['Authorization' => 'Signature keyId=...,algorithm=hs2019,signature=...']);
```

## SSL / TLS

```php
// Продакшен — оставьте проверку ВКЛЮЧЁННОЙ (по умолчанию)
$client = HttpClient::new();

// Самоподписанный dev-сертификат — отключайте проверку ТОЛЬКО локально
$dev = HttpClient::new()->withoutVerifying();
```

Никогда не деплойте `withoutVerifying()` в продакшен — это отключает проверку сертификата.

## Практические рецепты

### Клиент JSON API

```php
$github = HttpClient::new()
    ->timeout(15)
    ->retry(3)
    ->asJson()
    ->withToken('Bearer', $_ENV['GITHUB_TOKEN'])
    ->withHeaders(['Accept' => 'application/vnd.github+json']);

$repo = $github->get('https://api.github.com/repos/malinichevvv/lift-php')->throw()->json();
$prs  = $github->get('https://api.github.com/repos/malinichevvv/lift-php/pulls', ['state' => 'open'])->json();
```

### Доставка вебхука с идемпотентностью

```php
$response = HttpClient::new()
    ->timeout(8)
    ->retry(3)
    ->asJson()
    ->post('https://merchant.example.com/webhooks/orders', $payload, [
        'X-Idempotency-Key' => $idempotencyKey,
    ]);

if ($response->failed()) {
    $queue->push(new RetryWebhook($payload, $idempotencyKey, $response->status()));
}
```

### Загрузка файла (multipart)

Клиент не поставляет билдер multipart — он редко нужен для работы сервис-сервис. Для разовых случаев:

```php
$boundary = bin2hex(random_bytes(16));
$body  = "--{$boundary}\r\n";
$body .= "Content-Disposition: form-data; name=\"file\"; filename=\"a.txt\"\r\n";
$body .= "Content-Type: text/plain\r\n\r\n";
$body .= file_get_contents('a.txt') . "\r\n";
$body .= "--{$boundary}--\r\n";

$client->post($url, $body, [
    'Content-Type' => "multipart/form-data; boundary={$boundary}",
]);
```

### Внедрение через DI-контейнер

Зарегистрируйте настроенный клиент один раз и внедряйте его везде:

```php
use Lift\Http\HttpClient;

$app->singleton(HttpClient::class, fn() => HttpClient::new()
    ->timeout(10)
    ->retry(2)
    ->asJson());

// Теперь в любом контроллере / сервисе:
class GithubService
{
    public function __construct(private readonly HttpClient $http) {}
    public function repo(string $name): array
    {
        return $this->http
            ->withToken('Bearer', $_ENV['GITHUB_TOKEN'])
            ->get("https://api.github.com/repos/{$name}")
            ->throw()
            ->json();
    }
}
```

Заметьте: `withToken(...)` возвращает клон — синглтон в контейнере не затронут, так что переиспользовать его из многих сервисов безопасно.

## Тестирование кода, использующего клиент

Класс `final` (без лёгкого наследования). Рекомендуемый паттерн — зависеть от интерфейса, который вы контролируете, с крошечным адаптером:

```php
interface HttpFetcher {
    public function get(string $url): array;
}

final class LiftHttpFetcher implements HttpFetcher
{
    public function __construct(private readonly HttpClient $http) {}
    public function get(string $url): array
    {
        return $this->http->get($url)->throw()->json();
    }
}

// В тестах:
$app->instance(HttpFetcher::class, new class implements HttpFetcher {
    public function get(string $url): array { return ['stubbed' => true]; }
});
```

Как вариант, направьте настоящий `HttpClient` на локальный тестовый сервер (`php -S 127.0.0.1:9999 ...`) для интеграционных тестов.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `cURL error [60]: SSL certificate problem` | Самоподписанный / устаревший CA-набор | Исправьте CA-набор или используйте `withoutVerifying()` (только dev). |
| Зависает навсегда | Таймаут не настроен, сервер медленный | `->timeout(N)` — никогда не доверяйте удалённой стороне быть своевременной. |
| `Response body is not valid JSON` | Эндпоинт вернул HTML или пустоту | Не вызывайте `->json()` — сначала проверьте `->ok()` или используйте `->body()`. |
| Повторы не срабатывают | Статус был 4xx, не 5xx; или `retry(0)` | `retry()` обрабатывает только 5xx. Пишите свой цикл для 4xx. |
| Bearer-токен утекает не в тот сервис | Разделение одного настроенного клиента между хостами | Стройте клоны на хост (`$github = $base->withToken(...);`). |
| `withTimeout()` «не сработал» | Конфигуратор возвращает клон, который вы отбросили | `$client = $client->timeout(5);`. |

## Шпаргалка

```php
$client = HttpClient::new()
    ->timeout(10)
    ->retry(3)
    ->asJson()
    ->withToken('Bearer', $token);

$users = $client->get($url, query: ['page' => 2])->throw()->json();
$client->post($url, ['name' => 'Alice'])->throw();
$client->delete($url);

// Ответ
$response->status() / ok() / failed() / clientError() / serverError();
$response->body() / json() / header($n) / headerValues($n);
$response->throw();
```

[Form requests →](form-requests)
