---
layout: page
title: HTTP-клієнт
nav_order: 15
---

# HTTP-клієнт

`Lift\Http\HttpClient` — це невеликий, незмінний, плавний клієнт для виконання **вихідних** HTTP-запитів. Він використовує cURL, коли той доступний, відкочується до stream-обгорток PHP, коли ні, і дає вам помічники для JSON, повторні спроби, таймаути, basic-автентифікацію та bearer-токени «з коробки».

> Ментальна модель: кожен `with*()` повертає *новий* клієнт, тож ви можете налаштувати базовий клієнт один раз (таймаути, автентифікація, базові заголовки) і повторно використовувати його між викликами. Методи-дієслова (`->get()`, `->post()` тощо) фактично запускають запит і повертають `HttpClientResponse`.

## Демо за п’ять секунд

```php
use Lift\Http\HttpClient;

$client = HttpClient::new()->timeout(10);

$response = $client->get('https://api.example.com/users/1');

if ($response->ok()) {
    $user = $response->json();
}
```

`HttpClient::new()` — це фабрика. `$response` — це `HttpClientResponse`.

## Налаштування клієнта

Усі конфігуратори повертають **клон** — вони не мутують оригінал:

```php
$base = HttpClient::new()
    ->timeout(10)
    ->retry(3)                              // повторювати 5xx до 3 спроб
    ->asJson()                              // Content-Type і Accept = application/json
    ->withHeaders(['User-Agent' => 'MyApp/1.0'])
    ->withToken('Bearer', $jwt);            // Authorization: Bearer <token>
```

| Метод                             | Ефект                                           |
|-----------------------------------|-------------------------------------------------|
| `timeout(int $seconds)`           | Жорсткий таймаут (з’єднання + читання). За замовчуванням 30 с. |
| `retry(int $times)`               | Повтор за 5xx — усього спроб, включно з першою. |
| `asJson()`                        | Встановлює `Content-Type` і `Accept` у JSON.    |
| `withHeaders(array $headers)`     | Злити заголовки в базовий набір.                |
| `withToken('Bearer', $token)`     | `Authorization: Bearer <token>`.                |
| `withBasicAuth($user, $pass)`     | HTTP Basic-автентифікація.                      |
| `withoutVerifying()`              | Пропустити перевірку TLS-сертифіката (ЛИШЕ DEV). |
| `withoutRedirecting()`            | Не слідувати редиректам `Location:`.            |

> Клас незмінний — `$base->timeout(5)` не змінює `$base`. Перепризначте або зчіплюйте:
> `$client = $base->timeout(5);`

## Надсилання запитів

Методи у формі дієслів роблять рівно те, що ви й очікуєте:

```php
$client->get('https://api.example.com/users');
$client->get('https://api.example.com/users', query: ['page' => 2]); // /users?page=2

$client->post  ('https://api.example.com/users', ['name' => 'Alice']);   // кодує тіло в JSON
$client->put   ('https://api.example.com/users/1', ['name' => 'Bobby']);
$client->patch ('https://api.example.com/users/1', ['name' => 'Carol']);
$client->delete('https://api.example.com/users/1');
$client->head  ('https://api.example.com/users/1');
```

Аргументи тіла:

| Тип           | Надсилається як                                      |
|---------------|------------------------------------------------------|
| `array`/`object` | `application/json` (авто-кодується)              |
| `string`      | Сирі байти (ви задаєте `Content-Type` самі)          |
| `null`        | Без тіла                                             |

Власні заголовки на виклик (зливаються поверх базових заголовків клієнта):

```php
$client->post($url, $payload, headers: ['X-Idempotency-Key' => $key]);
```

## Читання відповіді

```php
$response = $client->get($url);

$response->status();              // 200
$response->body();                // сирий рядок тіла відповіді
$response->json();                // декодований масив (викидає RuntimeException за не-JSON)
$response->header('X-Foo');       // перше значення, регістронезалежно
$response->headerValues('X-Foo'); // усі значення
$response->headers();             // повна карта

$response->ok();          // 2xx
$response->failed();      // 4xx або 5xx
$response->clientError(); // 4xx
$response->serverError(); // 5xx

$response->throw();       // викинути RuntimeException за 4xx/5xx (зчіплювано)
```

