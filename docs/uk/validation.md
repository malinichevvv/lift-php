---
layout: page
title: Валідація
nav_order: 19
---

# Валідація

Валідатор Lift відповідає на одне запитання: **«чи відповідають ці вхідні дані очікуваним мною правилам?»** — і дає точний перелік того, що не пройшло.

Він працює з будь-яким асоціативним масивом: тіло HTTP-запиту, рядок запиту, параметри JSON-RPC, аргументи CLI, навіть рядок, прочитаний з іншого сервісу. DSL навмисно схожий на Laravel, тому крива навчання майже нульова.

> Ментальна модель: ви описуєте кожне поле списком правил (`'required|email|max:255'`). Валідатор збирає **всі** помилки (він не зупиняється на першій) і повертає або очищені дані, або карту помилок.

## 1. Тур за 60 секунд

```php
use Lift\Validation\Validator;

$v = new Validator($_POST, [
    'name'     => 'required|string|min:2|max:255',
    'email'    => 'required|email',
    'age'      => 'integer|min:13|max:120',
    'role'     => 'required|in:admin,user,moderator',
    'website'  => 'nullable|url',
]);

if ($v->fails()) {
    return Response::json(['errors' => $v->errors()], 422);
}

$data = $v->validated();
```

Три речі, які треба пам’ятати:

1. Правила можуть бути рядком через вертикальну риску (`'required|email'`) або масивом правил/об’єктів/замикань (`['required', 'email', new MyRule()]`).
2. `$v->errors()` — це `array<string, string[]>` — кожне поле може мати кілька повідомлень про помилки.
3. `$v->validated()` повертає лише ті поля, для яких ви оголосили правила (чистий DTO).

## 2. Валідація всередині маршруту

В HTTP-обробнику однорядковий `$req->validate(...)` — найпростіший шлях. Він об’єднує тіло + query + параметри маршруту, запускає валідатор, **викидає `ValidationException` за помилки**, а інакше повертає валідований масив. Типовий обробник помилок Lift перетворює виняток на **HTTP 422** з правильною формою JSON — `try/catch` писати не потрібно:

```php
$app->post('/users', function (Request $req) use ($repo) {
    $data = $req->validate([
        'name'     => 'required|string|min:2',
        'email'    => 'required|email',
        'password' => 'required|min:8|confirmed',
    ]);

    return Response::json($repo->create($data), 201);
});
```

Тіло відповіді за помилки виглядає так:

```json
{
  "errors": {
    "email":    ["The email must be a valid email address."],
    "password": ["The password must be at least 8 characters."]
  }
}
```

Для типізованого, повторно використовуваного контейнера використовуйте [FormRequest](form-requests).

## 3. Шпаргалка з повернених значень

| Метод             | Повертає                               | Примітки                                    |
|-------------------|----------------------------------------|---------------------------------------------|
| `passes()`        | `bool`                                 | `true`, коли проходять **усі** правила      |
| `fails()`         | `bool`                                 | `!passes()`                                 |
| `errors()`        | `array<string, string[]>`              | поле → список повідомлень                    |
| `validated()`     | `array<string, mixed>`                 | викидає `ValidationException` за помилки     |

## 4. Синтаксис правил

```php
// Через вертикальну риску (компактно, рекомендовано для простих випадків)
'email' => 'required|email|max:255'

// Масив (дозволяє змішувати замикання та об’єкти правил)
'phone' => ['required', 'string', new PhoneRule()]
```

Правила виконуються **у порядку, в якому ви їх перелічили**. `required`, `nullable` і `sometimes` особливі — вони впливають на те, чи виконується решта ланцюжка взагалі (див. §6 нижче).

Кілька помилок на поле збираються: валідація **не** зупиняється на першій невдачі, тому користувач бачить усі проблеми одразу.

## 5. Вбудовані правила — повний довідник

### Присутність і потік

| Правило | Опис |
|---------|------|
| `required` | Поле має бути присутнім і непорожнім (`''`, `[]`, `null` усі не проходять). |
| `nullable` | Якщо поле відсутнє / null / порожнє, пропустити решту ланцюжка. |
| `sometimes` | Якщо ключа **взагалі немає у вхідних даних**, пропустити всі правила. Чудово для PATCH. |
| `present` | Ключ має існувати (значення може бути `null` або `''`). |
| `filled` | Якщо ключ існує, значення не має бути порожнім. |

```php
'bio'        => 'nullable|string|max:500',          // порожній рядок допустимий
'avatar_url' => 'sometimes|url',                    // може бути відсутнім за PATCH
'profile.id' => 'present',                          // має бути присутнім, навіть як null
```

### Умовні required / prohibited

