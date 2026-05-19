---
layout: page
title: Сессии
nav_order: 11
---

# Сессии

**Сессия** — это серверный набор данных на каждого пользователя, который сохраняется между запросами. Система сессий Lift:

- **На основе драйверов** — драйверы для файлов, базы данных, Redis, Memcached или в памяти.
- **Управляется cookie** — в cookie живёт только непрозрачный идентификатор сессии; данные остаются на сервере.
- **Дружественна к PSR-15** — сессия предоставляется как атрибут запроса, так что ваши обработчики остаются тестируемыми.

> Ментальная модель: `SessionMiddleware` читает идентификатор сессии из cookie, загружает данные через подкладочное хранилище, прикрепляет объект `Session` к запросу, затем записывает любые изменения обратно на выходе.

## Простейшая настройка

Для прототипов — файловые сессии, база данных не требуется:

```php
use Lift\App;
use Lift\Http\Session\FileSessionStore;
use Lift\Http\Session\Session;
use Lift\Http\Session\SessionMiddleware;

$app = new App();

$store   = new FileSessionStore(__DIR__ . '/../storage/sessions');
$session = new Session($store, lifetime: 7200, cookieName: 'my_session');

$app->use(new SessionMiddleware($session));

// В любом обработчике:
$app->get('/me', function (Request $req) {
    $session = $req->getAttribute('session');   // Lift\Http\Session\Session
    return ['user_id' => $session->get('user_id')];
});
```

Убедитесь, что `storage/sessions/` существует и доступен для записи пользователю веб-сервера.

## Что делает middleware на каждый запрос

1. Читает идентификатор сессии из cookie `my_session` (или генерирует новый, если отсутствует).
2. Гидрирует сессию вызовом `Store::read($id)`.
3. Прикрепляет объект `Session` к запросу как атрибут `'session'`.
4. Вызывает ваш обработчик.
5. В блоке `finally`: старит flash-данные, затем записывает через `Store::write($id, …, ttl)`.
6. Добавляет заголовок `Set-Cookie`, чтобы браузер сохранил тот же идентификатор в следующий раз.

Даже когда ваш обработчик выбрасывает исключение, сессия всё равно сохраняется (шаг 5).

## Чтение и запись

После прикрепления API `Session` мал и очевиден:

```php
$session = $req->getAttribute('session');

$session->get('key', $default = null);
$session->set('key', $value);
$session->has('key');                  // bool
$session->pull('key');                 // get + delete за один вызов
$session->forget('key', 'another');    // удалить один или несколько ключей
$session->all();                       // весь массив данных
```

Сцепляемо:

```php
$session
    ->set('user_id', 42)
    ->set('last_seen', time());
```

## Flash-сообщения

**Flash-сообщение** — это значение, которое живёт ровно один дополнительный запрос — идеально для уведомлений *«действие успешно»*, показываемых после редиректа.

```php
// В обработчике, который обработал POST формы:
$session->flash('notice', 'User created.');
return Response::redirect('/users');

// На странице /users после редиректа:
$notice = $session->pull('notice');     // 'User created.' при первом чтении, затем пропадает
```

Как это работает: `flash()` записывает значение обычным образом *и* помечает ключ в `_flash_new`. После выполнения обработчика `ageFlashData()` перемещает `_flash_new` → `_flash_old`, так что значение переживает ещё один запрос. При *следующем* вызове `ageFlashData()` всё в `_flash_old` удаляется.

## Регенерация идентификатора сессии

**Всегда** регенерируйте идентификатор сессии сразу после изменения привилегий (вход, повышение роли), чтобы предотвратить атаки фиксации сессии:

```php
$app->post('/login', function (Request $req) {
    $session = $req->getAttribute('session');

    // …аутентифицировать пользователя…

    $session->regenerate();                     // идентификатор ротирован, старая сессия удалена из хранилища
    $session->set('user_id', $user->id);

    return Response::redirect('/dashboard');
});
```

Передайте `$deleteOldSession: false`, если хотите сохранить старые данные доступными где-то ещё — почти никогда не правильный выбор.

> **Начиная с 1.2.1:** как мера эшелонированной защиты, когда идентификатор сессии приходит из клиентской cookie, а хранилище не содержит сессии под ним, `start()` чеканит свежий идентификатор вместо принятия предоставленного клиентом значения. Это не заменяет вызов `regenerate()` при входе — атакующий всё ещё может зафиксировать *валидную* сессию до аутентификации — но это останавливает прямое принятие фиксированного неизвестного идентификатора.

## Уничтожение сессии (выход)

```php
$app->post('/logout', function (Request $req) {
    $req->getAttribute('session')->destroy();
    return Response::redirect('/');
});
```

`destroy()` очищает данные и удаляет запись из хранилища.

## Доступные драйверы

Все драйверы реализуют `SessionStoreInterface`. Выбирайте один в зависимости от того, где хотите хранить данные.

### `FileSessionStore`

```php
new FileSessionStore(__DIR__ . '/../storage/sessions');
```