`throw()` + `json()` — акуратна ідіома:

```php
$user = $client->get($url)->throw()->json();
```

## Повторні спроби

```php
$client = HttpClient::new()->retry(4);   // до 4 спроб усього за 5xx
```

Поведінка:

- Спрацьовує за `serverError()` (статус ≥ 500).
- Чекає `100 мс` між спробами.
- **Не** повторює за 4xx — це помилки клієнта, повтор не допоможе.
- **Не** повторює за помилок з’єднання, що викидають `RuntimeException` (помилки cURL, DNS тощо). Для них загортайте у власний try/catch.

Для експоненційного backoff, jitter чи circuit breaking нашаровуйте власний цикл:

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

## Автентифікація

```php
// Bearer JWT / API-ключ
$client->withToken('Bearer', $jwt);

// Basic
$client->withBasicAuth('user', 'pass');

// Власна схема
$client->withHeaders(['Authorization' => 'Signature keyId=...,algorithm=hs2019,signature=...']);
```

## SSL / TLS

```php
// Продакшен — залиште перевірку УВІМКНЕНОЮ (за замовчуванням)
$client = HttpClient::new();

// Самопідписаний dev-сертифікат — вимикайте перевірку ЛИШЕ локально
$dev = HttpClient::new()->withoutVerifying();
```

Ніколи не деплойте `withoutVerifying()` у продакшен — це вимикає перевірку сертифіката.

## Практичні рецепти

### Клієнт JSON API

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

### Доставка вебхука з ідемпотентністю

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

### Завантаження файлу (multipart)

Клієнт не постачає білдер multipart — він рідко потрібен для роботи сервіс-сервіс. Для разових випадків:

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

### Впровадження через DI-контейнер

Зареєструйте налаштований клієнт один раз і впроваджуйте його всюди:

```php
use Lift\Http\HttpClient;

$app->singleton(HttpClient::class, fn() => HttpClient::new()
    ->timeout(10)
    ->retry(2)
    ->asJson());

// Тепер у будь-якому контролері / сервісі:
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

Зверніть увагу: `withToken(...)` повертає клон — синглтон у контейнері не зачеплено, тож повторно використовувати його з багатьох сервісів безпечно.

## Тестування коду, що використовує клієнт

Клас `final` (без легкого спадкування). Рекомендований патерн — залежати від інтерфейсу, який ви контролюєте, із крихітним адаптером:

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

// У тестах:
$app->instance(HttpFetcher::class, new class implements HttpFetcher {
    public function get(string $url): array { return ['stubbed' => true]; }
});
```

Як варіант, спрямуйте справжній `HttpClient` на локальний тестовий сервер (`php -S 127.0.0.1:9999 ...`) для інтеграційних тестів.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `cURL error [60]: SSL certificate problem` | Самопідписаний / застарілий CA-набір | Виправте CA-набір або використовуйте `withoutVerifying()` (лише dev). |
| Зависає назавжди | Таймаут не налаштовано, сервер повільний | `->timeout(N)` — ніколи не довіряйте віддаленій стороні бути своєчасною. |
| `Response body is not valid JSON` | Ендпоінт повернув HTML або порожнечу | Не викликайте `->json()` — спершу перевірте `->ok()` або використовуйте `->body()`. |
| Повтори не спрацьовують | Статус був 4xx, не 5xx; або `retry(0)` | `retry()` обробляє лише 5xx. Пишіть свій цикл для 4xx. |
| Bearer-токен витікає не в той сервіс | Розділення одного налаштованого клієнта між хостами | Будуйте клони на хост (`$github = $base->withToken(...);`). |
| `withTimeout()` «не спрацював» | Конфігуратор повертає клон, який ви відкинули | `$client = $client->timeout(5);`. |

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

// Відповідь
$response->status() / ok() / failed() / clientError() / serverError();
$response->body() / json() / header($n) / headerValues($n);
$response->throw();
```

[Form requests →](form-requests)
