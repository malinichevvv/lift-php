---
layout: page
title: Validation
nav_order: 8
---

# Validation

`Lift\Validation\Validator` validates any associative array — request input, deserialized JSON, CLI arguments — against a set of typed rules. The syntax is intentionally similar to Laravel's so the learning curve is minimal.

## Basic usage

```php
use Lift\Validation\Validator;

$v = new Validator($request->all(), [
    'name'     => 'required|string|max:255',
    'email'    => 'required|email',
    'age'      => 'required|integer|min:18|max:120',
    'password' => 'required|min_length:8|confirmed',
    'role'     => 'required|in:admin,user,moderator',
    'website'  => 'nullable|url',
]);

if ($v->fails()) {
    return Response::json(['errors' => $v->errors()], 422);
}

$data = $v->validated(); // only fields that have rules
```

### Return values

| Method | Returns | Notes |
|--------|---------|-------|
| `passes()` | `bool` | `true` when all rules pass |
| `fails()` | `bool` | `!passes()` |
| `errors()` | `array<string, string[]>` | Field → list of error messages |
| `validated()` | `array<string, mixed>` | Throws `ValidationException` on failure |

---

## Rule syntax

Rules are written as pipe-delimited strings or as arrays of strings, objects, and closures:

```php
// Pipe-delimited string (compact)
'email' => 'required|email|max:255'

// Array (allows mixing closures and rule objects)
'phone' => ['required', 'string', new PhoneRule()]
```

Multiple errors per field are collected — validation does not stop at the first failure.

---

## Nested data

### Dot notation

Access any depth of nesting with dot notation. Each segment is an array key:

```php
$v = new Validator($data, [
    'user.name'              => 'required|string|max:100',
    'user.email'             => 'required|email',
    'user.address.city'      => 'required|string',
    'user.address.zip'       => 'required|digits_between:5,10',
    'user.address.country'   => 'required|alpha|max:2',
    'user.preferences.lang'  => 'required|in:en,ru,de,fr',
]);
```

Input that satisfies the rules above:

```php
$data = [
    'user' => [
        'name'  => 'Alice',
        'email' => 'alice@example.com',
        'address' => [
            'city'    => 'Berlin',
            'zip'     => '10115',
            'country' => 'DE',
        ],
        'preferences' => ['lang' => 'de'],
    ],
];
```

---

### Wildcards (`.*`)

`.*` expands to every integer-indexed element of the parent array. Rules without
a wildcard apply to the array itself; rules with `.*` apply to each element.

#### Flat list of scalars

```php
$v = new Validator($data, [
    'tags'   => 'required|array|list|distinct|min_items:1|max_items:10',
    'tags.*' => 'required|string|max:50|alpha_num',
]);
```

Error keys are `tags.0`, `tags.1`, … for individual element failures.

#### List of objects

```php
$v = new Validator($data, [
    'items'              => 'required|array|min_items:1|max_items:100',
    'items.*.name'       => 'required|string|max:200',
    'items.*.sku'        => 'required|string|regex:/^[A-Z0-9\-]{3,20}$/',
    'items.*.qty'        => 'required|integer|min:1|max:9999',
    'items.*.price'      => 'required|numeric|min:0',
    'items.*.taxable'    => 'required|boolean',
    'items.*.categories' => 'required|array|list|min_items:1',
    'items.*.categories.*' => 'required|string|max:50',
]);
```

Nested wildcards produce error keys like `items.2.categories.0`.

#### Deeply nested — three levels

```php
$v = new Validator($data, [
    'orders'                          => 'required|array|min_items:1',
    'orders.*.id'                     => 'required|uuid',
    'orders.*.lines'                  => 'required|array|min_items:1',
    'orders.*.lines.*.product_id'     => 'required|uuid',
    'orders.*.lines.*.quantity'       => 'required|integer|min:1',
    'orders.*.lines.*.discounts'      => 'nullable|array',
    'orders.*.lines.*.discounts.*'    => 'numeric|between:0,100',
]);
```

Sample error array when `orders[1].lines[0].product_id` is missing:

```php
[
    'orders.1.lines.0.product_id' => ['The orders.1.lines.0.product_id field is required.'],
]
```