Посилайтеся на будь-яке інше поле через крапковий шлях. Працюють і поля верхнього рівня, і вкладені.

| Правило | Опис |
|---------|------|
| `required_if:field,value`        | Обов’язкове, коли *field* дорівнює *value*.        |
| `required_unless:field,value`    | Обов’язкове, якщо тільки *field* не дорівнює *value*. |
| `required_with:f1,f2,...`        | Обов’язкове, якщо **будь-яке** з перелічених полів непорожнє. |
| `required_without:f1,f2,...`     | Обов’язкове, якщо **будь-яке** з перелічених полів відсутнє/порожнє. |
| `prohibited`                     | Поле має бути відсутнім або порожнім.              |
| `prohibited_if:field,value`      | Заборонене, коли *field* дорівнює *value*.         |
| `prohibited_unless:field,value`  | Заборонене, якщо тільки *field* не дорівнює *value*. |

```php
'type'         => 'required|in:individual,company',
'company.name' => 'required_if:type,company|string|max:200',
'person.dob'   => 'required_unless:type,company|date',

'admin_token'  => 'prohibited_unless:role,admin|string',
'password'     => 'prohibited_if:role,guest|string|min:8',
```

### Тип

| Правило | Проходить, коли |
|---------|-----------------|
| `string`               | Значення — рядок PHP.                                     |
| `integer` / `int`      | Числове ціле (приймає `"42"`).                            |
| `float` / `numeric`    | Числове (int або float).                                  |
| `boolean` / `bool`     | Одне з `true`, `false`, `1`, `0`, `"1"`, `"0"`, `"true"`, `"false"`. |
| `array`                | Масив PHP.                                                |

### Формат

| Правило | Проходить, коли |
|---------|-----------------|
| `email`                  | Коректна адреса електронної пошти.                      |
| `url`                    | Коректний URL.                                          |
| `ip` / `ipv4` / `ipv6`   | Відповідна IP-адреса.                                   |
| `alpha`                  | Лише ASCII-літери.                                      |
| `alpha_num`              | Лише ASCII-літери + цифри.                              |
| `digits`                 | Лише цифрові символи.                                   |
| `digits_between:min,max` | Лише цифри, довжина між *min* і *max*.                  |
| `date`                   | Розбирається функцією `strtotime()`.                    |
| `date_format:fmt`        | Відповідає заданому формату дати PHP (наприклад, `Y-m-d`). |
| `json`                   | Коректний рядок JSON.                                   |
| `uuid`                   | Коректний UUID v1–v5.                                   |
| `mac_address`            | `AA:BB:CC:DD:EE:FF` (двокрапки або дефіси).             |
| `regex:/pattern/`        | Відповідає regex.                                       |
| `not_regex:/pattern/`    | **Не** відповідає regex.                                |
| `lowercase` / `uppercase`| Увесь рядок у нижньому/верхньому регістрі.              |

### Обмеження значення

| Правило | Проходить, коли |
|---------|-----------------|
| `min:n` / `max:n`        | Число ≥/≤ n; довжина рядка ≥/≤ n; кількість у масиві ≥/≤ n. |
| `between:min,max`        | Числове значення між min і max (включно).              |
| `size:n`                 | Точне значення / довжина рядка / кількість у масиві.    |
| `min_length:n` / `max_length:n` | Довжина рядка (незалежно від числового вмісту).  |
| `multiple_of:n`          | Число ділиться на n націло.                             |
| `in:a,b,c`               | Значення — один із перелічених варіантів.               |
| `not_in:a,b,c`           | Значення не входить до перелічених варіантів.           |
| `accepted` / `declined`  | Одне з `yes/on/1/true` (або `no/off/0/false`).          |
| `confirmed`              | Сусіднє поле `{name}_confirmation` існує і дорівнює.    |
| `same:other` / `different:other` | Значення дорівнює / відрізняється від іншого поля. |
| `starts_with:pfx` / `ends_with:sfx` | Рядок починається/закінчується заданим підрядком. |

### Правила для масивів

| Правило | Проходить, коли |
|---------|-----------------|
| `list`         | Ключі — `0, 1, 2, …` (без рядкових ключів, без пропусків). |
| `distinct`     | Усі значення масиву унікальні.                              |
| `min_items:n`  | У масиві щонайменше *n* елементів.                          |
| `max_items:n`  | У масиві щонайбільше *n* елементів.                         |

## 6. `required`, `nullable`, `sometimes` — коли що відбувається?

Найтонші правила системи. Запам’ятайте цю таблицю:

