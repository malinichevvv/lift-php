---
layout: page
title: JWT
nav_order: 25
---

# JWT — JSON Web Tokens

Lift постачає самодостатню реалізацію JWT: кодування/декодування, HS256–HS512 (симетричні) і RS256–RS512 (асиметричні), плавний білдер `Claims` та готовий до використання middleware для Bearer-автентифікації. **Нуль зовнішніх залежностей** — чистий PHP плюс `ext-openssl` для RSA.

> Ментальна модель: JWT — це `{header}.{payload}.{signature}` — три сегменти base64url, розділені крапками. Підпис доводить, що корисне навантаження не було підроблене. Якщо ви довіряєте підпису, ви довіряєте корисному навантаженню.

## Коли використовувати JWT

- **Безстанова автентифікація API** (мобільні застосунки, SPA, сервіс-сервіс).
- **Короткоживучі підписані квитки** (посилання скидання пароля, підтвердження email, вхід за magic-link).
- **Довіра між сервісами** (один із ваших сервісів підписує токен; інший перевіряє його, не розділяючи стан БД).

Коли **не** використовувати їх:

- **Серверно-рендеровані застосунки із session-cookie** — використовуйте [Сесії](sessions). Вони чисто обробляють відкликання.
- **Зберігання чутливих даних** — корисні навантаження JWT закодовані в base64, **не зашифровані**. Будь-хто, у кого є токен, може їх прочитати. (Для зашифрованих токенів див. JWE — ця бібліотека його не реалізує.)

## Приклад за 30 секунд

```php
use Lift\Jwt\Jwt;
use Lift\Jwt\Claims;

$jwt = new Jwt(secret: $_ENV['JWT_SECRET']);

// Випустити токен
$token = $jwt->encode(
    Claims::make()
        ->subject('user_42')
        ->expiresIn(3600)
        ->extra(['role' => 'admin'])
        ->toArray()
);

// Пізніше — перевірити й декодувати
try {
    $payload = $jwt->decode($token);
    // $payload['sub']   === 'user_42'
    // $payload['role']  === 'admin'
} catch (\Lift\Jwt\JwtException $e) {
    // минув, підроблений, невірний ключ, спотворений, …
}
```

## Алгоритми

| Варіант enum            | Тип       | Використовувати, коли                                   |
|-------------------------|-----------|---------------------------------------------------------|
| `JwtAlgorithm::HS256`   | HMAC SHA-256 | Однопроцесні застосунки. І видавець, і перевіряч розділяють один секрет. |
| `JwtAlgorithm::HS384`   | HMAC SHA-384 | Те саме, більший дайджест. Потрібно рідко.           |
| `JwtAlgorithm::HS512`   | HMAC SHA-512 | Те саме, більший дайджест. Потрібно рідко.           |
| `JwtAlgorithm::RS256`   | RSA SHA-256  | **Багатосервісні.** Приватний ключ підписує; публічний перевіряє. |
| `JwtAlgorithm::RS384`   | RSA SHA-384  | Те саме.                                              |
| `JwtAlgorithm::RS512`   | RSA SHA-512  | Те саме.                                              |

### Симетричні (HS*) — просто

```php
$jwt = new Jwt(
    secret: $_ENV['JWT_SECRET'],
    algo:   JwtAlgorithm::HS256,    // за замовчуванням
);
```

> Секрет має бути **щонайменше** 32 випадкових байти. Згенеруйте його один раз:
> `php -r 'echo base64_encode(random_bytes(64));'`

### Асиметричні (RS*) — для розподілених систем

Видавець тримає **приватний** ключ. Перевірячам потрібен лише **публічний** ключ. Скомпрометуйте публічний ключ — нічого не станеться; зловмисник усе одно не зможе підробити токени.

```php
// Видавець
$issuer = new Jwt(
    algo:       JwtAlgorithm::RS256,
    privateKey: file_get_contents('/keys/private.pem'),
);
$token = $issuer->encode($payload);

// Перевіряч (потрібен лише публічний ключ)
$verifier = new Jwt(
    algo:      JwtAlgorithm::RS256,
    publicKey: file_get_contents('/keys/public.pem'),
);
$payload = $verifier->decode($token);
```

Згенеруйте пару ключів:

```bash
openssl genpkey -algorithm RSA -out private.pem -pkeyopt rsa_keygen_bits:2048
openssl rsa -in private.pem -pubout -out public.pem
```

## Білдер `Claims`

