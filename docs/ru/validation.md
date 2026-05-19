---
layout: page
title: Валидация
nav_order: 19
---

# Валидация

Валидатор Lift отвечает на один вопрос: **«соответствуют ли эти входные данные ожидаемым мной правилам?»** — и даёт точный список того, что не прошло.

Он работает с любым ассоциативным массивом: тело HTTP-запроса, строка запроса, параметры JSON-RPC, аргументы CLI, даже строка, прочитанная из другого сервиса. DSL намеренно похож на Laravel, поэтому кривая обучения почти нулевая.

> Ментальная модель: вы описываете каждое поле списком правил (`'required|email|max:255'`). Валидатор собирает **все** ошибки (он не останавливается на первой) и возвращает либо очищенные данные, либо карту ошибок.

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

Три вещи, которые нужно помнить:

1. Правила могут быть строкой через вертикальную черту (`'required|email'`) или массивом правил/объектов/замыканий (`['required', 'email', new MyRule()]`).
2. `$v->errors()` — это `array<string, string[]>` — у каждого поля может быть несколько сообщений об ошибках.
3. `$v->validated()` возвращает только те поля, для которых вы объявили правила (чистый DTO).

## 2. Валидация внутри маршрута

В HTTP-обработчике однострочник `$req->validate(...)` — самый простой путь. Он объединяет тело + query + параметры маршрута, запускает валидатор, **выбрасывает `ValidationException` при ошибке**, а иначе возвращает валидированный массив. Обработчик ошибок Lift по умолчанию преобразует исключение в **HTTP 422** с правильной формой JSON — `try/catch` писать не нужно:

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

Тело ответа при ошибке выглядит так:

```json
{
  "errors": {
    "email":    ["The email must be a valid email address."],
    "password": ["The password must be at least 8 characters."]
  }
}
```

Для типизированного, переиспользуемого контейнера используйте [FormRequest](form-requests).

## 3. Шпаргалка по возвращаемым значениям

| Метод             | Возвращает                             | Примечания                                  |
|-------------------|----------------------------------------|---------------------------------------------|
| `passes()`        | `bool`                                 | `true`, когда проходят **все** правила      |
| `fails()`         | `bool`                                 | `!passes()`                                 |
| `errors()`        | `array<string, string[]>`              | поле → список сообщений                      |
| `validated()`     | `array<string, mixed>`                 | выбрасывает `ValidationException` при ошибке |

## 4. Синтаксис правил

```php
// Через вертикальную черту (компактно, рекомендуется для простых случаев)
'email' => 'required|email|max:255'

// Массив (позволяет смешивать замыкания и объекты правил)
'phone' => ['required', 'string', new PhoneRule()]
```

Правила выполняются **в том порядке, в котором вы их перечислили**. `required`, `nullable` и `sometimes` особенные — они влияют на то, выполняется ли остальная цепочка вообще (см. §6 ниже).

Несколько ошибок на поле собираются: валидация **не** останавливается на первой неудаче, поэтому пользователь видит все проблемы сразу.

## 5. Встроенные правила — полный справочник

### Присутствие и поток

| Правило | Описание |
|---------|----------|
| `required` | Поле должно присутствовать и быть непустым (`''`, `[]`, `null` все не проходят). |
| `nullable` | Если поле отсутствует / null / пустое, пропустить остаток цепочки. |
| `sometimes` | Если ключа **вообще нет во входных данных**, пропустить все правила. Отлично для PATCH. |
| `present` | Ключ должен существовать (значение может быть `null` или `''`). |
| `filled` | Если ключ существует, значение не должно быть пустым. |

```php
'bio'        => 'nullable|string|max:500',          // пустая строка допустима
'avatar_url' => 'sometimes|url',                    // может отсутствовать при PATCH
'profile.id' => 'present',                          // должен присутствовать, даже как null
```

### Условное required / prohibited

Ссылайтесь на любое другое поле через точечный путь. Работают и поля верхнего уровня, и вложенные.

| Правило | Описание |
|---------|----------|
| `required_if:field,value`        | Обязательно, когда *field* равно *value*.          |
| `required_unless:field,value`    | Обязательно, если только *field* не равно *value*. |
| `required_with:f1,f2,...`        | Обязательно, если **любое** из перечисленных полей непустое. |
| `required_without:f1,f2,...`     | Обязательно, если **любое** из перечисленных полей отсутствует/пустое. |
| `prohibited`                     | Поле должно отсутствовать или быть пустым.         |
| `prohibited_if:field,value`      | Запрещено, когда *field* равно *value*.            |
| `prohibited_unless:field,value`  | Запрещено, если только *field* не равно *value*.   |

```php
'type'         => 'required|in:individual,company',
'company.name' => 'required_if:type,company|string|max:200',
'person.dob'   => 'required_unless:type,company|date',

'admin_token'  => 'prohibited_unless:role,admin|string',
'password'     => 'prohibited_if:role,guest|string|min:8',
```

### Тип