| Стан вводу                               | `required` | `nullable` | `sometimes` |
|------------------------------------------|:----------:|:----------:|:-----------:|
| Ключ повністю відсутній                  | ❌ не проходить | пропустити решту | пропустити все |
| Ключ є, значення `null` / `''` / `[]`    | ❌ не проходить | пропустити решту | виконати інші правила |
| Ключ є, реальне значення                 | виконати правила | виконати правила | виконати правила |

Простими словами:

- **`nullable`** — *«це поле можна залишити порожнім / null, але якщо воно заповнене, воно має задовольняти правила»*.
- **`sometimes`** — *«це поле може взагалі бути відсутнім у вхідних даних; якщо воно є, валідувати як зазвичай»*. Ідеально для PATCH-ендпоінтів.
- **`required`** — *«це поле має бути присутнім **і** непорожнім»*.

## 7. Вкладені дані з крапковими шляхами

```php
$v = new Validator($data, [
    'user.name'              => 'required|string|max:100',
    'user.email'             => 'required|email',
    'user.address.city'      => 'required|string',
    'user.address.zip'       => 'required|digits_between:5,10',
    'user.preferences.lang'  => 'required|in:en,ru,de,fr',
]);
```

Помилки мають ключ із тим самим крапковим шляхом:

```json
{ "errors": { "user.address.zip": ["The user.address.zip must be 5-10 digits."] } }
```

## 8. Підстановні знаки (`.*`) — валідація масивів сутностей

`.*` розгортається у *кожен елемент із цілочисловим індексом* батьківського масиву.

```php
$v = new Validator($data, [
    'tags'    => 'required|array|list|distinct|min_items:1|max_items:10',
    'tags.*'  => 'required|string|max:50|alpha_num',
]);
```

Ключі помилок стають `tags.0`, `tags.1`, … тож фронтенд може зіставити помилки з потрібним `<input>`.

Вкладені підстановні знаки (масив об’єктів):

```php
$v = new Validator($data, [
    'items'              => 'required|array|min_items:1|max_items:100',
    'items.*.name'       => 'required|string|max:200',
    'items.*.sku'        => 'required|string|regex:/^[A-Z0-9\-]+$/',
    'items.*.qty'        => 'required|integer|min:1',
    'items.*.tags'       => 'nullable|array|list|max_items:10',
    'items.*.tags.*'     => 'string|max:50',
]);
```

## 9. Правила-замикання — швидка вбудована логіка

Замикання отримує `($field, $value, $allData, $fail)`. Викличте `$fail("message")`, щоб позначити правило проваленим:

```php
$v = new Validator($data, [
    'slug' => [
        'required', 'string', 'min:3',
        function (string $field, mixed $value, array $data, \Closure $fail): void {
            if (str_contains($value, '--')) {
                $fail("The {$field} must not contain consecutive hyphens.");
            }
        },
    ],
]);
```

Замикання отримує **всі** дані — ідеально для міжполевих перевірок (`'end_date' >= 'start_date'` тощо).

## 10. Повторно використовувані класи правил (`RuleInterface`)

Для логіки, яку ви повторно використовуєте у 3+ місцях, оформіть її як клас:

```php
use Lift\Validation\RuleInterface;

final class PhoneRule implements RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool
    {
        return is_string($value) && preg_match('/^\+?[0-9]{10,15}$/', $value) === 1;
    }

    public function message(): string
    {
        return 'The :attribute must be a valid phone number.';
    }
}

$v = new Validator($data, [
    'phone' => ['required', new PhoneRule()],
]);
```

Плейсхолдер `:attribute` автоматично замінюється іменем поля. Перевизначте його для конкретного поля через масив власних повідомлень (наступний розділ).

## 11. Власні повідомлення про помилки

Передайте масив третім аргументом конструктора. Ключі — `"field.rule"` (найконкретніший) або просто `"rule"` (запасний варіант для всього правила). Плейсхолдери `:attribute`, `:min`, `:max`, `:value`, `:other`, `:when`, `:values` підставляються автоматично.

```php
$v = new Validator($data, $rules, [
    // На поле
    'email.required' => 'We need your email address.',
    'email.email'    => ':attribute does not look right.',

    // Запасний варіант для всіх полів, що використовують правило
    'required'       => 'This field is required.',
    'min'            => ':attribute must be at least :min.',
]);
```

Усередині `FormRequest` перевизначте `messages()`:

```php
public function messages(): array
{
    return [
        'password.min' => 'Password must be at least :min characters.',
    ];
}
```

## 12. Реєстрація власних правил глобально

Для правил, які мають бути доступні всюди (`'card' => 'required|luhn'`):

```php
use Lift\Validation\Validator;

// Форма-замикання
Validator::extend(
    'luhn',
    fn(string $field, mixed $value, array $data) => $this->checkLuhn($value),
    'The :attribute must be a valid card number.',
);

// Форма RuleInterface (використовує власний message())
Validator::extend('isbn13', new Isbn13Rule());
```