Стандартні імена claim у JWT — трилітерні коди. Плавний білдер пише їх повністю:

```php
$payload = Claims::make()
    ->subject('user_42')                              // sub
    ->issuer('https://api.example.com')               // iss
    ->audience('https://app.example.com')             // aud  (або масив)
    ->id(Uuid::v7())                                  // jti  — унікальний id токена
    ->issuedAt()                                      // iat  — за замовчуванням now()
    ->expiresIn(3600)                                 // exp  — через 1 годину від поточного моменту
    ->notBefore(time() + 60)                          // nbf  — дійсний лише після цього часу
    ->extra([
        'role'  => 'admin',
        'email' => 'a@example.com',
    ])
    ->toArray();
```

Білдер можна повністю пропустити й передати сирий масив — це просто синтаксичний цукор.

### Що Lift валідує автоматично

Коли ви викликаєте `$jwt->decode($token)`, він перевіряє:

| Claim   | Поведінка                                                          |
|---------|--------------------------------------------------------------------|
| Підпис  | Завжди перевіряється проти налаштованого ключа/секрета.            |
| `exp`   | Токен відхиляється, якщо `now > exp` (з допуском `leeway`).         |
| `nbf`   | Токен відхиляється, якщо `now < nbf` (з допуском `leeway`).         |
| `iss`   | Перевіряється, лише якщо ви налаштували `issuer:` на екземплярі `Jwt`. |
| `aud`   | Перевіряється, лише якщо ви налаштували `audience:` на екземплярі `Jwt`. |

Усе інше (`sub`, власні claim) **не** валідується бібліотекою — ви перевіряєте їх у своєму обробнику / middleware.

### Примусова перевірка issuer / audience

```php
$jwt = new Jwt(
    secret:   $_ENV['JWT_SECRET'],
    issuer:   'https://auth.example.com',
    audience: 'https://api.example.com',
);

// decode() відхилить будь-який токен, чиї `iss` і `aud` не збігаються
```

Це захищає вас від повторного використання токена між сервісами — токен, випущений *для billing API*, не можна використати проти *admin API*.

### Розходження годинників

Якщо годинник вашого перевіряча відстає від годинника видавця на пару секунд, щойно випущені токени можуть на короткий час виглядати «ще не дійсними». Дозвольте невеликий допуск:

```php
$jwt = new Jwt(secret: $_ENV['JWT_SECRET'], leeway: 30);   // ±30 секунд
```

## Middleware

Готова Bearer-токен автентифікація:

```php
use Lift\Jwt\JwtMiddleware;

$jwt = new Jwt(secret: $_ENV['JWT_SECRET']);
$app->use(new JwtMiddleware($jwt));

// У будь-якому обробнику:
$app->get('/me', function (Request $req) {
    $claims = $req->getAttribute('jwt');         // декодоване корисне навантаження
    return ['user_id' => $claims['sub']];
});
```

Що він робить:

1. Читає `Authorization: Bearer <token>`.
2. Викликає `$jwt->decode($token)`.
3. За успіху: прикріплює корисне навантаження до `$req` як атрибут `'jwt'`, викликає наступний обробник.
4. За невдачі: повертає JSON `401 Unauthorized` + заголовок `WWW-Authenticate: Bearer`.

### Пропуск публічних маршрутів

Middleware може ігнорувати точні шляхи — корисно, коли ви монтуєте його глобально:

```php
$app->use(new JwtMiddleware(
    jwt:    $jwt,
    except: ['/login', '/register', '/healthz', '/openapi.json'],
));
```

Для гнучкішого пропуску (regex-шляхи, публічні групи) застосовуйте погрупово замість глобального:

```php
$app->group('/api', function ($g) use ($jwt) {
    $g->get('/me', /* … */);
    $g->get('/orders', /* … */);
})->middleware(new JwtMiddleware($jwt));
```

### Власне ім’я атрибута

```php
new JwtMiddleware($jwt, attribute: 'auth');
// пізніше: $req->getAttribute('auth')
```

## Наскрізний приклад: вхід + захищений маршрут

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

// 1. Вхід — публічний
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

// 2. Захистити все під /api за допомогою middleware
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

Використання на клієнті:

```bash
# 1. Вхід
TOKEN=$(curl -s -X POST http://localhost:8000/login \
    -H 'Content-Type: application/json' \
    -d '{"email":"a@b.c","password":"hunter2"}' | jq -r .token)

# 2. Використати токен
curl http://localhost:8000/api/me \
    -H "Authorization: Bearer $TOKEN"
```