| Правило | Проходит, когда |
|---------|-----------------|
| `string`               | Значение — строка PHP.                                    |
| `integer` / `int`      | Числовое целое (принимает `"42"`).                        |
| `float` / `numeric`    | Числовое (int или float).                                 |
| `boolean` / `bool`     | Одно из `true`, `false`, `1`, `0`, `"1"`, `"0"`, `"true"`, `"false"`. |
| `array`                | Массив PHP.                                               |

### Формат

| Правило | Проходит, когда |
|---------|-----------------|
| `email`                  | Корректный адрес электронной почты.                     |
| `url`                    | Корректный URL.                                         |
| `ip` / `ipv4` / `ipv6`   | Соответствующий IP-адрес.                               |
| `alpha`                  | Только ASCII-буквы.                                     |
| `alpha_num`              | Только ASCII-буквы + цифры.                             |
| `digits`                 | Только цифровые символы.                                |
| `digits_between:min,max` | Только цифры, длина между *min* и *max*.                |
| `date`                   | Разбирается функцией `strtotime()`.                     |
| `date_format:fmt`        | Соответствует заданному формату даты PHP (например, `Y-m-d`). |
| `json`                   | Корректная строка JSON.                                 |
| `uuid`                   | Корректный UUID v1–v5.                                  |
| `mac_address`            | `AA:BB:CC:DD:EE:FF` (двоеточия или дефисы).             |
| `regex:/pattern/`        | Соответствует regex.                                    |
| `not_regex:/pattern/`    | **Не** соответствует regex.                             |
| `lowercase` / `uppercase`| Вся строка в нижнем/верхнем регистре.                   |

### Ограничения значения

| Правило | Проходит, когда |
|---------|-----------------|
| `min:n` / `max:n`        | Число ≥/≤ n; длина строки ≥/≤ n; количество в массиве ≥/≤ n. |
| `between:min,max`        | Числовое значение между min и max (включительно).      |
| `size:n`                 | Точное значение / длина строки / количество в массиве.  |
| `min_length:n` / `max_length:n` | Длина строки (независимо от числового содержимого). |
| `multiple_of:n`          | Число делится на n нацело.                              |
| `in:a,b,c`               | Значение — один из перечисленных вариантов.             |
| `not_in:a,b,c`           | Значение не входит в перечисленные варианты.            |
| `accepted` / `declined`  | Одно из `yes/on/1/true` (или `no/off/0/false`).         |
| `confirmed`              | Соседнее поле `{name}_confirmation` существует и равно. |
| `same:other` / `different:other` | Значение равно / отличается от другого поля.    |
| `starts_with:pfx` / `ends_with:sfx` | Строка начинается/заканчивается заданной подстрокой. |

### Правила для массивов

| Правило | Проходит, когда |
|---------|-----------------|
| `list`         | Ключи — `0, 1, 2, …` (без строковых ключей, без пропусков). |
| `distinct`     | Все значения массива уникальны.                             |
| `min_items:n`  | В массиве не менее *n* элементов.                           |
| `max_items:n`  | В массиве не более *n* элементов.                           |

## 6. `required`, `nullable`, `sometimes` — когда что происходит?

Самые тонкие правила системы. Запомните эту таблицу:

| Состояние ввода                          | `required` | `nullable` | `sometimes` |
|------------------------------------------|:----------:|:----------:|:-----------:|
| Ключ полностью отсутствует               | ❌ не проходит | пропустить остаток | пропустить всё |
| Ключ есть, значение `null` / `''` / `[]` | ❌ не проходит | пропустить остаток | выполнить остальные правила |
| Ключ есть, реальное значение             | выполнить правила | выполнить правила | выполнить правила |

Простыми словами:

- **`nullable`** — *«это поле можно оставить пустым / null, но если оно заполнено, оно должно удовлетворять правилам»*.
- **`sometimes`** — *«это поле может вообще отсутствовать во входных данных; если оно есть, валидировать как обычно»*. Идеально для PATCH-эндпоинтов.
- **`required`** — *«это поле должно присутствовать **и** быть непустым»*.

## 7. Вложенные данные с точечными путями

```php
$v = new Validator($data, [
    'user.name'              => 'required|string|max:100',
    'user.email'             => 'required|email',
    'user.address.city'      => 'required|string',
    'user.address.zip'       => 'required|digits_between:5,10',
    'user.preferences.lang'  => 'required|in:en,ru,de,fr',
]);
```

Ошибки имеют ключ с тем же точечным путём:

```json
{ "errors": { "user.address.zip": ["The user.address.zip must be 5-10 digits."] } }
```

## 8. Подстановочные знаки (`.*`) — валидация массивов сущностей

`.*` раскрывается в *каждый элемент с целочисленным индексом* родительского массива.

```php
$v = new Validator($data, [
    'tags'    => 'required|array|list|distinct|min_items:1|max_items:10',
    'tags.*'  => 'required|string|max:50|alpha_num',
]);
```

Ключи ошибок становятся `tags.0`, `tags.1`, … так что фронтенд может сопоставить ошибки с нужным `<input>`.

Вложенные подстановочные знаки (массив объектов):

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