Реєструйте під час завантаження (наприклад, у `public/index.php` або файлі початкового завантаження).

## 13. Реальний приклад — замовлення в інтернет-магазині

```php
$data = $req->validate([
    // Заголовок замовлення
    'currency'    => 'required|string|size:3|uppercase',
    'coupon_code' => 'nullable|string|max:30|alpha_num',
    'note'        => 'nullable|string|max:1000',

    // Доставка
    'shipping.name'         => 'required|string|max:100',
    'shipping.line1'        => 'required|string|max:200',
    'shipping.line2'        => 'nullable|string|max:200',
    'shipping.city'         => 'required|string|max:100',
    'shipping.zip'          => 'required|digits_between:4,10',
    'shipping.country_code' => 'required|alpha|max:2|uppercase',

    // Позиції — 1..50
    'items'                       => 'required|array|list|min_items:1|max_items:50',
    'items.*.product_id'          => 'required|uuid',
    'items.*.qty'                 => 'required|integer|min:1|max:999',
    'items.*.unit_price'          => 'required|numeric|min:0',
    'items.*.promotions'          => 'nullable|array|list|max_items:5',
    'items.*.promotions.*'        => 'string|max:50',

    // Оплата
    'payment.method'         => 'required|in:card,paypal,bank_transfer',
    'payment.token'          => 'required_if:payment.method,card|string',
    'payment.paypal_email'   => 'required_if:payment.method,paypal|email',
    'payment.bank_reference' => 'required_if:payment.method,bank_transfer|string|max:100',
    'payment.save_card'      => 'prohibited_unless:payment.method,card|boolean',
]);
```

## 14. Локалізовані повідомлення про помилки

Передайте [Translator](localization) для виводу не англійською:

```php
use Lift\Translation\Translator;

// Глобальне значення за замовчуванням
Validator::setTranslator(new Translator('ru'));

// Або на екземпляр
$v = new Validator($data, $rules, [], new Translator('fr'));
```

Файл перекладів використовує ключі повідомлень на кшталт `validation.required`, `validation.email` тощо. Формат див. у [Локалізації](localization).

## 15. `ValidationException` — програмне використання

Коли потрібно провалити валідацію ззовні валідатора (наприклад, після запиту до БД):

```php
use Lift\Validation\ValidationException;

throw ValidationException::withErrors([
    'email' => ['This email is already registered.'],
]);
```

Обробник помилок Lift перетворює його на HTTP 422 так само, як будь-яку іншу невдачу валідації. Щоб перехопити й оглянути його:

```php
try {
    $data = $v->validated();
} catch (ValidationException $e) {
    $errors = $e->errors();   // ['field' => ['msg', …], …]
}
```

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Усі необов’язкові поля провалюються з `required` | Ви поставили `nullable` після правил, які вже провалилися | Ставте `nullable` першим: `'nullable\|string\|max:50'`. |
| `nullable` не допомагає, коли ключ відсутній | `nullable` обробляє лише порожні значення, не відсутні ключі | Використовуйте `sometimes` для «може взагалі бути відсутнім». |
| Підстановний знак валідує і рядкові ключі | `.*` розгортає лише елементи з цілочисловим індексом | Додайте `array\|list` на батька, щоб спершу забезпечити форму списку. |
| `min:5` відхилив `'12'` (рядок довжини 2) | `min` трактує числові рядки як числа | Використовуйте `min_length:5` для явної перевірки довжини рядка. |
| `confirmed` не спрацьовує | Сусіднє поле має бути точно `{name}_confirmation` | Перевірте написання — `password` → `password_confirmation`. |
| Власне правило ніколи не виконується | Ви додали його у замикання, яке повертає значення замість виклику `$fail()` | Замикання мають **викликати `$fail(...)`** за помилки, а не повертати false. |
| Усі помилки кажуть «The X field is invalid» | Немає власних повідомлень, відкат до загального шаблону | Додайте повідомлення або використовуйте глобальний перекладач. |

## Шпаргалка

```php
// Найчастіше: однорядковий код усередині обробника
$data = $req->validate([
    'email' => 'required|email',
    'age'   => 'integer|min:13',
]);

// Окремо
$v = new Validator($input, $rules, $customMessages = []);
$v->passes() / $v->fails() / $v->errors() / $v->validated();

// Власне правило
final class FooRule implements RuleInterface { … }
Validator::extend('foo', new FooRule());

// Викинути своє
throw ValidationException::withErrors(['email' => ['already taken']]);
```

[Кеш →](cache)
