---
layout: page
title: JWT
nav_order: 25
---

# JWT — JSON Web Tokens

Lift поставляет самодостаточную реализацию JWT: кодирование/декодирование, HS256–HS512 (симметричные) и RS256–RS512 (асимметричные), текучий билдер `Claims` и готовый к использованию middleware для Bearer-аутентификации. **Ноль внешних зависимостей** — чистый PHP плюс `ext-openssl` для RSA.

> Ментальная модель: JWT — это `{header}.{payload}.{signature}` — три сегмента base64url, разделённые точками. Подпись доказывает, что полезная нагрузка не была подделана. Если вы доверяете подписи, вы доверяете полезной нагрузке.

## Когда использовать JWT

- **Безсостоятельная аутентификация API** (мобильные приложения, SPA, сервис-сервис).
- **Короткоживущие подписанные билеты** (ссылки сброса пароля, подтверждение email, вход по magic-link).
- **Доверие между сервисами** (один из ваших сервисов подписывает токен; другой проверяет его, не разделяя состояние БД).

Когда **не** использовать их:

- **Серверно-рендерящиеся приложения с session-cookie** — используйте [Сессии](sessions). Они чисто обрабатывают отзыв.
- **Хранение чувствительных данных** — полезные нагрузки JWT закодированы в base64, **не зашифрованы**. Любой, у кого есть токен, может их прочитать. (Для зашифрованных токенов см. JWE — эта библиотека его не реализует.)

## Пример за 30 секунд

```php
use Lift\Jwt\Jwt;
use Lift\Jwt\Claims;

$jwt = new Jwt(secret: $_ENV['JWT_SECRET']);

// Выпустить токен
$token = $jwt->encode(
    Claims::make()
        ->subject('user_42')
        ->expiresIn(3600)
        ->extra(['role' => 'admin'])
        ->toArray()
);

// Позже — проверить и декодировать
try {
    $payload = $jwt->decode($token);
    // $payload['sub']   === 'user_42'
    // $payload['role']  === 'admin'
} catch (\Lift\Jwt\JwtException $e) {
    // истёк, подделан, неверный ключ, искажён, …
}
```

## Алгоритмы

| Вариант enum            | Тип       | Использовать, когда                                     |
|-------------------------|-----------|---------------------------------------------------------|
| `JwtAlgorithm::HS256`   | HMAC SHA-256 | Однопроцессные приложения. И издатель, и проверяющий разделяют один секрет. |
| `JwtAlgorithm::HS384`   | HMAC SHA-384 | То же, больший дайджест. Нужно редко.                |
| `JwtAlgorithm::HS512`   | HMAC SHA-512 | То же, больший дайджест. Нужно редко.                |
| `JwtAlgorithm::RS256`   | RSA SHA-256  | **Многосервисные.** Приватный ключ подписывает; публичный проверяет. |
| `JwtAlgorithm::RS384`   | RSA SHA-384  | То же.                                                |
| `JwtAlgorithm::RS512`   | RSA SHA-512  | То же.                                                |

### Симметричные (HS*) — просто

```php
$jwt = new Jwt(
    secret: $_ENV['JWT_SECRET'],
    algo:   JwtAlgorithm::HS256,    // по умолчанию
);
```

> Секрет должен быть **не менее** 32 случайных байт. Сгенерируйте его один раз:
> `php -r 'echo base64_encode(random_bytes(64));'`

### Асимметричные (RS*) — для распределённых систем

Издатель держит **приватный** ключ. Проверяющим нужен только **публичный** ключ. Скомпрометируйте публичный ключ — ничего не произойдёт; атакующий всё равно не сможет подделать токены.

```php
// Издатель
$issuer = new Jwt(
    algo:       JwtAlgorithm::RS256,
    privateKey: file_get_contents('/keys/private.pem'),
);
$token = $issuer->encode($payload);

// Проверяющий (нужен только публичный ключ)
$verifier = new Jwt(
    algo:      JwtAlgorithm::RS256,
    publicKey: file_get_contents('/keys/public.pem'),
);
$payload = $verifier->decode($token);
```

Сгенерируйте пару ключей:

```bash
openssl genpkey -algorithm RSA -out private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -in private.pem -pubout -out public.pem
```

## Билдер `Claims`

Стандартные имена claim в JWT — трёхбуквенные коды. Текучий билдер пишет их полностью:

```php
$payload = Claims::make()
    ->subject('user_42')                              // sub
    ->issuer('https://api.example.com')               // iss
    ->audience('https://app.example.com')             // aud  (или массив)
    ->id(Uuid::v7())                                  // jti  — уникальный id токена
    ->issuedAt()                                      // iat  — по умолчанию now()
    ->expiresIn(3600)                                 // exp  — через 1 час от текущего момента
    ->notBefore(time() + 60)                          // nbf  — действителен только после этого времени
    ->extra([
        'role'  => 'admin',
        'email' => 'a@example.com',
    ])
    ->toArray();
```

Билдер можно полностью пропустить и передать сырой массив — это просто синтаксический сахар.

