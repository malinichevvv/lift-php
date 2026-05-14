---
layout: page
title: Form requests
nav_order: 16
---

# Form requests

A **form request** is a tiny class that owns the validation rules and the typed accessors for one incoming HTTP request. Once it's built, the controller receives a *validated, type-safe* object instead of a raw `Request`.

Use form requests when:

- You want validation rules **next to** the route they belong to, not in the controller.
- The same input shape is reused across multiple controllers.
- You need typed accessors (`->string('name')`, `->integer('age')`).
- You want a pre-validation `authorize()` hook (e.g. *"is the current user allowed to do this?"*).

> Mental model: a `FormRequest` is what arrives in your controller **after** validation has already succeeded. If validation fails, Lift's normal 422 handling kicks in before your controller is even invoked.

## Smallest possible example

```php
use Lift\Http\FormRequest;

final class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'     => 'required|string|min:2|max:255',
            'email'    => 'required|email',
            'age'      => 'integer|min:13',
        ];
    }
}
```

Use it from the controller:

```php
use Lift\Http\Request;
use Lift\Http\Response;

final class UserController
{
    public function __construct(private readonly UserRepository $users) {}

    public function store(Request $req): Response
    {
        $form = StoreUserRequest::fromRequest($req);

        return Response::json($this->users->create([
            'name'  => $form->string('name'),
            'email' => $form->string('email'),
            'age'   => $form->integer('age', 0),
        ]), 201);
    }
}
```

If the body doesn't match the rules, `fromRequest()` throws `Lift\Validation\ValidationException` → Lift returns HTTP 422 with the errors map. **Your controller's `store()` is never called.** You don't need a try/catch.

## What you can override

`FormRequest` is abstract; subclasses override these:

```php
abstract class FormRequest
{
    abstract public function rules(): array;        // required

    public function messages(): array { return []; }
    public function translator(): ?Translator { return null; }
    public function authorize(Request $request): void {}
    public function afterValidation(array $validated, Request $request): void {}
}
```

### `rules()`

Returns the same array shape that [`Validator`](validation) accepts:

```php
public function rules(): array
{
    return [
        'name'           => 'required|string|min:2',
        'tags'           => 'array',
        'tags.*'         => 'string|distinct',          // every item in the array
        'profile.bio'    => 'string|nullable|max:500',  // nested
        'profile.dob'    => 'date_format:Y-m-d',
    ];
}
```

### `messages()`

Override the default error message for a specific field/rule:

```php
public function messages(): array
{
    return [
        'email.required' => 'We need your email to send the welcome link.',
        'email.email'    => 'That doesn\'t look like a valid email address.',
        'required'       => 'This field is required.',  // global fallback
    ];
}
```

Keys are `'field.rule'` (specific) or just `'rule'` (rule-wide). Specific wins.

### `translator()`

Return a configured [Translator](localization) for localised messages:

```php
public function __construct(
    Request $request,
    array $validated,
    private readonly Translator $t,
) {
    parent::__construct($request, $validated);
}

public function translator(): ?Translator { return $this->t; }
```

(For a simpler app, bind a global `Translator` once and skip this.)

### `authorize(Request $req)`

Runs *before* validation. Throw an exception to abort:

```php
use Lift\Exception\ForbiddenException;

public function authorize(Request $request): void
{
    $user = $request->getAttribute('user');
    if (!$user?->canCreatePosts()) {
        throw new ForbiddenException("You are not allowed to create posts.");
    }
}
```

The exception becomes a proper HTTP 403 via the normal [error handling](errors) flow.

### `afterValidation(array $validated, Request $request)`

Runs *after* validation succeeds, before the immutable object is returned. Use for derived data or cross-field checks that the rule DSL can't express:

```php
public function afterValidation(array $validated, Request $request): void
{
    if ($validated['start_date'] >= $validated['end_date']) {
        throw new \Lift\Validation\ValidationException([
            'end_date' => ['End date must be after start date.'],
        ]);
    }
}
```

