---
layout: page
title: Криптографія
nav_order: 26
---

# Криптографія

Три невеликих, вузькоспрямованих класи, що покривають 99 % того, що вебзастосунку потрібно робити з криптографією — без підтягування 5-мегабайтного стека залежностей на кшталт Sodium/libsodium/Defuse:

| Клас             | Розв’язує                                               | Алгоритм      |
|------------------|---------------------------------------------------------|---------------|
| `Encrypter`      | Шифрування й автентифікація даних у спокої               | AES-256-GCM   |
| `Hasher`         | Хешування паролів (одностороннє, навмисно повільне)      | Argon2id (за замовчуванням) / Argon2i / bcrypt |
| `Signer`         | Підпис даних, щоб їх можна було перевірити пізніше       | HMAC (за замовчуванням SHA-256) |

Усі три **безстанові** й безпечні для реєстрації як синглтони. Усі порівняння використовують примітиви, стійкі до тайминг-атак (`hash_equals`, `password_verify`, перевірка тегу GCM).

> Ментальна модель: обирайте за наміром. *«Хочу це потім прочитати»* → `Encrypter`. *«Хочу довести, що це моє, але не ховати»* → `Signer`. *«Порівняти пароль, ніколи його не відновлюючи»* → `Hasher`.

## Коли що використовувати

| Потреба                                            | Використовувати |
|----------------------------------------------------|-----------------|
| Зберігати секрети у спокої (API-токени, персональні дані) | `Encrypter` |
| Підписувати параметри URL, cookie, непрозорі квитки | `Signer`        |
| Хешувати паролі користувачів                       | `Hasher`        |
| Безстанові токени автентифікації API з claim        | [JWT](jwt)      |

Якщо вас тягне написати `md5($password)` — зупиніться, видихніть, використовуйте `Hasher`.

---

## Encrypter — AES-256-GCM

Автентифіковане симетричне шифрування. AES-256-GCM забезпечує **і** конфіденційність (ніхто не може це прочитати), **і** цілісність (ніхто не може це підробити). Розшифрування з невірним ключем або перевернутим бітом викидає виняток — ніколи не псує дані мовчки.

Формат зберігання (потім кодується в base64):

```
[12-байтовий IV][16-байтовий тег GCM][шифротекст змінної довжини]
```

### Швидкий старт

```php
use Lift\Crypto\Encrypter;

// Один раз: згенеруйте ключ і збережіть у base64
$key = base64_encode(Encrypter::generateKey());     // покладіть у env-змінну APP_KEY

// Під час завантаження:
$encrypter = new Encrypter(base64_decode($_ENV['APP_KEY']));

// Зашифрувати що завгодно
$ciphertext = $encrypter->encrypt('hunter2');
$plaintext  = $encrypter->decrypt($ciphertext);     // 'hunter2'
```

### Згенеруйте ключ

```bash
php -r "require 'vendor/autoload.php'; echo base64_encode(\Lift\Crypto\Encrypter::generateKey()) . PHP_EOL;"
# d6vK2tBh+RDxYTPbAv1mZ+iD1mPj5L0eR2RhYZmDcNk=
```

Помістіть результат у `.env` як `APP_KEY=…`. Ніколи не комітьте. Ніколи не логуйте.

### Властивості виводу

- **Різний шифротекст щоразу** навіть для того самого відкритого тексту (випадковий IV на кожен виклик). Не використовуйте його як ключ дедуплікації.
- **Автентифікований.** Підробка будь-де → `RuntimeException: Decryption failed: authentication tag mismatch`.
- **Закодований у base64**, тому безпечний у URL / cookie / JSON / стовпцях бази даних.
- **Близько 28 байт накладних витрат** порівняно із сирим відкритим текстом (IV + тег + доповнення base64).

### Реальні патерни

#### Зашифрований стовпець

```php
$encrypter = $app->make(Encrypter::class);

$db->table('users')->insert([
    'email'         => $email,
    'oauth_token'   => $encrypter->encrypt($accessToken),    // зберігається зашифрованим
]);

$row = $db->table('users')->where('id', $id)->first();
$accessToken = $encrypter->decrypt($row['oauth_token']);
```

#### Зашифрована cookie