### Что Lift валидирует автоматически

Когда вы вызываете `$jwt->decode($token)`, он проверяет:

| Claim   | Поведение                                                          |
|---------|--------------------------------------------------------------------|
| Подпись | Всегда проверяется против настроенного ключа/секрета.              |
| `exp`   | Токен отклоняется, если `now > exp` (с допуском `leeway`).          |
| `nbf`   | Токен отклоняется, если `now < nbf` (с допуском `leeway`).          |
| `iss`   | Проверяется, только если вы настроили `issuer:` на экземпляре `Jwt`. |
| `aud`   | Проверяется, только если вы настроили `audience:` на экземпляре `Jwt`. |

Всё остальное (`sub`, собственные claim) **не** валидируется библиотекой — вы проверяете их в своём обработчике / middleware.

### Принудительная проверка issuer / audience

```php
$jwt = new Jwt(
    secret:   $_ENV['JWT_SECRET'],
    issuer:   'https://auth.example.com',
    audience: 'https://api.example.com',
);

// decode() отклонит любой токен, чьи `iss` и `aud` не совпадают
```

Это защищает вас от повторного использования токена между сервисами — токен, выпущенный *для billing API*, нельзя использовать против *admin API*.

### Расхождение часов

Если часы вашего проверяющего отстают от часов издателя на пару секунд, только что выпущенные токены могут на короткое время выглядеть «ещё не действительными». Разрешите небольшой допуск:

```php
$jwt = new Jwt(secret: $_ENV['JWT_SECRET'], leeway: 30);   // ±30 секунд
```

## Middleware

Готовая Bearer-токен аутентификация:

```php
use Lift\Jwt\JwtMiddleware;

$jwt = new Jwt(secret: $_ENV['JWT_SECRET']);
$app->use(new JwtMiddleware($jwt));

// В любом обработчике:
$app->get('/me', function (Request $req) {
    $claims = $req->getAttribute('jwt');         // декодированная полезная нагрузка
    return ['user_id' => $claims['sub']];
});
```

Что он делает:

1. Читает `Authorization: Bearer <token>`.
2. Вызывает `$jwt->decode($token)`.
3. При успехе: прикрепляет полезную нагрузку к `$req` как атрибут `'jwt'`, вызывает следующий обработчик.
4. При неудаче: возвращает JSON `401 Unauthorized` + заголовок `WWW-Authenticate: Bearer`.

### Пропуск публичных маршрутов

Middleware может игнорировать точные пути — полезно, когда вы монтируете его глобально:

```php
$app->use(new JwtMiddleware(
    jwt:    $jwt,
    except: ['/login', '/register', '/healthz', '/openapi.json'],
));
```

Для более гибкого пропуска (regex-пути, публичные группы) применяйте погруппово вместо глобального:

```php
$app->group('/api', function ($g) use ($jwt) {
    $g->get('/me', /* … */);
    $g->get('/orders', /* … */);
})->middleware(new JwtMiddleware($jwt));
```

### Собственное имя атрибута

```php
new JwtMiddleware($jwt, attribute: 'auth');
// позже: $req->getAttribute('auth')
```

## Сквозной пример: вход + защищённый маршрут

```php
use Lift\App;
use Lift\Crypto\Hasher;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Jwt\Claims;
use Lift\Jwt\Jwt;
use Lift\Jwt\JwtMiddleware;

$app = new App();

$jwt    = new Jwt(secret: $_ENV['JWT_SECRET']);
$hasher = new Hasher();

$app->instance(Jwt::class, $jwt);
$app->instance(Hasher::class, $hasher);

// 1. Вход — публичный
$app->post('/login', function (Request $req) use ($jwt, $hasher, $db) {
    $data = $req->validate([
        'email'    => 'required|email',
        'password' => 'required|string',
    ]);

    $user = $db->table('users')->where('email', $data['email'])->first();
    if ($user === null || !$hasher->verify($data['password'], $user['password_hash'])) {
        return Response::json(['error' => 'Invalid credentials'], 401);
    }

    $token = $jwt->encode(
        Claims::make()
            ->subject((string) $user['id'])
            ->expiresIn(3600)
            ->extra(['email' => $user['email'], 'role' => $user['role']])
            ->toArray()
    );

    return Response::json(['token' => $token, 'expires_in' => 3600]);
});

// 2. Защитить всё под /api с помощью middleware
$app->group('/api', function ($g) {
    $g->get('/me', function (Request $req) {
        $claims = $req->getAttribute('jwt');
        return ['id' => $claims['sub'], 'email' => $claims['email']];
    });

    $g->get('/admin/stats', function (Request $req) {
        $claims = $req->getAttribute('jwt');
        if (($claims['role'] ?? '') !== 'admin') {
            throw new \Lift\Exception\ForbiddenException();
        }
        return ['users' => 42, 'orders' => 1337];
    });
})->middleware(new JwtMiddleware($jwt));

$app->run();
```

Использование на клиенте:

```bash
# 1. Вход
TOKEN=$(curl -s -X POST http://localhost:8000/login \
    -H 'Content-Type: application/json' \
    -d '{"email":"a@b.c","password":"hunter2"}' | jq -r .token)

# 2. Использовать токен
curl http://localhost:8000/api/me \
    -H "Authorization: Bearer $TOKEN"
```

## Refresh-токены

JWT безсостоятелен — раз выпущенный токен нельзя «отозвать» до его истечения. Стандартное решение:

- **Access-токены** — короткоживущие (5–15 мин) JWT.
- **Refresh-токены** — долгоживущие (дни/недели) непрозрачные идентификаторы, хранящиеся в вашей БД. Когда access-токен истекает, клиент обменивает refresh-токен на новый access-токен. **Вы можете отозвать refresh-токен**, удалив строку.

Набросок:

```php
$app->post('/refresh', function (Request $req) use ($jwt, $db) {
    $data = $req->validate(['refresh_token' => 'required|string']);

    $row = $db->table('refresh_tokens')
        ->where('token', hash('sha256', $data['refresh_token']))
        ->where('expires_at', '>', date('Y-m-d H:i:s'))
        ->first();

    if ($row === null) {
        throw new \Lift\Exception\UnauthorizedException("Bad refresh token");
    }

    // Опционально: ротация — удалить старый refresh-токен, выпустить новую пару.
    $access = $jwt->encode(Claims::make()->subject((string)$row['user_id'])->expiresIn(900)->toArray());
    return ['access_token' => $access];
});
```

## Хранение токенов на стороне клиента

**SPA / браузер**: помещайте access-токен в **память** (JS-переменная, никогда не `localStorage`). Refresh-токен — в cookie `HttpOnly; Secure; SameSite=Strict`. Эта комбинация устойчива к XSS (access-токен недоступен вредоносным скриптам) и к CSRF (cookie не может быть выкрадена другими сайтами).

**Мобильные**: защищённое хранилище платформы (Keychain / Keystore).

**Сервис-сервис**: в памяти процесса; перечитывайте из менеджера секретов при ротации.

## Чек-лист безопасности

- ✅ HMAC-секрет — **не менее 32 случайных байт**, хранится в env-переменных / менеджере секретов, **никогда в git**.
- ✅ Короткий `exp` (≤ 1 час для access-токенов).
- ✅ Всегда валидируйте `iss` и `aud`, когда у вас несколько сервисов / клиентов.
- ✅ Всегда фиксируйте `algo` на стороне сервера — никогда не позволяйте заголовку `alg` токена решать.
- ✅ Ротируйте RSA-ключи минимум ежегодно. Поддерживайте несколько публичных ключей в окне ротации (постройте небольшой `KeySelector`, если это нужно).
- ❌ **Никогда** не доверяйте полезной нагрузке до того, как `decode()` успешно вернётся.
- ❌ **Никогда** не помещайте пароли, сырые персональные данные или session-cookie внутрь JWT — они видны в открытом виде.
- ❌ **Никогда** не используйте `alg: none` — enum Lift его даже не включает, но имейте в виду, что некоторые библиотеки да.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `Token has expired` сразу после выпуска | Часы издателя и проверяющего расходятся | Установите `leeway: 30` на проверяющем. |
| 401 на каждом запросе после деплоя | `JWT_SECRET` изменился | Ротируйте осторожно: токены, выпущенные со старым секретом, умирают мгновенно. Используйте rolling-деплои / поддержку двух ключей. |
| `Missing or malformed Authorization header.` | Заголовок `Bearer token` (правильно), но клиент забыл пробел или использует `bearer` (нижний регистр OK) | Проверка Lift регистрозависима для `Bearer ` — убедитесь, что клиент отправляет ровно этот префикс. |
| Поле `role` токена обновлено на сервере, но пользователь всё ещё видит старую роль | JWT *безсостоятелен*; старые токены работают до `exp` | Короткий `exp` + refresh-токены; или поддерживайте серверную «версию сессии», которую клиент должен возвращать. |
| RSA-проверка возвращает `false` для валидного токена | Неверный ключ или PEM с переводами строк CRLF | Переэкспортируйте ключи; проверьте через `openssl rsa -in private.pem -check`. |
| Токен огромный | Вы напихали в него много claim | Держите полезные нагрузки крошечными (sub + несколько id). Токен отправляется на каждом запросе. |

## Шпаргалка

```php
// Настроить
$jwt = new Jwt(
    secret:   $_ENV['JWT_SECRET'],
    algo:     JwtAlgorithm::HS256,
    leeway:   30,
    issuer:   'https://auth.example.com',
    audience: 'https://api.example.com',
);

// Выпустить
$token = $jwt->encode(
    Claims::make()->subject('user_42')->expiresIn(3600)->extra([...])->toArray()
);

// Проверить
try { $payload = $jwt->decode($token); }
catch (JwtException $e) { /* 401 */ }

// Middleware
$app->use(new JwtMiddleware($jwt, except: ['/login']));
$claims = $req->getAttribute('jwt');
```

[Шифрование →](crypto)