---

### Array-level rules

Apply constraints to the array as a whole before validating individual elements:

| Rule | What it enforces on the array |
|------|-------------------------------|
| `array` | Value must be a PHP array. |
| `list` | Keys must be `0, 1, 2, …` (no string keys, no gaps). |
| `distinct` | All values must be unique. |
| `min_items:n` | At least *n* elements. |
| `max_items:n` | At most *n* elements. |

```php
$v = new Validator($data, [
    // Unique list of 1–5 role strings
    'roles'   => 'required|array|list|distinct|min_items:1|max_items:5',
    'roles.*' => 'required|string|in:admin,editor,viewer',

    // Map: string keys allowed (not list), 1–20 entries
    'meta'    => 'required|array|min_items:1|max_items:20',
    'meta.*'  => 'required|string|max:255',
]);
```

---

### `sometimes` — optional nested objects (PATCH)

`sometimes` skips **all** rules for a field when that key is absent from the
input entirely. Use it to validate partial updates without marking every field
optional:

```php
$v = new Validator($data, [
    // These three are always required
    'id'    => 'required|uuid',
    'email' => 'required|email',

    // Only validated when present in the request
    'profile.bio'       => 'sometimes|string|max:500',
    'profile.avatar'    => 'sometimes|url',
    'address.city'      => 'sometimes|string',
    'address.zip'       => 'sometimes|digits_between:5,10',

    // Optional list — validated fully only when provided
    'tags'   => 'sometimes|array|list|distinct|max_items:20',
    'tags.*' => 'string|max:50',
]);
```

`nullable` vs `sometimes`:
- **`nullable`** — key may exist with a `null`/empty value; subsequent rules are
  skipped for that field only.
- **`sometimes`** — key is allowed to be missing entirely; all rules are skipped.

---

### `present` and `filled` on nested fields

```php
$v = new Validator($data, [
    // Key must exist even if null — useful for explicit nulling in PATCH bodies
    'settings.theme'    => 'present|nullable|string',

    // Key may be absent, but if it exists the value must not be empty
    'settings.language' => 'filled|string|in:en,ru,de',
]);
```

---

### Conditional rules on nested fields

#### `required_if` / `required_unless`

Reference any field in `$data` — including top-level fields — from within a
nested rule:

```php
$v = new Validator($data, [
    'type'              => 'required|in:individual,company',

    // Required only for company accounts
    'company.name'      => 'required_if:type,company|string|max:200',
    'company.vat'       => 'required_if:type,company|string|max:30',

    // Required for individuals but not companies
    'person.first_name' => 'required_unless:type,company|string|max:100',
    'person.last_name'  => 'required_unless:type,company|string|max:100',
]);
```

#### `required_with` / `required_without`

```php
$v = new Validator($data, [
    'shipping.address' => 'sometimes|string',
    'shipping.city'    => 'required_with:shipping.address|string',
    'shipping.zip'     => 'required_with:shipping.address|digits_between:5,10',

    // At least one contact method is enough
    'contact.email'    => 'required_without:contact.phone|email',
    'contact.phone'    => 'required_without:contact.email|string|max:20',
]);
```

#### `prohibited_if` / `prohibited_unless`

```php
$v = new Validator($data, [
    'role'              => 'required|in:user,admin',
    'permissions'       => 'required|array',
    'permissions.*'     => 'required|string',

    // Admin-only fields
    'admin_token'       => 'prohibited_unless:role,admin|string',

    // Guest accounts cannot set a password
    'password'          => 'prohibited_if:role,guest|string|min_length:8',
]);
```

---

### Custom closure rules on array elements

Closures receive the full `$data` array as their third argument, so you can
cross-reference sibling or parent fields:

```php
$v = new Validator($data, [
    'currency'     => 'required|in:USD,EUR,GBP',
    'items'        => 'required|array|min_items:1',
    'items.*.name' => 'required|string',
    'items.*.price' => [
        'required',
        'numeric',
        'min:0',
        function (string $field, mixed $value, array $data, \Closure $fail): void {
            // Require integer amounts for non-USD currencies (no cents)
            if (($data['currency'] ?? 'USD') !== 'USD' && floor((float)$value) !== (float)$value) {
                $fail("$field must be a whole number for non-USD currencies.");
            }
        },
    ],
]);
```