## 9. Правила-замыкания — быстрая встроенная логика

Замыкание получает `($field, $value, $allData, $fail)`. Вызовите `$fail("message")`, чтобы отметить правило проваленным:

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

Замыкание получает **все** данные — идеально для межполевых проверок (`'end_date' >= 'start_date'` и т. п.).

## 10. Переиспользуемые классы правил (`RuleInterface`)

Для логики, которую вы переиспользуете в 3+ местах, оформите её как класс:

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

Плейсхолдер `:attribute` автоматически заменяется именем поля. Переопределите его для конкретного поля через массив собственных сообщений (следующий раздел).

## 11. Собственные сообщения об ошибках

Передайте массив третьим аргументом конструктора. Ключи — `"field.rule"` (наиболее конкретный) или просто `"rule"` (запасной вариант для всего правила). Плейсхолдеры `:attribute`, `:min`, `:max`, `:value`, `:other`, `:when`, `:values` подставляются автоматически.

```php
$v = new Validator($data, $rules, [
    // На поле
    'email.required' => 'We need your email address.',
    'email.email'    => ':attribute does not look right.',

    // Запасной вариант для всех полей, использующих правило
    'required'       => 'This field is required.',
    'min'            => ':attribute must be at least :min.',
]);
```

Внутри `FormRequest` переопределите `messages()`:

```php
public function messages(): array
{
    return [
        'password.min' => 'Password must be at least :min characters.',
    ];
}
```

## 12. Регистрация собственных правил глобально

Для правил, которые должны быть доступны везде (`'card' => 'required|luhn'`):

```php
use Lift\Validation\Validator;

// Форма-замыкание
Validator::extend(
    'luhn',
    fn(string $field, mixed $value, array $data) => $this->checkLuhn($value),
    'The :attribute must be a valid card number.',
);

// Форма RuleInterface (использует собственный message())
Validator::extend('isbn13', new Isbn13Rule());
```

Регистрируйте при загрузке (например, в `public/index.php` или файле начальной загрузки).

## 13. Реальный пример — заказ в интернет-магазине

```php
$data = $req->validate([
    // Заголовок заказа
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

    // Позиции — 1..50
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

## 14. Локализованные сообщения об ошибках

Передайте [Translator](localization) для вывода не на английском:

```php
use Lift\Translation\Translator;

// Глобальное значение по умолчанию
Validator::setTranslator(new Translator('ru'));

// Или на экземпляр
$v = new Validator($data, $rules, [], new Translator('fr'));
```

Файл переводов использует ключи сообщений вроде `validation.required`, `validation.email` и т. д. Формат см. в [Локализации](localization).

## 15. `ValidationException` — программное использование

Когда нужно провалить валидацию извне валидатора (например, после запроса к БД):

```php
use Lift\Validation\ValidationException;

throw ValidationException::withErrors([
    'email' => ['This email is already registered.'],
]);
```

Обработчик ошибок Lift преобразует его в HTTP 422 так же, как любую другую неудачу валидации. Чтобы перехватить и осмотреть его:

```php
try {
    $data = $v->validated();
} catch (ValidationException $e) {
    $errors = $e->errors();   // ['field' => ['msg', …], …]
}
```

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Все необязательные поля проваливаются с `required` | Вы поставили `nullable` после правил, которые уже провалились | Ставьте `nullable` первым: `'nullable\|string\|max:50'`. |
| `nullable` не помогает, когда ключ отсутствует | `nullable` обрабатывает только пустые значения, не отсутствующие ключи | Используйте `sometimes` для «может вообще отсутствовать». |
| Подстановочный знак валидирует и строковые ключи | `.*` раскрывает только элементы с целочисленным индексом | Добавьте `array\|list` на родителя, чтобы сначала обеспечить форму списка. |
| `min:5` отклонил `'12'` (строку длины 2) | `min` трактует числовые строки как числа | Используйте `min_length:5` для явной проверки длины строки. |
| `confirmed` не срабатывает | Соседнее поле должно быть точно `{name}_confirmation` | Проверьте написание — `password` → `password_confirmation`. |
| Собственное правило никогда не выполняется | Вы добавили его в замыкание, которое возвращает значение вместо вызова `$fail()` | Замыкания должны **вызывать `$fail(...)`** при ошибке, а не возвращать false. |
| Все ошибки говорят «The X field is invalid» | Нет собственных сообщений, откат к общему шаблону | Добавьте сообщения или используйте глобальный переводчик. |

## Шпаргалка

```php
// Самое частое: однострочник внутри обработчика
$data = $req->validate([
    'email' => 'required|email',
    'age'   => 'integer|min:13',
]);

// Отдельно
$v = new Validator($input, $rules, $customMessages = []);
$v->passes() / $v->fails() / $v->errors() / $v->validated();

// Собственное правило
final class FooRule implements RuleInterface { … }
Validator::extend('foo', new FooRule());

// Выбросить своё
throw ValidationException::withErrors(['email' => ['already taken']]);
```

[Кэш →](cache)
