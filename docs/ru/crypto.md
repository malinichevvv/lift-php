---
layout: page
title: Криптография
nav_order: 26
---

# Криптография

Три небольших, узконаправленных класса, покрывающих 99 % того, что веб-приложению нужно делать с криптографией — без подтягивания 5-мегабайтного стека зависимостей вроде Sodium/libsodium/Defuse:

| Класс            | Решает                                                  | Алгоритм      |
|------------------|---------------------------------------------------------|---------------|
| `Encrypter`      | Шифрование и аутентификация данных в покое               | AES-256-GCM   |
| `Hasher`         | Хеширование паролей (одностороннее, намеренно медленное)  | Argon2id (по умолчанию) / Argon2i / bcrypt |
| `Signer`         | Подпись данных, чтобы их можно было проверить позже      | HMAC (по умолчанию SHA-256) |

Все три **безсостоятельны** и безопасны для регистрации как синглтоны. Все сравнения используют примитивы, устойчивые к тайминг-атакам (`hash_equals`, `password_verify`, проверка тега GCM).

> Ментальная модель: выбирайте по намерению. *«Хочу это потом прочитать»* → `Encrypter`. *«Хочу доказать, что это моё, но не прятать»* → `Signer`. *«Сравнить пароль, никогда его не восстанавливая»* → `Hasher`.

## Когда что использовать

| Потребность                                       | Использовать |
|---------------------------------------------------|--------------|
| Хранить секреты в покое (API-токены, персональные данные) | `Encrypter` |
| Подписывать параметры URL, cookie, непрозрачные билеты    | `Signer`    |
| Хешировать пароли пользователей                   | `Hasher`    |
| Безсостоятельные токены аутентификации API с claim | [JWT](jwt)  |

Если вас тянет написать `md5($password)` — остановитесь, выдохните, используйте `Hasher`.

---

## Encrypter — AES-256-GCM

Аутентифицированное симметричное шифрование. AES-256-GCM обеспечивает **и** конфиденциальность (никто не может это прочитать), **и** целостность (никто не может это подделать). Расшифровка с неверным ключом или перевёрнутым битом выбрасывает исключение — никогда не портит данные молча.

Формат хранения (затем кодируется в base64):

```
[12-байтовый IV][16-байтовый тег GCM][шифротекст переменной длины]
```

### Быстрый старт

```php
use Lift\Crypto\Encrypter;

// Один раз: сгенерируйте ключ и сохраните в base64
$key = base64_encode(Encrypter::generateKey());     // положите в env-переменную APP_KEY

// При загрузке:
$encrypter = new Encrypter(base64_decode($_ENV['APP_KEY']));

// Зашифровать что угодно
$ciphertext = $encrypter->encrypt('hunter2');
$plaintext  = $encrypter->decrypt($ciphertext);     // 'hunter2'
```

### Сгенерируйте ключ

```bash
php -r "require 'vendor/autoload.php'; echo base64_encode(\Lift\Crypto\Encrypter::generateKey()) . PHP_EOL;"
# d6vK2tBh+RDxYTPbAv1mZ+iD1mPj5L0eR2RhYZmDcNk=
```

Поместите результат в `.env` как `APP_KEY=…`. Никогда не коммитьте. Никогда не логируйте.

### Свойства вывода

- **Разный шифротекст каждый раз** даже для одного и того же открытого текста (случайный IV на каждый вызов). Не используйте его как ключ дедупликации.
- **Аутентифицирован.** Подделка где-либо → `RuntimeException: Decryption failed: authentication tag mismatch`.
- **Закодирован в base64**, поэтому безопасен в URL / cookie / JSON / столбцах базы данных.
- **Около 28 байт накладных расходов** по сравнению с сырым открытым текстом (IV + тег + дополнение base64).

### Реальные паттерны

#### Зашифрованный столбец

```php
$encrypter = $app->make(Encrypter::class);

$db->table('users')->insert([
    'email'         => $email,
    'oauth_token'   => $encrypter->encrypt($accessToken),    // хранится зашифрованным
]);

$row = $db->table('users')->where('id', $id)->first();
$accessToken = $encrypter->decrypt($row['oauth_token']);
```