```php
return Response::json($data)
    ->withCookie('session_extra', $encrypter->encrypt(json_encode($payload)), [
        'http_only' => true,
        'secure'    => true,
        'same_site' => 'Lax',
    ]);
```

#### Одноразове посилання із зашифрованим корисним навантаженням

```php
// Згенерувати
$url = '/reset?t=' . urlencode($encrypter->encrypt(json_encode([
    'user_id' => 42,
    'exp'     => time() + 900,
])));

// Перевірити
try {
    $payload = json_decode($encrypter->decrypt($req->query('t')), true);
    if ($payload['exp'] < time()) throw new \RuntimeException('expired');
} catch (\Throwable) {
    return Response::json(['error' => 'Invalid link'], 400);
}
```

Для токенів, які мають бути **читаними** клієнту (наприклад, йому потрібно бачити user-id), використовуйте натомість [Signer](#signer--hmac) — шифрування надмірне.

### Ротація ключів

Якщо `APP_KEY` колись витече, ротуйте:

1. Згенеруйте новий ключ.
2. Тримайте **старий** ключ доступним як `APP_KEY_PREVIOUS`.
3. За невдачі `decrypt()` з новим ключем спробуйте попередній. Якщо він спрацював, перешифруйте новим ключем і збережіть.

```php
try {
    $plaintext = $newEncrypter->decrypt($ciphertext);
} catch (\RuntimeException) {
    $plaintext  = $oldEncrypter->decrypt($ciphertext);          // перехідний період
    $ciphertext = $newEncrypter->encrypt($plaintext);           // зберегти нове
}
```

Через два тижні видаліть старий ключ.

### Обмеження

- Довжина відкритого тексту: практично необмежена (ми ніколи не завантажуємо більше одного разу в пам’ять; обмеження — те, що дозволяє PHP).
- Не намагайтеся шифрувати **потоки** цим класом — він одноразовий. Для великих файлів шифруйте чанки окремо.

---

## Hasher — хешування паролів

Загортає нативні `password_hash()` / `password_verify()` PHP. **Argon2id** — за замовчуванням: рекомендований OWASP, стійкий і до GPU-перебору, і до атак бічними каналами.

### Швидкий старт

```php
use Lift\Crypto\Hasher;

$hasher = new Hasher();           // Argon2id, опції за замовчуванням

// Хешувати під час реєстрації
$hash = $hasher->hash('hunter2');
$db->table('users')->insert(['email' => $email, 'password_hash' => $hash]);

// Перевірити під час входу
$user = $db->table('users')->where('email', $email)->first();
if ($user === null || !$hasher->verify($plainPassword, $user['password_hash'])) {
    return Response::json(['error' => 'Invalid credentials'], 401);
}
```

### Чому Argon2id

| Алгоритм   | Memory-hard | Стійкий до GPU | Рекомендований OWASP |
|------------|:-----------:|:--------------:|:--------------------:|
| MD5 / SHA-* | ❌          | ❌            | НІКОЛИ               |
| bcrypt     | частково    | ⚠️ (сучасні GPU допомагають) | OK |
| Argon2i    | ✅          | ✅            | ✅                   |
| **Argon2id** | ✅        | ✅            | ✅ за замовчуванням  |

Argon2id змішує Argon2i (стійкий до бічних каналів) і Argon2d (стійкий до GPU) — найкраще з обох. Використовуйте його, якщо тільки у вас немає жорсткого обмеження на підтримку легасі.

### Налаштування вартості

Параметри Argon2 контролюють, скільки CPU/RAM коштує хешування (і, отже, наскільки повільно його зламати). Значення за замовчуванням хороші; для застосунків із високими вимогами до безпеки збільшіть їх:

```php
$hasher = new Hasher(
    algorithm: Algorithm::Argon2id,
    options:   [
        'memory_cost' => 65_536,   // КіБ  — 64 МіБ
        'time_cost'   => 4,        // ітерації
        'threads'     => 2,
    ],
);
```

Прагніть до часу хешування ≈ 100–300 мс на вашому продакшен-залізі. Заміряйте:

```php
$start = hrtime(true);
$hasher->hash('test');
$ms = (hrtime(true) - $start) / 1e6;
echo "hash took {$ms} ms\n";
```

### bcrypt для сумісності з легасі

```php
$hasher = new Hasher(algorithm: Algorithm::Bcrypt, options: ['cost' => 12]);
```

Примітки:

- bcrypt мовчки обрізає ввід до **72 байт** — багатобайтові паролі можуть втратити ентропію. Argon2 — ні.
- Фактор вартості 12 — мінімальне сучасне керівництво; 14+ для застосунків, чутливих до безпеки.

### Повторне хешування під час входу

Коли ви підвищуєте фактори вартості Argon2 (або переходите з bcrypt на Argon2id), користувачів зі старим хешем потрібно оновлювати прозоро:

```php
if ($hasher->verify($plainPassword, $row['password_hash'])) {
    if ($hasher->needsRehash($row['password_hash'])) {
        $newHash = $hasher->hash($plainPassword);
        $db->table('users')->where('id', $row['id'])->update(['password_hash' => $newHash]);
    }
    // … виконати вхід …
}
```

`needsRehash()` повертає true, коли збережений хеш використовує слабший алгоритм або меншу вартість, ніж поточна конфігурація `Hasher`.

---

## Signer — HMAC

`Signer` не ховає дані — він доводить, що дані прийшли від вас. Сценарії:

- Підписані параметри URL (`/files?id=42&exp=…&sig=…`)
- Безстанові одноразові токени (скидання пароля, підтвердження email)
- Підписи вебхуків (`X-Signature: sha256=…`)
- Cookie, які клієнт має читати, але не змінювати

### Швидкий старт

```php
use Lift\Crypto\Signer;

$signer = new Signer($_ENV['APP_SECRET']);          // будь-який непорожній секрет
$signer = new Signer($_ENV['APP_SECRET'], 'sha512'); // будь-який алгоритм із hash_hmac_algos()
```

### Сирий підпис / перевірка

```php
$payload = $userId . '|' . $exp;

$sig = $signer->sign($payload);                 // 64-символьний hex
$ok  = $signer->verify($payload, $sig);         // bool, стійко до таймінгу

if (!$ok) { /* підроблено */ }
```

### Самодостатні токени

Високорівневий помічник пакує корисне навантаження + підпис в один URL-безпечний рядок:

```php
$token = $signer->signToken([
    'user_id' => 42,
    'action'  => 'reset_password',
    'exp'     => time() + 900,
]);
// → 'eyJ1c2VyX2lkIjo0Mn0.b6f3c0a9…'

try {
    $payload = $signer->verifyToken($token);     // повертає вихідний масив
    if ($payload['exp'] < time()) throw new \RuntimeException('expired');
} catch (\RuntimeException $e) {
    // погана підпис, спотворений або минув
}
```

`signToken()` / `verifyToken()` відрізняються від JWT двома речами:

- Немає заголовка — алгоритм фіксований екземпляром `Signer` (жодних атак на плутанину `alg`).
- Корисне навантаження — це JSON, закодований у base64url. **Не зашифроване** — будь-хто може його прочитати.

Для сумісності з іншими системами, які очікують *стандартні* JWT, використовуйте натомість [JWT](jwt).

### Перевірка вебхуків

Поширений патерн: ви приймаєте вебхуки, підписані HMAC-SHA256 сирого тіла.

```php
$app->post('/webhook', function (Request $req) use ($signer) {
    $raw    = (string) $req->getBody();
    $sig    = $req->getHeaderLine('X-Signature');

    if (!$signer->verify($raw, $sig)) {
        throw new \Lift\Exception\UnauthorizedException("Bad signature");
    }

    $event = json_decode($raw, true);
    // … обробити …

    return Response::noContent();
});
```

> Завжди підписуйте й перевіряйте **сирі байти**, а не розібране тіло. Повторний розбір змінює пробіли, порядок ключів тощо — і підпис не збіжиться.

### Підписані посилання на завантаження

```php
$expires = time() + 60;
$signature = $signer->sign("download:$fileId:$expires");
$url = "/download?id={$fileId}&exp={$expires}&sig={$signature}";

// Обробник
$app->get('/download', function (Request $req) use ($signer, $fs) {
    $id  = (int) $req->query('id');
    $exp = (int) $req->query('exp');
    $sig = $req->query('sig', '');

    if ($exp < time() || !$signer->verify("download:{$id}:{$exp}", $sig)) {
        return Response::json(['error' => 'Link expired or invalid'], 403);
    }
    // … передати файл потоком …
});
```

Користувач може скопіювати URL; без секрета він не зможе підробити новий.

---

## Реєстрація всього в контейнері

```php
use Lift\Crypto\Encrypter;
use Lift\Crypto\Hasher;
use Lift\Crypto\Signer;

$app->singleton(Encrypter::class, fn() => new Encrypter(base64_decode($_ENV['APP_KEY'])));
$app->singleton(Hasher::class);                                       // автозв’язується зі значеннями за замовчуванням
$app->singleton(Signer::class,    fn() => new Signer($_ENV['APP_SECRET']));
```

Потім будь-де — обробник, контролер, сервіс — вкажіть тип класу:

```php
class TokenService
{
    public function __construct(
        private readonly Encrypter $cipher,
        private readonly Signer    $signer,
    ) {}
}
```

## Чек-лист безпеки

- ✅ Ключі живуть в env-змінних / менеджері секретів, **ніколи** в git.
- ✅ `APP_KEY` (Encrypter) — це 32 сирих байти (= 44-символьний base64).
- ✅ `APP_SECRET` (Signer / CSRF) — ≥ 32 випадкових байти.
- ✅ Використовуйте Argon2id для паролів. Перехешуйте під час входу, коли `needsRehash()` повертає true.
- ✅ Підписуйте **сирі байти** для перевірки вебхуків; ніколи розібраний JSON.
- ✅ Завжди перевіряйте термін дії поряд із підписом (claim `exp` у токені, `?exp=…` в URL).
- ❌ Не пишіть власний криптоалгоритм — ці класи вже загортають правильні примітиви.
- ❌ Не робіть `md5/sha1` паролів. Ніколи. (Так, навіть із «сіллю».)
- ❌ Не порівнюйте хеші/підписи через `==`. Використовуйте `hash_equals()` (що `Signer` робить внутрішньо).
- ❌ Не використовуйте повторно один ключ для кількох цілей (шифрування vs підпис vs cookie) — використовуйте окремі env-змінні.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `InvalidArgumentException: Encryption key must be exactly 32 bytes` | Ви передали base64-рядок, а не декодовані байти | `new Encrypter(base64_decode($_ENV['APP_KEY']))`. |
| `Decryption failed: authentication tag mismatch` | Невірний ключ, або хтось перевернув біт (або ви закодували в base64 двічі) | Перевірте ключ; ніколи не редагуйте шифротекст вручну. |
| `password_verify()` повертає false для правильного пароля | bcrypt обрізав пароль до 72 байт під час хешування | Перейдіть на Argon2id і перехешуйте всіх під час наступного входу. |
| Підпис вебхука не проходить | Ви перевіряли проти `json_decode($body)`, а не сирих байтів | Використовуйте `(string) $req->getBody()` ДО будь-якого розбору. |
| `Hasher::hash()` займає 2 секунди | Вартість Argon2 дуже висока | Знизьте `memory_cost` / `time_cost`; прагніть до ~200 мс. |
| URL із підписом розкривають user_id | Корисне навантаження — це JSON у base64, не зашифроване | Використовуйте `Encrypter`, якщо потрібно приховати вміст. |

## Шпаргалка

```php
// Encrypter
$e = new Encrypter(base64_decode($_ENV['APP_KEY']));
$ct = $e->encrypt('secret');
$pt = $e->decrypt($ct);

// Hasher
$h = new Hasher();                              // Argon2id за замовчуванням
$hash = $h->hash($password);
$h->verify($password, $hash);                   // bool
$h->needsRehash($hash);                         // bool, після оновлення

// Signer
$s = new Signer($_ENV['APP_SECRET']);
$sig = $s->sign($data);                         // hex
$s->verify($data, $sig);                        // bool, стійко до таймінгу
$tok = $s->signToken(['user_id' => 1, 'exp' => time() + 60]);
$payload = $s->verifyToken($tok);
```

[Черги →](queues)
