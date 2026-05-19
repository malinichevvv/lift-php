---
layout: page
title: Сесії
nav_order: 11
---

# Сесії

**Сесія** — це серверний набір даних на кожного користувача, який зберігається між запитами. Система сесій Lift:

- **На основі драйверів** — драйвери для файлів, бази даних, Redis, Memcached або в пам’яті.
- **Керується cookie** — у cookie живе лише непрозорий ідентифікатор сесії; дані залишаються на сервері.
- **Дружня до PSR-15** — сесія надається як атрибут запиту, тож ваші обробники залишаються тестовними.

> Ментальна модель: `SessionMiddleware` читає ідентифікатор сесії з cookie, завантажує дані через підкладкове сховище, прикріплює об’єкт `Session` до запиту, потім записує будь-які зміни назад на виході.

## Найпростіше налаштування

Для прототипів — файлові сесії, база даних не потрібна:

```php
use Lift\App;
use Lift\Http\Session\FileSessionStore;
use Lift\Http\Session\Session;
use Lift\Http\Session\SessionMiddleware;

$app = new App();

$store   = new FileSessionStore(__DIR__ . '/../storage/sessions');
$session = new Session($store, lifetime: 7200, cookieName: 'my_session');

$app->use(new SessionMiddleware($session));

// У будь-якому обробнику:
$app->get('/me', function (Request $req) {
    $session = $req->getAttribute('session');   // Lift\Http\Session\Session
    return ['user_id' => $session->get('user_id')];
});
```

Переконайтеся, що `storage/sessions/` існує і доступний для запису користувачу вебсервера.

## Що робить middleware на кожен запит

1. Читає ідентифікатор сесії з cookie `my_session` (або генерує новий, якщо відсутній).
2. Гідрує сесію викликом `Store::read($id)`.
3. Прикріплює об’єкт `Session` до запиту як атрибут `'session'`.
4. Викликає ваш обробник.
5. У блоці `finally`: старить flash-дані, потім записує через `Store::write($id, …, ttl)`.
6. Додає заголовок `Set-Cookie`, щоб браузер зберіг той самий ідентифікатор наступного разу.

Навіть коли ваш обробник викидає виняток, сесія все одно зберігається (крок 5).

## Читання і запис

Після прикріплення API `Session` малий і очевидний:

```php
$session = $req->getAttribute('session');

$session->get('key', $default = null);
$session->set('key', $value);
$session->has('key');                  // bool
$session->pull('key');                 // get + delete за один виклик
$session->forget('key', 'another');    // видалити один або кілька ключів
$session->all();                       // увесь масив даних
```

Зчіплювано:

```php
$session
    ->set('user_id', 42)
    ->set('last_seen', time());
```

## Flash-повідомлення

**Flash-повідомлення** — це значення, яке живе рівно один додатковий запит — ідеально для сповіщень *«дію виконано»*, що показуються після редиректу.

```php
// В обробнику, який обробив POST форми:
$session->flash('notice', 'User created.');
return Response::redirect('/users');

// На сторінці /users після редиректу:
$notice = $session->pull('notice');     // 'User created.' під час першого читання, потім зникає
```

Як це працює: `flash()` записує значення звичайним чином *і* позначає ключ у `_flash_new`. Після виконання обробника `ageFlashData()` переміщує `_flash_new` → `_flash_old`, тож значення переживає ще один запит. Під час *наступного* виклику `ageFlashData()` усе в `_flash_old` видаляється.

## Регенерація ідентифікатора сесії

**Завжди** регенеруйте ідентифікатор сесії одразу після зміни привілеїв (вхід, підвищення ролі), щоб запобігти атакам фіксації сесії:

```php
$app->post('/login', function (Request $req) {
    $session = $req->getAttribute('session');

    // …автентифікувати користувача…

    $session->regenerate();                     // ідентифікатор ротовано, стара сесія видалена зі сховища
    $session->set('user_id', $user->id);

    return Response::redirect('/dashboard');
});
```

Передайте `$deleteOldSession: false`, якщо хочете зберегти старі дані доступними десь ще — майже ніколи не правильний вибір.

> **Починаючи з 1.2.1:** як міра ешелонованого захисту, коли ідентифікатор сесії приходить із клієнтської cookie, а сховище не містить сесії під ним, `start()` карбує свіжий ідентифікатор замість прийняття наданого клієнтом значення. Це не замінює виклик `regenerate()` під час входу — зловмисник усе ще може зафіксувати *валідну* сесію до автентифікації — але це зупиняє пряме прийняття фіксованого невідомого ідентифікатора.

## Знищення сесії (вихід)

```php
$app->post('/logout', function (Request $req) {
    $req->getAttribute('session')->destroy();
    return Response::redirect('/');
});
```

`destroy()` очищає дані й видаляє запис зі сховища.

## Доступні драйвери

Усі драйвери реалізують `SessionStoreInterface`. Обирайте один залежно від того, де хочете зберігати дані.

### `FileSessionStore`

```php
new FileSessionStore(__DIR__ . '/../storage/sessions');
```

Зберігає один файл на ідентифікатор сесії. Підходить для односерверних, низьконавантажених застосунків. Запускайте періодичну задачу GC (`store->gc(7200)`), щоб минулі файли видалялися — або запускайте її вбудовано на початку кожного запиту, якщо вам не важливі кілька мс затримки.