#### RuleInterface with cross-field access

```php
use Lift\Validation\RuleInterface;

final class UniqueSkuRule implements RuleInterface
{
    public function __construct(private readonly array $existingSkus) {}

    public function passes(string $field, mixed $value, array $data): bool
    {
        // Also ensure no duplicate SKUs within the submitted batch itself
        $submitted = array_column($data['items'] ?? [], 'sku');
        $occurrences = array_count_values($submitted);
        return !in_array($value, $this->existingSkus, true)
            && ($occurrences[$value] ?? 0) <= 1;
    }

    public function message(): string
    {
        return 'The :attribute SKU is already taken or duplicated in this batch.';
    }
}

$v = new Validator($data, [
    'items'       => 'required|array|min_items:1',
    'items.*.sku' => ['required', 'string', new UniqueSkuRule($existingSkus)],
]);
```

---

### Error key format

Dot paths are preserved verbatim in the errors map, with `*` replaced by the
actual index:

```php
$v = new Validator([
    'items' => [
        ['name' => ''],       // index 0 — empty name
        ['name' => 'Widget'], // index 1 — ok
        [],                   // index 2 — name missing
    ],
], [
    'items.*.name' => 'required|string|min_length:2',
]);

$v->errors();
// [
//   'items.0.name' => ['The items.0.name field is required.'],
//   'items.2.name' => ['The items.2.name field is required.'],
// ]
```

Use this key format when returning structured API errors or when mapping errors
back onto front-end form fields.

---

### Complete real-world example — e-commerce order

```php
$v = new Validator($request->all(), [
    // Order header
    'currency'              => 'required|string|size:3|uppercase',
    'coupon_code'           => 'nullable|string|max:30|alpha_num',
    'note'                  => 'nullable|string|max:1000',

    // Shipping address
    'shipping.name'         => 'required|string|max:100',
    'shipping.line1'        => 'required|string|max:200',
    'shipping.line2'        => 'nullable|string|max:200',
    'shipping.city'         => 'required|string|max:100',
    'shipping.zip'          => 'required|digits_between:4,10',
    'shipping.country_code' => 'required|alpha|max:2|uppercase',

    // Line items — at least 1, at most 50
    'items'                       => 'required|array|list|min_items:1|max_items:50',
    'items.*.product_id'          => 'required|uuid',
    'items.*.variant_id'          => 'nullable|uuid',
    'items.*.qty'                 => 'required|integer|min:1|max:999',
    'items.*.unit_price'          => 'required|numeric|min:0',

    // Per-item applied promotions (optional sub-list)
    'items.*.promotions'          => 'nullable|array|list|max_items:5',
    'items.*.promotions.*'        => 'string|max:50',

    // Payment
    'payment.method'              => 'required|in:card,paypal,bank_transfer',
    'payment.token'               => 'required_if:payment.method,card|string',
    'payment.paypal_email'        => 'required_if:payment.method,paypal|email',
    'payment.bank_reference'      => 'required_if:payment.method,bank_transfer|string|max:100',

    // Card fields — prohibited for non-card methods
    'payment.save_card'           => 'prohibited_unless:payment.method,card|boolean',
]);

if ($v->fails()) {
    return Response::json(['errors' => $v->errors()], 422);
}

$order = $v->validated();
```

---

## Built-in rules reference

### Presence & flow

| Rule | Description |
|------|-------------|
| `required` | Field must be present and non-empty. |
| `nullable` | Skip remaining rules when the field is absent or empty. |
| `sometimes` | Skip **all** rules when the key is not in the input at all (useful for PATCH). |
| `present` | Key must exist in the input (value may be `null` or empty string). |
| `filled` | If the key exists, the value must not be empty. |

### Conditional required

| Rule | Description |
|------|-------------|
| `required_if:field,value` | Required when *field* equals *value*. |
| `required_unless:field,value` | Required unless *field* equals *value*. |
| `required_with:f1,f2,...` | Required if **any** of the listed fields is non-empty. |
| `required_without:f1,f2,...` | Required if **any** of the listed fields is absent or empty. |