(Or implement a custom rule — see [Validation](validation#custom-rules) — when the logic is reusable.)

## Reading validated data

The built-in accessors:

```php
$form->validated();                // entire validated array
$form->input('key', $default);     // mixed
$form->string('key', '');          // string (cast)
$form->integer('key', 0);          // int (cast)
$form->request();                  // the original Request object
```

For boolean/float/array/etc. — read from `validated()`:

```php
$age   = (int) $form->validated()['age'];
$tags  = $form->validated()['tags'] ?? [];
```

> The `string()` / `integer()` shortcuts deliberately cover only the two most common cases; we keep the class small. Add your own subclass helper for `bool()` / `float()` if you reuse them.

## Injecting form requests directly

In Lift, controllers receive the **`Request`**, then call `Form::fromRequest($req)`. We deliberately don't auto-inject the form request type, because:

- Auto-injection means *some* parameter types do validation as a side effect; others don't. That magic is confusing.
- `fromRequest()` is one extra line — and a *visible* one. Reading the controller, you instantly see "this validates first".

If you want zero-boilerplate controllers, write a tiny base method:

```php
abstract class BaseController
{
    /** @template T of FormRequest @param class-string<T> $cls @return T */
    protected function form(string $cls, Request $req): FormRequest
    {
        return $cls::fromRequest($req);
    }
}

final class UserController extends BaseController
{
    public function store(Request $req): Response
    {
        $form = $this->form(StoreUserRequest::class, $req);
        // …
    }
}
```

## Reusing across endpoints

The same form is fine for `POST` (create) and `PUT` (full replace):

```php
$app->post('/users',          [UserController::class, 'store']);
$app->put ('/users/{id:\d+}', [UserController::class, 'update']);

class UserController
{
    public function store(Request $req): Response
    {
        return $this->save(StoreUserRequest::fromRequest($req));
    }
    public function update(Request $req): Response
    {
        return $this->save(StoreUserRequest::fromRequest($req), (int) $req->param('id'));
    }
}
```

For `PATCH` (partial update), define a separate form with mostly-`nullable` rules.

## Generating with the CLI

The `lift` binary scaffolds boilerplate:

```bash
vendor/bin/lift make:request StoreUserRequest
```

Creates `src/Http/Requests/StoreUserRequest.php` with the right skeleton — edit the rules and you're done. See [Console](console).

## Comparison with raw `$req->validate(...)`

Both routes through the same `Validator`. Use raw `validate()` when:

- The handler is a one-off (a small admin endpoint).
- You don't need typed accessors.
- The rules are too trivial to deserve a class (1-2 fields, used in one place).

Use a `FormRequest` when:

- The same input is reused across multiple controllers.
- You want `authorize()` and `messages()` in one place.
- The form has 5+ rules / nested arrays.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `fromRequest` throws even though the body looks right | Lift merges body + query + route params before validating; field collisions can shadow real values | Use a key that's not also a route/query param, or rename. |
| `string('foo')` returns `''` on a valid request | The field name in `rules()` differs from the one you're reading | Match the keys exactly. |
| `authorize()` runs but never blocks | You threw a generic `Exception` instead of an `HttpException` | Throw `ForbiddenException` (or any `HttpException` subclass). |
| Custom messages don't apply | Wrong key shape (`required.email` instead of `email.required`) | Format is `'field.rule'`. |
| Form's constructor needs deps but `fromRequest` is `static` | Override the constructor *and* keep `$prototype = new $class($request, []);` happy | Make extra deps optional or inject via setters; alternatively call your own factory. |

## Cheat sheet

```php
final class StoreUserRequest extends FormRequest
{
    public function rules(): array {
        return ['email' => 'required|email', 'name' => 'required|string'];
    }
    public function messages(): array { return ['email.email' => 'Bad email']; }
    public function authorize(Request $r): void { /* throw on denial */ }
    public function afterValidation(array $data, Request $r): void { /* … */ }
}

// In controller:
$form = StoreUserRequest::fromRequest($req);
$email = $form->string('email');
$all   = $form->validated();
```

[JSON resources →](json-resources)