Хранит один файл на идентификатор сессии. Подходит для одно-серверных, низконагруженных приложений. Запускайте периодическую задачу GC (`store->gc(7200)`), чтобы истёкшие файлы удалялись — или запускайте её встроенно в начале каждого запроса, если вам не важны несколько мс задержки.

### `DatabaseSessionStore`

```php
use Lift\Database\Connection;
use Lift\Http\Session\DatabaseSessionStore;

$db = Connection::fromConfig([...]);

// Создайте таблицу `sessions` один раз (или запустите `lift migrate`, если сгенерировали миграцию):
(new \Lift\Database\Migrator($db, '...'))->createSessionsTable();

new DatabaseSessionStore($db, table: 'sessions');
```

Переживает между серверами. Самый медленный из четырёх (каждое чтение/запись — это SQL-обращение).

### `RedisSessionStore`

```php
use Lift\Http\Session\RedisSessionStore;
use Lift\Redis\RedisClient;

$redis = new RedisClient(host: 'redis', port: 6379);
new RedisSessionStore($redis, prefix: 'sess:');
```

Нативный TTL, доступ за доли миллисекунды. Выбор по умолчанию для любого горизонтально масштабируемого развёртывания.

### `MemcachedSessionStore`

```php
new MemcachedSessionStore($memcached);  // экземпляр ext-memcached
```

Как Redis, но использует Memcached. Не имеет персистентности — годится для сессий, но не для очередей.

### `ArraySessionStore`

```php
new ArraySessionStore();
```

Только в памяти, теряется при завершении процесса. Идеально для [тестов](testing).

## Собственные хранилища

Реализуйте `Lift\Http\Session\SessionStoreInterface`:

```php
interface SessionStoreInterface
{
    public function read(string $id): ?string;
    public function write(string $id, string $payload, int $ttl): void;
    public function destroy(string $id): void;
    public function gc(int $maxLifetime): void;
}
```

`$payload` — это непрозрачная PHP-сериализованная строка — ваше хранилище обращается с ней как с blob.

## Атрибуты cookie

Когда middleware записывает cookie, он использует эти значения по умолчанию:

| Атрибут      | По умолчанию                    | Переопределение                               |
|--------------|---------------------------------|-----------------------------------------------|
| `Path`       | `/`                             | жёстко прописан                               |
| `HttpOnly`   | всегда                          | жёстко прописан                               |
| `SameSite`   | `Lax`                           | жёстко прописан                               |
| `Max-Age`    | `$lifetime` (по умолчанию 7200 с)| `new Session($store, lifetime: …)`           |
| `Secure`     | только по HTTPS                 | автоопределяется из `$req->getUri()->getScheme()` |

Если вам нужны другие атрибуты cookie (например, `SameSite=Strict`, родительский домен и т. д.), создайте собственный middleware или унаследуйте `SessionMiddleware`.

## Чек-лист безопасности

- ✅ Всегда используйте HTTPS в продакшене. Cookie сессии — самая критичная для безопасности часть вашего стека.
- ✅ Вызывайте `$session->regenerate()` при входе / изменении привилегий.
- ✅ Вызывайте `$session->destroy()` при выходе.
- ✅ Для чувствительных данных **не** кладите их в сессию — только непрозрачный идентификатор пользователя. Остальное ищите на сервере на каждом запросе.
- ✅ Задайте разумный `lifetime`. 2 часа — по умолчанию; 30 минут безопаснее для админ-областей.
- ❌ Не сериализуйте объекты с секретами в сессию — передайте белый список разрешённых классов или храните только идентификаторы:
  ```php
  new Session($store, allowedClasses: false);          // никаких объектов, только скаляры
  new Session($store, allowedClasses: [Money::class]); // явный список разрешённых
  ```

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Сессия пуста на каждом запросе | Middleware не зарегистрирован или неверное имя cookie | `$app->use(new SessionMiddleware($session));` и проверьте `cookieName`. |
| Вход работает локально, но не в продакшене | Установлен флаг `Secure` cookie, но вы на HTTP | Используйте HTTPS или настройте обратный прокси с терминацией TLS. |
| Данные теряются между двумя серверами | Файловое хранилище + несколько серверов приложения | Перейдите на Redis/БД. |
| Предупреждения безопасности `unserialize` | Вы сохранили объект, чей класс больше не загружаем | Используйте `allowedClasses: false` и храните только скаляры. |
| Flash-сообщение не появляется | Вы вызвали `flash()`, затем прочитали его на **том же** запросе | Flash для *следующего* запроса — сначала редирект, затем чтение. |
| Сессия «разлогинена» при POST | CSRF-middleware регенерировал идентификатор; или вы переиспользовали старую ссылку `$session` после `regenerate()` | Перечитайте через `$req->getAttribute('session')` после чувствительных изменений. |

## Шпаргалка

```php
// Загрузка
$store   = new FileSessionStore($path);             // или Redis/БД/Memcached
$session = new Session($store, lifetime: 7200);
$app->use(new SessionMiddleware($session));

// Использование
$session = $req->getAttribute('session');
$session->set('user_id', 42);
$session->get('user_id');
$session->pull('flash');
$session->flash('notice', 'OK');
$session->regenerate();    // после входа
$session->destroy();       // при выходе
```

[Form requests →](form-requests)