#### Зашифрованная cookie

```php
return Response::json($data)
    ->withCookie('session_extra', $encrypter->encrypt(json_encode($payload)), [
        'http_only' => true,
        'secure'    => true,
        'same_site' => 'Lax',
    ]);
```

#### Одноразовая ссылка с зашифрованной полезной нагрузкой

```php
// Сгенерировать
$url = '/reset?t=' . urlencode($encrypter->encrypt(json_encode([
    'user_id' => 42,
    'exp'     => time() + 900,
])));

// Проверить
try {
    $payload = json_decode($encrypter->decrypt($req->query('t')), true);
    if ($payload['exp'] < time()) throw new \RuntimeException('expired');
} catch (\Throwable) {
    return Response::json(['error' => 'Invalid link'], 400);
}
```

Для токенов, которые должны быть **читаемы** клиенту (например, ему нужно видеть user-id), используйте вместо этого [Signer](#signer--hmac) — шифрование избыточно.

### Ротация ключей

Если `APP_KEY` когда-либо утечёт, ротируйте:

1. Сгенерируйте новый ключ.
2. Держите **старый** ключ доступным как `APP_KEY_PREVIOUS`.
3. При неудаче `decrypt()` с новым ключом попробуйте предыдущий. Если он сработал, перешифруйте новым ключом и сохраните.

```php
try {
    $plaintext = $newEncrypter->decrypt($ciphertext);
} catch (\RuntimeException) {
    $plaintext  = $oldEncrypter->decrypt($ciphertext);          // переходный период
    $ciphertext = $newEncrypter->encrypt($plaintext);           // сохранить новое
}
```

Через две недели удалите старый ключ.

### Ограничения

- Длина открытого текста: практически неограниченна (мы никогда не загружаем больше одного раза в память; ограничение — то, что позволяет PHP).
- Не пытайтесь шифровать **потоки** этим классом — он одноразовый. Для больших файлов шифруйте чанки по отдельности.

---

## Hasher — хеширование паролей

Оборачивает нативные `password_hash()` / `password_verify()` PHP. **Argon2id** — по умолчанию: рекомендован OWASP, устойчив и к GPU-перебору, и к атакам по сторонним каналам.

### Быстрый старт

```php
use Lift\Crypto\Hasher;

$hasher = new Hasher();           // Argon2id, опции по умолчанию

// Хешировать при регистрации
$hash = $hasher->hash('hunter2');
$db->table('users')->insert(['email' => $email, 'password_hash' => $hash]);

// Проверить при входе
$user = $db->table('users')->where('email', $email)->first();
if ($user === null || !$hasher->verify($plainPassword, $user['password_hash'])) {
    return Response::json(['error' => 'Invalid credentials'], 401);
}
```

### Почему Argon2id

| Алгоритм   | Memory-hard | Устойчив к GPU | Рекомендован OWASP |
|------------|:-----------:|:--------------:|:------------------:|
| MD5 / SHA-* | ❌          | ❌            | НИКОГДА            |
| bcrypt     | частично    | ⚠️ (современные GPU помогают) | OK |
| Argon2i    | ✅          | ✅            | ✅                 |
| **Argon2id** | ✅        | ✅            | ✅ по умолчанию    |

Argon2id смешивает Argon2i (устойчив к сторонним каналам) и Argon2d (устойчив к GPU) — лучшее из обоих. Используйте его, если только у вас нет жёсткого ограничения на поддержку легаси.

### Настройка стоимости

Параметры Argon2 контролируют, сколько CPU/RAM стоит хеширование (и, следовательно, насколько медленно его взломать). Значения по умолчанию хороши; для приложений с высокими требованиями к безопасности увеличьте их:

```php
$hasher = new Hasher(
    algorithm: Algorithm::Argon2id,
    options:   [
        'memory_cost' => 65_536,   // КиБ  — 64 МиБ
        'time_cost'   => 4,        // итерации
        'threads'     => 2,
    ],
);
```

Стремитесь ко времени хеширования ≈ 100–300 мс на вашем продакшен-железе. Замерьте:

```php
$start = hrtime(true);
$hasher->hash('test');
$ms = (hrtime(true) - $start) / 1e6;
echo "hash took {$ms} ms\n";
```

### bcrypt для совместимости с легаси

```php
$hasher = new Hasher(algorithm: Algorithm::Bcrypt, options: ['cost' => 12]);
```

Примечания:

- bcrypt молча обрезает ввод до **72 байт** — многобайтовые пароли могут потерять энтропию. Argon2 — нет.
- Фактор стоимости 12 — минимальное современное руководство; 14+ для приложений, чувствительных к безопасности.

### Повторное хеширование при входе

Когда вы повышаете факторы стоимости Argon2 (или переходите с bcrypt на Argon2id), пользователей со старым хешем нужно обновлять прозрачно:

```php
if ($hasher->verify($plainPassword, $row['password_hash'])) {
    if ($hasher->needsRehash($row['password_hash'])) {
        $newHash = $hasher->hash($plainPassword);
        $db->table('users')->where('id', $row['id'])->update(['password_hash' => $newHash]);
    }
    // … выполнить вход …
}
```

`needsRehash()` возвращает true, когда сохранённый хеш использует более слабый алгоритм или меньшую стоимость, чем текущая конфигурация `Hasher`.

---

## Signer — HMAC

`Signer` не прячет данные — он доказывает, что данные пришли от вас. Сценарии:

- Подписанные параметры URL (`/files?id=42&exp=…&sig=…`)
- Безсостоятельные одноразовые токены (сброс пароля, подтверждение email)
- Подписи вебхуков (`X-Signature: sha256=…`)
- Cookie, которые клиент должен читать, но не изменять

### Быстрый старт

```php
use Lift\Crypto\Signer;

$signer = new Signer($_ENV['APP_SECRET']);          // любой непустой секрет
$signer = new Signer($_ENV['APP_SECRET'], 'sha512'); // любой алгоритм из hash_hmac_algos()
```

### Сырая подпись / проверка

```php
$payload = $userId . '|' . $exp;

$sig = $signer->sign($payload);                 // 64-символьный hex
$ok  = $signer->verify($payload, $sig);         // bool, устойчиво к таймингу

if (!$ok) { /* подделано */ }
```

### Самодостаточные токены

Высокоуровневый помощник упаковывает полезную нагрузку + подпись в одну URL-безопасную строку:

```php
$token = $signer->signToken([
    'user_id' => 42,
    'action'  => 'reset_password',
    'exp'     => time() + 900,
]);
// → 'eyJ1c2VyX2lkIjo0Mn0.b6f3c0a9…'

try {
    $payload = $signer->verifyToken($token);     // возвращает исходный массив
    if ($payload['exp'] < time()) throw new \RuntimeException('expired');
} catch (\RuntimeException $e) {
    // плохая подпись, искажён или истёк
}
```

`signToken()` / `verifyToken()` отличаются от JWT двумя вещами:

- Нет заголовка — алгоритм фиксирован экземпляром `Signer` (никаких атак на путаницу `alg`).
- Полезная нагрузка — это JSON, закодированный в base64url. **Не зашифрована** — любой может её прочитать.

Для совместимости с другими системами, которые ожидают *стандартные* JWT, используйте вместо этого [JWT](jwt).

### Проверка вебхуков

Распространённый паттерн: вы принимаете вебхуки, подписанные HMAC-SHA256 сырого тела.

```php
$app->post('/webhook', function (Request $req) use ($signer) {
    $raw    = (string) $req->getBody();
    $sig    = $req->getHeaderLine('X-Signature');

    if (!$signer->verify($raw, $sig)) {
        throw new \Lift\Exception\UnauthorizedException("Bad signature");
    }

    $event = json_decode($raw, true);
    // … обработать …

    return Response::noContent();
});
```

> Всегда подписывайте и проверяйте **сырые байты**, а не разобранное тело. Повторный разбор меняет пробелы, порядок ключей и т. д. — и подпись не совпадёт.

### Подписанные ссылки на скачивание

```php
$expires = time() + 60;
$signature = $signer->sign("download:$fileId:$expires");
$url = "/download?id={$fileId}&exp={$expires}&sig={$signature}";

// Обработчик
$app->get('/download', function (Request $req) use ($signer, $fs) {
    $id  = (int) $req->query('id');
    $exp = (int) $req->query('exp');
    $sig = $req->query('sig', '');

    if ($exp < time() || !$signer->verify("download:{$id}:{$exp}", $sig)) {
        return Response::json(['error' => 'Link expired or invalid'], 403);
    }
    // … передать файл потоком …
});
```

Пользователь может скопировать URL; без секрета он не сможет подделать новый.

---

## Регистрация всего в контейнере

```php
use Lift\Crypto\Encrypter;
use Lift\Crypto\Hasher;
use Lift\Crypto\Signer;

$app->singleton(Encrypter::class, fn() => new Encrypter(base64_decode($_ENV['APP_KEY'])));
$app->singleton(Hasher::class);                                       // автосвязывается со значениями по умолчанию
$app->singleton(Signer::class,    fn() => new Signer($_ENV['APP_SECRET']));
```

Затем где угодно — обработчик, контроллер, сервис — укажите тип класса:

```php
class TokenService
{
    public function __construct(
        private readonly Encrypter $cipher,
        private readonly Signer    $signer,
    ) {}
}
```

## Чек-лист безопасности

- ✅ Ключи живут в env-переменных / менеджере секретов, **никогда** в git.
- ✅ `APP_KEY` (Encrypter) — это 32 сырых байта (= 44-символьный base64).
- ✅ `APP_SECRET` (Signer / CSRF) — ≥ 32 случайных байт.
- ✅ Используйте Argon2id для паролей. Перехешируйте при входе, когда `needsRehash()` возвращает true.
- ✅ Подписывайте **сырые байты** для проверки вебхуков; никогда разобранный JSON.
- ✅ Всегда проверяйте срок действия наряду с подписью (claim `exp` в токене, `?exp=…` в URL).
- ❌ Не пишите собственный криптоалгоритм — эти классы уже оборачивают правильные примитивы.
- ❌ Не делайте `md5/sha1` паролей. Никогда. (Да, даже с «солью».)
- ❌ Не сравнивайте хеши/подписи через `==`. Используйте `hash_equals()` (что `Signer` делает внутренне).
- ❌ Не переиспользуйте один ключ для нескольких целей (шифрование vs подпись vs cookie) — используйте отдельные env-переменные.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `InvalidArgumentException: Encryption key must be exactly 32 bytes` | Вы передали base64-строку, а не декодированные байты | `new Encrypter(base64_decode($_ENV['APP_KEY']))`. |
| `Decryption failed: authentication tag mismatch` | Неверный ключ, или кто-то перевернул бит (или вы закодировали в base64 дважды) | Перепроверьте ключ; никогда не редактируйте шифротекст вручную. |
| `password_verify()` возвращает false для правильного пароля | bcrypt обрезал пароль до 72 байт при хешировании | Перейдите на Argon2id и перехешируйте всех при следующем входе. |
| Подпись вебхука не проходит | Вы проверяли против `json_decode($body)`, а не сырых байт | Используйте `(string) $req->getBody()` ДО любого разбора. |
| `Hasher::hash()` занимает 2 секунды | Стоимость Argon2 очень высока | Снизьте `memory_cost` / `time_cost`; стремитесь к ~200 мс. |
| URL с подписью раскрывают user_id | Полезная нагрузка — это JSON в base64, не зашифрована | Используйте `Encrypter`, если нужно скрыть содержимое. |

## Шпаргалка

```php
// Encrypter
$e = new Encrypter(base64_decode($_ENV['APP_KEY']));
$ct = $e->encrypt('secret');
$pt = $e->decrypt($ct);

// Hasher
$h = new Hasher();                              // Argon2id по умолчанию
$hash = $h->hash($password);
$h->verify($password, $hash);                   // bool
$h->needsRehash($hash);                         // bool, после обновления

// Signer
$s = new Signer($_ENV['APP_SECRET']);
$sig = $s->sign($data);                         // hex
$s->verify($data, $sig);                        // bool, устойчиво к таймингу
$tok = $s->signToken(['user_id' => 1, 'exp' => time() + 60]);
$payload = $s->verifyToken($tok);
```

[Очереди →](queues)