## Refresh-токени

JWT безстановий — раз випущений токен не можна «відкликати» до його завершення. Стандартне розв’язання:

- **Access-токени** — короткоживучі (5–15 хв) JWT.
- **Refresh-токени** — довгоживучі (дні/тижні) непрозорі ідентифікатори, що зберігаються у вашій БД. Коли access-токен завершується, клієнт обмінює refresh-токен на новий access-токен. **Ви можете відкликати refresh-токен**, видаливши рядок.

Начерк:

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

    // Опційно: ротація — видалити старий refresh-токен, випустити нову пару.
    $access = $jwt->encode(Claims::make()->subject((string)$row['user_id'])->expiresIn(900)->toArray());
    return ['access_token' => $access];
});
```

## Зберігання токенів на стороні клієнта

**SPA / браузер**: розміщуйте access-токен у **пам’яті** (JS-змінна, ніколи не `localStorage`). Refresh-токен — у cookie `HttpOnly; Secure; SameSite=Strict`. Ця комбінація стійка до XSS (access-токен недоступний шкідливим скриптам) і до CSRF (cookie не може бути викрадена іншими сайтами).

**Мобільні**: захищене сховище платформи (Keychain / Keystore).

**Сервіс-сервіс**: у пам’яті процесу; перечитуйте з менеджера секретів під час ротації.

## Чек-лист безпеки

- ✅ HMAC-секрет — **щонайменше 32 випадкових байти**, зберігається в env-змінних / менеджері секретів, **ніколи в git**.
- ✅ Короткий `exp` (≤ 1 година для access-токенів).
- ✅ Завжди валідуйте `iss` і `aud`, коли у вас кілька сервісів / клієнтів.
- ✅ Завжди фіксуйте `algo` на стороні сервера — ніколи не дозволяйте заголовку `alg` токена вирішувати.
- ✅ Ротуйте RSA-ключі щонайменше щорічно. Підтримуйте кілька публічних ключів у вікні ротації (побудуйте невеликий `KeySelector`, якщо це потрібно).
- ❌ **Ніколи** не довіряйте корисному навантаженню до того, як `decode()` успішно повернеться.
- ❌ **Ніколи** не розміщуйте паролі, сирі персональні дані чи session-cookie всередині JWT — вони видимі у відкритому вигляді.
- ❌ **Ніколи** не використовуйте `alg: none` — enum Lift його навіть не включає, але майте на увазі, що деякі бібліотеки так.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `Token has expired` одразу після випуску | Годинники видавця й перевіряча розходяться | Установіть `leeway: 30` на перевірячі. |
| 401 на кожному запиті після деплою | `JWT_SECRET` змінився | Ротуйте обережно: токени, випущені зі старим секретом, помирають миттєво. Використовуйте rolling-деплої / підтримку двох ключів. |
| `Missing or malformed Authorization header.` | Заголовок `Bearer token` (правильно), але клієнт забув пробіл або використовує `bearer` (нижній регістр OK) | Перевірка Lift регістрозалежна для `Bearer ` — переконайтеся, що клієнт надсилає рівно цей префікс. |
| Поле `role` токена оновлено на сервері, але користувач усе ще бачить стару роль | JWT *безстановий*; старі токени працюють до `exp` | Короткий `exp` + refresh-токени; або підтримуйте серверну «версію сесії», яку клієнт має повертати. |
| RSA-перевірка повертає `false` для валідного токена | Невірний ключ або PEM з переносами рядків CRLF | Переекспортуйте ключі; перевірте через `openssl rsa -in private.pem -check`. |
| Токен величезний | Ви напхали в нього багато claim | Тримайте корисні навантаження крихітними (sub + кілька id). Токен надсилається на кожному запиті. |

## Шпаргалка

```php
// Налаштувати
$jwt = new Jwt(
    secret:   $_ENV['JWT_SECRET'],
    algo:     JwtAlgorithm::HS256,
    leeway:   30,
    issuer:   'https://auth.example.com',
    audience: 'https://api.example.com',
);

// Випустити
$token = $jwt->encode(
    Claims::make()->subject('user_42')->expiresIn(3600)->extra([...])->toArray()
);

// Перевірити
try { $payload = $jwt->decode($token); }
catch (JwtException $e) { /* 401 */ }

// Middleware
$app->use(new JwtMiddleware($jwt, except: ['/login']));
$claims = $req->getAttribute('jwt');
```

[Шифрування →](crypto)