```php
'body'   => 'required_unless:status,draft',
'notify' => 'required_with:email,phone',
'alt'    => 'required_without:primary',
```

### Prohibited

| Rule | Description |
|------|-------------|
| `prohibited` | Field must be absent or empty. |
| `prohibited_if:field,value` | Prohibited when *field* equals *value*. |
| `prohibited_unless:field,value` | Prohibited unless *field* equals *value*. |

```php
'admin_token' => 'prohibited_if:role,guest',
'debug_mode'  => 'prohibited_unless:env,local',
```

### Type

| Rule | Passes when |
|------|-------------|
| `string` | Value is a PHP string. |
| `integer` / `int` | Numeric integer (accepts `"42"`). |
| `float` / `numeric` | Numeric value. |
| `boolean` / `bool` | One of `true`, `false`, `1`, `0`, `"1"`, `"0"`, `"true"`, `"false"`. |
| `array` | PHP array. |

### Format

| Rule | Passes when |
|------|-------------|
| `email` | Valid email address. |
| `url` | Valid URL. |
| `ip` | Valid IPv4 or IPv6 address. |
| `ipv4` | Valid IPv4 address. |
| `ipv6` | Valid IPv6 address. |
| `alpha` | Only ASCII letters. |
| `alpha_num` | Only ASCII letters and digits. |
| `digits` | Only digit characters (`ctype_digit`). |
| `digits_between:min,max` | Only digits, length between *min* and *max*. |
| `date` | Parseable by `strtotime()`. |
| `date_format:fmt` | Matches the given PHP date format. |
| `json` | Valid JSON string. |
| `uuid` | Valid UUID (v1–v5). |
| `mac_address` | Valid MAC address (`AA:BB:CC:DD:EE:FF` or with `-`). |
| `regex:/pattern/` | Matches the regular expression. |
| `not_regex:/pattern/` | Does not match the regular expression. |
| `lowercase` | All characters are lowercase (`mb_strtolower`). |
| `uppercase` | All characters are uppercase (`mb_strtoupper`). |

### Value constraints

| Rule | Passes when |
|------|-------------|
| `min:n` | Numeric ≥ *n*; string length ≥ *n*; array count ≥ *n*. |
| `max:n` | Numeric ≤ *n*; string length ≤ *n*; array count ≤ *n*. |
| `between:min,max` | Numeric value between *min* and *max* (inclusive). |
| `size:n` | Exact numeric value, string length, or array count. |
| `min_length:n` | String length ≥ *n* characters. |
| `max_length:n` | String length ≤ *n* characters. |
| `multiple_of:n` | Numeric value is divisible by *n*. |
| `in:a,b,c` | Value is one of the listed options. |
| `not_in:a,b,c` | Value is not one of the listed options. |
| `accepted` | One of `yes`, `on`, `1`, `true`. |
| `declined` | One of `no`, `off`, `0`, `false`. |
| `confirmed` | `{field}_confirmation` field exists and is equal. |
| `same:other` | Value equals the value of *other* field. |
| `different:other` | Value differs from the value of *other* field. |
| `starts_with:pfx` | String starts with *pfx*. |
| `ends_with:sfx` | String ends with *sfx*. |

### Array rules

| Rule | Passes when |
|------|-------------|
| `list` | Array keys are `0, 1, 2, …` (sequential, no string keys). |
| `distinct` | All array values are unique. |
| `min_items:n` | Array has at least *n* elements. |
| `max_items:n` | Array has at most *n* elements. |

---

## Custom inline rules

### RuleInterface

Implement `Lift\Validation\RuleInterface` to encapsulate reusable logic:

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

// Usage
$v = new Validator($data, [
    'phone' => ['required', new PhoneRule()],
]);
```

The `:attribute` placeholder in `message()` is replaced with the field label.
Override it per-field via custom messages (see below).

### Closure rules

A closure receives `($field, $value, $allData, $fail)` — call `$fail()` with an
error string to make the rule fail:

```php
$v = new Validator($data, [
    'slug' => [
        'required',
        'string',
        function (string $field, mixed $value, array $data, \Closure $fail): void {
            if (str_contains($value, '--')) {
                $fail("The {$field} must not contain consecutive hyphens.");
            }
        },
    ],
]);
```

---

## Global rule registration

Register a custom rule once (e.g. in `bootstrap.php`) and use it by name
in any rule string from that point on:

```php
use Lift\Validation\Validator;