### `DatabaseSessionStore`

```php
use Lift\Database\Connection;
use Lift\Http\Session\DatabaseSessionStore;

$db = Connection::fromConfig([...]);

// Створіть таблицю `sessions` один раз (або запустіть `lift migrate`, якщо згенерували міграцію):
(new \Lift\Database\Migrator($db, '...'))->createSessionsTable();

new DatabaseSessionStore($db, table: 'sessions');
```

Переживає між серверами. Найповільніший із чотирьох (кожне читання/запис — це SQL-звернення).

### `RedisSessionStore`

```php
use Lift\Http\Session\RedisSessionStore;
use Lift\Redis\RedisClient;

$redis = new RedisClient(host: 'redis', port: 6379);
new RedisSessionStore($redis, prefix: 'sess:');
```

Нативний TTL, доступ за частки мілісекунди. Вибір за замовчуванням для будь-якого горизонтально масштабованого розгортання.

### `MemcachedSessionStore`

```php
new MemcachedSessionStore($memcached);  // екземпляр ext-memcached
```

Як Redis, але використовує Memcached. Не має персистентності — годиться для сесій, але не для черг.

### `ArraySessionStore`

```php
new ArraySessionStore();
```

Лише в пам’яті, втрачається під час завершення процесу. Ідеально для [тестів](testing).

## Власні сховища

Реалізуйте `Lift\Http\Session\SessionStoreInterface`:

```php
interface SessionStoreInterface
{
    public function read(string $id): ?string;
    public function write(string $id, string $payload, int $ttl): void;
    public function destroy(string $id): void;
    public function gc(int $maxLifetime): void;
}
```

`$payload` — це непрозорий PHP-серіалізований рядок — ваше сховище поводиться з ним як із blob.

## Атрибути cookie

Коли middleware записує cookie, він використовує ці значення за замовчуванням:

| Атрибут      | За замовчуванням                | Перевизначення                                |
|--------------|---------------------------------|-----------------------------------------------|
| `Path`       | `/`                             | жорстко прописаний                            |
| `HttpOnly`   | завжди                          | жорстко прописаний                            |
| `SameSite`   | `Lax`                           | жорстко прописаний                            |
| `Max-Age`    | `$lifetime` (за замовчуванням 7200 с)| `new Session($store, lifetime: …)`       |
| `Secure`     | лише по HTTPS                   | автовизначається з `$req->getUri()->getScheme()` |

Якщо вам потрібні інші атрибути cookie (наприклад, `SameSite=Strict`, батьківський домен тощо), створіть власний middleware або успадкуйте `SessionMiddleware`.

## Чек-лист безпеки

- ✅ Завжди використовуйте HTTPS у продакшені. Cookie сесії — найкритичніша для безпеки частина вашого стека.
- ✅ Викликайте `$session->regenerate()` під час входу / зміни привілеїв.
- ✅ Викликайте `$session->destroy()` під час виходу.
- ✅ Для чутливих даних **не** кладіть їх у сесію — лише непрозорий ідентифікатор користувача. Решту шукайте на сервері на кожному запиті.
- ✅ Задайте розумний `lifetime`. 2 години — за замовчуванням; 30 хвилин безпечніше для адмін-областей.
- ❌ Не серіалізуйте об’єкти із секретами в сесію — передайте білий список дозволених класів або зберігайте лише ідентифікатори:
  ```php
  new Session($store, allowedClasses: false);          // жодних об’єктів, лише скаляри
  new Session($store, allowedClasses: [Money::class]); // явний список дозволених
  ```

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Сесія порожня на кожному запиті | Middleware не зареєстровано або невірне ім’я cookie | `$app->use(new SessionMiddleware($session));` і перевірте `cookieName`. |
| Вхід працює локально, але не в продакшені | Установлено прапор `Secure` cookie, але ви на HTTP | Використовуйте HTTPS або налаштуйте зворотний проксі з термінацією TLS. |
| Дані втрачаються між двома серверами | Файлове сховище + кілька серверів застосунку | Перейдіть на Redis/БД. |
| Попередження безпеки `unserialize` | Ви зберегли об’єкт, чий клас більше не завантажуваний | Використовуйте `allowedClasses: false` і зберігайте лише скаляри. |
| Flash-повідомлення не з’являється | Ви викликали `flash()`, потім прочитали його на **тому самому** запиті | Flash для *наступного* запиту — спершу редирект, потім читання. |
| Сесія «розлогінена» під час POST | CSRF-middleware регенерував ідентифікатор; або ви повторно використали старе посилання `$session` після `regenerate()` | Перечитайте через `$req->getAttribute('session')` після чутливих змін. |

## Шпаргалка

```php
// Завантаження
$store   = new FileSessionStore($path);             // або Redis/БД/Memcached
$session = new Session($store, lifetime: 7200);
$app->use(new SessionMiddleware($session));

// Використання
$session = $req->getAttribute('session');
$session->set('user_id', 42);
$session->get('user_id');
$session->pull('flash');
$session->flash('notice', 'OK');
$session->regenerate();    // після входу
$session->destroy();       // під час виходу
```

[Form requests →](form-requests)
