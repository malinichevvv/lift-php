---
layout: page
title: Validation
nav_order: 19
---

# Validation

Lift's validator answers one question: **"does this input match the rules I expect?"** — and gives you a precise list of what failed.

It works on any associative array: HTTP request body, query string, JSON RPC params, CLI arguments, even a row read from another service. The DSL is intentionally similar to Laravel's so the learning curve is near zero.

> Mental model: you describe each field by a list of rules (`'required|email|max:255'`). The validator collects **every** failure (it doesn't stop at the first one) and gives you back either the cleaned data or an error map.

## 1. The 60-second tour

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

Three things to remember:

1. Rules can be a pipe-delimited string (`'required|email'`) or an array of rules/objects/closures (`['required', 'email', new MyRule()]`).
2. `$v->errors()` is `array<string, string[]>` — every field can have several error messages.
3. `$v->validated()` returns only the fields you declared rules for (clean DTO).

## 2. Validating inside a route

In an HTTP handler, the one-liner `$req->validate(...)` is the easiest path. It merges body + query + route params, runs the validator, **throws `ValidationException` on failure**, and otherwise returns the validated array. Lift's default error handler converts the exception to **HTTP 422** with the right JSON shape — you don't have to write a `try/catch`:

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

The response body on failure looks like:

```json
{
  "errors": {
    "email":    ["The email must be a valid email address."],
    "password": ["The password must be at least 8 characters."]
  }
}
```

For a typed, reusable container, use a [FormRequest](form-requests).

## 3. Return-value cheat sheet

| Method            | Returns                                | Notes                                       |
|-------------------|----------------------------------------|---------------------------------------------|
| `passes()`        | `bool`                                 | `true` when **all** rules pass              |
| `fails()`         | `bool`                                 | `!passes()`                                 |
| `errors()`        | `array<string, string[]>`              | field → list of messages                    |
| `validated()`     | `array<string, mixed>`                 | throws `ValidationException` on failure     |

## 4. Rule syntax

```php
// Pipe-delimited (compact, recommended for simple cases)
'email' => 'required|email|max:255'

// Array (lets you mix closures and rule objects)
'phone' => ['required', 'string', new PhoneRule()]
```

Rules run **in the order you list them**. `required`, `nullable`, and `sometimes` are special — they affect whether the rest of the chain runs at all (see §6 below).

Multiple errors per field are collected: validation does **not** stop at the first failure, so the user sees all problems at once.

## 5. Built-in rules — complete reference

### Presence & flow

| Rule | Description |
|------|-------------|
| `required` | Field must be present and non-empty (`''`, `[]`, `null` all fail). |
| `nullable` | If the field is absent / null / empty, skip the rest of the chain. |
| `sometimes` | If the key is **not in the input at all**, skip every rule. Great for PATCH. |
| `present` | Key must exist (value may be `null` or `''`). |
| `filled` | If the key exists, the value must not be empty. |

```php
'bio'        => 'nullable|string|max:500',          // empty string is fine
'avatar_url' => 'sometimes|url',                    // can be absent on PATCH
'profile.id' => 'present',                          // must show up, even as null
```

### Conditional required / prohibited

Reference any other field via dot path. Both top-level and nested keys work.

| Rule | Description |
|------|-------------|
| `required_if:field,value`        | Required when *field* equals *value*.              |
| `required_unless:field,value`    | Required unless *field* equals *value*.            |
| `required_with:f1,f2,...`        | Required if **any** listed field is non-empty.     |
| `required_without:f1,f2,...`     | Required if **any** listed field is absent/empty.  |
| `prohibited`                     | Field must be absent or empty.                     |
| `prohibited_if:field,value`      | Prohibited when *field* equals *value*.            |
| `prohibited_unless:field,value`  | Prohibited unless *field* equals *value*.          |

```php
'type'         => 'required|in:individual,company',
'company.name' => 'required_if:type,company|string|max:200',
'person.dob'   => 'required_unless:type,company|date',

'admin_token'  => 'prohibited_unless:role,admin|string',
'password'     => 'prohibited_if:role,guest|string|min:8',
```

### Type

| Rule | Passes when |
|------|-------------|
| `string`               | Value is a PHP string.                                    |
| `integer` / `int`      | Numeric integer (accepts `"42"`).                         |
| `float` / `numeric`    | Numeric (int or float).                                   |
| `boolean` / `bool`     | One of `true`, `false`, `1`, `0`, `"1"`, `"0"`, `"true"`, `"false"`. |
| `array`                | PHP array.                                                |

### Format

| Rule | Passes when |
|------|-------------|
| `email`                  | Valid email address.                                    |
| `url`                    | Valid URL.                                              |
| `ip` / `ipv4` / `ipv6`   | Matching IP address.                                    |
| `alpha`                  | ASCII letters only.                                     |
| `alpha_num`              | ASCII letters + digits only.                            |
| `digits`                 | Only digit characters.                                  |
| `digits_between:min,max` | Only digits, length between *min* and *max*.            |
| `date`                   | Parseable by `strtotime()`.                             |
| `date_format:fmt`        | Matches the given PHP date format (e.g. `Y-m-d`).       |
| `json`                   | Valid JSON string.                                      |
| `uuid`                   | Valid UUID v1–v5.                                       |
| `mac_address`            | `AA:BB:CC:DD:EE:FF` (colons or hyphens).                |
| `regex:/pattern/`        | Matches the regex.                                      |
| `not_regex:/pattern/`    | Does **not** match the regex.                           |
| `lowercase` / `uppercase`| Entire string is lower-/upper-cased.                    |

### Value constraints

| Rule | Passes when |
|------|-------------|
| `min:n` / `max:n`        | Numeric ≥/≤ n; string-length ≥/≤ n; array count ≥/≤ n.  |
| `between:min,max`        | Numeric value between min and max (inclusive).          |
| `size:n`                 | Exact value / string length / array count.              |
| `min_length:n` / `max_length:n` | String length (regardless of numeric content).   |
| `multiple_of:n`          | Numeric is divisible by n.                              |
| `in:a,b,c`               | Value is one of the listed options.                     |
| `not_in:a,b,c`           | Value is not one of the listed options.                 |
| `accepted` / `declined`  | One of `yes/on/1/true` (or `no/off/0/false`).           |
| `confirmed`              | Sibling field `{name}_confirmation` exists and equals.  |
| `same:other` / `different:other` | Value equals / differs from another field.      |
| `starts_with:pfx` / `ends_with:sfx` | String starts/ends with the given substring. |

### Array rules

| Rule | Passes when |
|------|-------------|
| `list`         | Keys are `0, 1, 2, …` (no string keys, no gaps).             |
| `distinct`     | All array values are unique.                                 |
| `min_items:n`  | Array has at least *n* elements.                             |
| `max_items:n`  | Array has at most *n* elements.                              |

## 6. `required`, `nullable`, `sometimes` — when does what happen?

Most subtle rules of the system. Memorise this table:

| Input state                          | `required` | `nullable` | `sometimes` |
|--------------------------------------|:----------:|:----------:|:-----------:|
| Key missing entirely                 | ❌ fails   | skip rest  | skip everything |
| Key present, value `null` / `''` / `[]` | ❌ fails | skip rest  | run other rules |
| Key present, real value              | run rules  | run rules  | run rules   |

In English:

- **`nullable`** — *"this field may be left empty / null, but if it's filled it must satisfy the rules"*.
- **`sometimes`** — *"this field may be missing from the input entirely; if it's present, validate normally"*. Perfect for PATCH endpoints.
- **`required`** — *"this field must be present **and** non-empty"*.

## 7. Nested data with dot paths

```php
$v = new Validator($data, [
    'user.name'              => 'required|string|max:100',
    'user.email'             => 'required|email',
    'user.address.city'      => 'required|string',
    'user.address.zip'       => 'required|digits_between:5,10',
    'user.preferences.lang'  => 'required|in:en,ru,de,fr',
]);
```

Errors are keyed by the same dot path:

```json
{ "errors": { "user.address.zip": ["The user.address.zip must be 5-10 digits."] } }
```

## 8. Wildcards (`.*`) — validate arrays of things

`.*` expands to *every integer-indexed element* of the parent array.

```php
$v = new Validator($data, [
    'tags'    => 'required|array|list|distinct|min_items:1|max_items:10',
    'tags.*'  => 'required|string|max:50|alpha_num',
]);
```

Error keys become `tags.0`, `tags.1`, … so the front-end can map errors to the right `<input>`.

Nested wildcards (array of objects):

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

## 9. Closure rules — quick inline logic

A closure receives `($field, $value, $allData, $fail)`. Call `$fail("message")` to mark the rule failed:

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

The closure gets **all** the data — perfect for cross-field checks (`'end_date' >= 'start_date'`, etc.).

## 10. Reusable rule classes (`RuleInterface`)

For logic you'll reuse in 3+ places, package it as a class:

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

The `:attribute` placeholder is replaced with the field name automatically. Override it per-field through the custom-messages array (next section).

## 11. Custom error messages

Pass an array as the third constructor argument. Keys are `"field.rule"` (most specific) or just `"rule"` (rule-wide fallback). Placeholders `:attribute`, `:min`, `:max`, `:value`, `:other`, `:when`, `:values` are substituted automatically.

```php
$v = new Validator($data, $rules, [
    // Per-field
    'email.required' => 'We need your email address.',
    'email.email'    => ':attribute does not look right.',

    // Fallback for all fields using a rule
    'required'       => 'This field is required.',
    'min'            => ':attribute must be at least :min.',
]);
```

Inside a `FormRequest` override `messages()`:

```php
public function messages(): array
{
    return [
        'password.min' => 'Password must be at least :min characters.',
    ];
}
```

## 12. Registering custom rules globally

For rules you want available everywhere (`'card' => 'required|luhn'`):

```php
use Lift\Validation\Validator;

// Closure form
Validator::extend(
    'luhn',
    fn(string $field, mixed $value, array $data) => $this->checkLuhn($value),
    'The :attribute must be a valid card number.',
);

// RuleInterface form (uses its own message())
Validator::extend('isbn13', new Isbn13Rule());
```

Register at boot (e.g. in `public/index.php` or a bootstrap file).

## 13. Real-world example — e-commerce order

```php
$data = $req->validate([
    // Order header
    'currency'    => 'required|string|size:3|uppercase',
    'coupon_code' => 'nullable|string|max:30|alpha_num',
    'note'        => 'nullable|string|max:1000',

    // Shipping
    'shipping.name'         => 'required|string|max:100',
    'shipping.line1'        => 'required|string|max:200',
    'shipping.line2'        => 'nullable|string|max:200',
    'shipping.city'         => 'required|string|max:100',
    'shipping.zip'          => 'required|digits_between:4,10',
    'shipping.country_code' => 'required|alpha|max:2|uppercase',

    // Line items — 1..50
    'items'                       => 'required|array|list|min_items:1|max_items:50',
    'items.*.product_id'          => 'required|uuid',
    'items.*.qty'                 => 'required|integer|min:1|max:999',
    'items.*.unit_price'          => 'required|numeric|min:0',
    'items.*.promotions'          => 'nullable|array|list|max_items:5',
    'items.*.promotions.*'        => 'string|max:50',

    // Payment
    'payment.method'         => 'required|in:card,paypal,bank_transfer',
    'payment.token'          => 'required_if:payment.method,card|string',
    'payment.paypal_email'   => 'required_if:payment.method,paypal|email',
    'payment.bank_reference' => 'required_if:payment.method,bank_transfer|string|max:100',
    'payment.save_card'      => 'prohibited_unless:payment.method,card|boolean',
]);
```

## 14. Localised error messages

Pass a [Translator](localization) for non-English output:

```php
use Lift\Translation\Translator;

// Global default
Validator::setTranslator(new Translator('ru'));

// Or per-instance
$v = new Validator($data, $rules, [], new Translator('fr'));
```

The translation file uses message keys like `validation.required`, `validation.email`, etc. See [Localization](localization) for the format.

## 15. `ValidationException` — programmatic use

For when you need to fail validation from outside the validator (e.g. after a DB lookup):

```php
use Lift\Validation\ValidationException;

throw ValidationException::withErrors([
    'email' => ['This email is already registered.'],
]);
```

Lift's error handler converts it to HTTP 422 just like any other validation failure. To catch and inspect it:

```php
try {
    $data = $v->validated();
} catch (ValidationException $e) {
    $errors = $e->errors();   // ['field' => ['msg', …], …]
}
```

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| All optional fields fail with `required` | You put `nullable` after rules that already failed | Put `nullable` first: `'nullable\|string\|max:50'`. |
| `nullable` doesn't help when the key is missing | `nullable` only handles empty values, not missing keys | Use `sometimes` for "may be absent entirely". |
| Wildcard validates string keys too | `.*` only expands int-indexed elements | Add `array\|list` on the parent to enforce list-shape first. |
| `min:5` rejected `'12'` (string of length 2) | `min` treats numeric strings as numbers | Use `min_length:5` for an explicit string-length check. |
| `confirmed` doesn't trigger | Sibling field must be exactly `{name}_confirmation` | Check spelling — `password` → `password_confirmation`. |
| Custom rule never runs | You added it to a closure that returns instead of calls `$fail()` | Closures should **call `$fail(...)`** on failure, not return false. |
| All errors say "The X field is invalid" | No custom messages, falling back to the generic template | Add messages or use the global translator. |

## Cheat sheet

```php
// Most common: one-liner inside a handler
$data = $req->validate([
    'email' => 'required|email',
    'age'   => 'integer|min:13',
]);

// Standalone
$v = new Validator($input, $rules, $customMessages = []);
$v->passes() / $v->fails() / $v->errors() / $v->validated();

// Custom rule
final class FooRule implements RuleInterface { … }
Validator::extend('foo', new FooRule());

// Throw your own
throw ValidationException::withErrors(['email' => ['already taken']]);
```

[Cache →](cache)