// Closure form
Validator::extend(
    'luhn',
    fn ($field, $value, $data) => checkLuhn($value),
    'The :attribute must be a valid card number.',
);

// RuleInterface form (message() is used automatically)
Validator::extend('isbn13', new Isbn13Rule());

// Usage anywhere
$v = new Validator($data, [
    'card' => 'required|luhn',
    'book' => 'required|isbn13',
]);
```

---

## Custom error messages

Pass an array of messages as the **third constructor argument**. Keys follow the
`"field.rule"` pattern (highest priority) or just `"rule"` (fallback for all
fields). Standard placeholders — `:attribute`, `:min`, `:max`, `:value`,
`:other`, `:when`, `:values` — are substituted automatically.

```php
$v = new Validator($data, $rules, [
    // Most specific: field + rule
    'email.required'  => 'We need your email address to continue.',
    'email.email'     => ':attribute does not look like a valid address.',

    // Fallback: rule only (applies to all fields using this rule)
    'required'        => 'This field cannot be left blank.',
    'min'             => ':attribute must be at least :min.',

    // Custom rule with placeholder
    'age.min'         => 'You must be at least :min years old.',
    'title.required_if' => 'A title is required when publishing.',
]);
```

### In FormRequest

Override `messages()` in your form request class:

```php
final class CreatePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title'  => 'required|string|max:200',
            'body'   => 'required|string',
            'status' => 'required|in:draft,published',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'  => 'Every post needs a title.',
            'status.in'       => 'Status must be draft or published.',
        ];
    }
}
```

---

## FormRequest

`Lift\Http\FormRequest` validates and hydrates request data before it reaches
the controller, keeping handler code clean.

### Defining a form request

```php
use Lift\Http\FormRequest;

final class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:100',
            'email'    => 'required|email',
            'role'     => 'required|in:admin,user,editor',
            'password' => 'required|min_length:12|confirmed',
        ];
    }

    public function messages(): array
    {
        return [
            'password.min_length' => 'Password must be at least 12 characters.',
        ];
    }

    // Throw ForbiddenException or similar to block the request
    public function authorize(Request $request): void
    {
        // e.g. check auth
    }
}
```

### Using in a controller

```php
$app->post('/users', function (Request $req) {
    $form = StoreUserRequest::fromRequest($req);

    $name  = $form->string('name');
    $email = $form->string('email');
    $all   = $form->validated();          // full validated array

    return Response::json(['id' => createUser($all)], 201);
});
```

### Direct validation on the request

For quick one-off validation without a dedicated class:

```php
$app->post('/login', function (Request $req) {
    $data = $req->validate([
        'email'    => 'required|email',
        'password' => 'required|string',
    ]);

    // $data is the validated array
});
```

`validate()` throws `ValidationException` on failure, which the framework
converts to a `422` JSON response automatically.

---

## Localization

Pass a `Translator` to get error messages in any language.
See the [Localization](localization) page for a full guide.

```php
use Lift\Translation\Translator;

// Set globally (all validators without their own translator)
Validator::setTranslator(new Translator('ru'));

// Or per-instance
$v = new Validator($data, $rules, [], new Translator('fr'));

// Or inside FormRequest
public function translator(): ?Translator
{
    return new Translator('de');
}
```

---

## ValidationException

`Lift\Validation\ValidationException` is thrown by `validated()` and
`Request::validate()` when validation fails. It carries the errors map:

```php
try {
    $data = $v->validated();
} catch (ValidationException $e) {
    $errors = $e->errors();  // ['field' => ['message', ...], ...]
    return Response::json(['errors' => $errors], 422);
}
```

You can also create one manually for programmatic use:

```php
throw ValidationException::withErrors([
    'email' => ['This email is already registered.'],
]);
```