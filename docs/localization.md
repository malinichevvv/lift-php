---
layout: page
title: Localization
nav_order: 31
---

# Localization

`Lift\Translation\Translator` is a small message-catalog loader with placeholder substitution and plural-form selection. It's used by the validator for error messages and the view helper (`$view->t()`), but you can use it for any string in your app.

> Mental model: each locale is a PHP file returning `['key' => 'string with :placeholders']`. The translator loads them on demand, falls back to a default locale when a key is missing, and picks the right plural form when a count is given.

## 30-second tour

```php
use Lift\Translation\Translator;

$t = new Translator('ru', fallback: 'en');
$t->addPath(__DIR__ . '/../lang');

echo $t->get('welcome', ['name' => 'Alice']);
// → "Добро пожаловать, Alice!"

echo $t->choice('items.count', 5, ['count' => 5]);
// → "5 предметов"
```

Message file `lang/ru.php`:

```php
<?php
return [
    'welcome'     => 'Добро пожаловать, :name!',
    'items.count' => '{0} нет предметов|{1} :count предмет|[2,4] :count предмета|[5,*] :count предметов',
];
```

## Message files

A locale file is a single PHP file that **returns** an associative array. Keys are flat strings — use dots for namespacing (`'auth.failed'`, `'cart.empty'`). One file per locale:

```
lang/
├── en.php
├── ru.php
└── de.php
```

```php
// lang/en.php
return [
    'welcome'        => 'Welcome, :name!',
    'items.count'    => 'no items|:count item|:count items',
    'auth.failed'    => 'These credentials do not match our records.',
];
```

## Loading paths

The translator first loads its **bundled translations** (under `resources/lang/` in the framework) so `validation.*` keys work out of the box. Then it appends any paths you register:

```php
$t = new Translator('en');
$t->addPath(__DIR__ . '/../lang');                   // your project
$t->addPath(__DIR__ . '/../vendor/some-pkg/lang');   // a package
```

Keys in **later-added** paths override earlier ones. So your project's `'validation.required'` beats the bundled default.

For values you don't want to put in a file (e.g. database-stored content), call `addMessages()`:

```php
$t->addMessages('en', ['feature.banner' => 'Now in beta!']);
```

## Reading a message

```php
$t->get('welcome');                              // 'Welcome, :name!'  (placeholders left as-is)
$t->get('welcome', ['name' => 'Alice']);         // 'Welcome, Alice!'
```

If the key isn't in the current locale, the translator tries the **fallback locale**. If that also has nothing, the **key string itself** is returned. (So a missing translation never throws — it just renders as e.g. `welcome`.)

## Placeholders

Every `:name` token is substituted from the second argument:

```php
$t->get('min_length', [
    'attribute' => 'Name',
    'min'       => 3,
]);
```

Numbers, strings, floats, and `__toString()`-able objects all work. Anything else gets stringified by PHP.

Conventional placeholders used by the bundled validation messages: `:attribute`, `:min`, `:max`, `:value`, `:other`, `:values`, `:when`, `:count`, `:format`.

## Plurals

The third argument to `get()` (or the dedicated `choice()` helper) is a count. When provided, the message is split on `|` and the right segment is chosen.

Two syntaxes — mix freely in one message:

### Interval notation

```
{0} no items|{1} :count item|[2,4] :count items|[5,*] :count items
```

| Pattern   | Matches            |
|-----------|--------------------|
| `{n}`     | Exactly that count |
| `[a,b]`   | a ≤ count ≤ b      |
| `[a,*]`   | a ≤ count          |
| `[*,b]`   | count ≤ b          |

First matching segment wins, left to right.

### Simple two-form

If the message has exactly two segments and no interval/`{n}` syntax, the first is used for count=1, the second otherwise:

```
one apple|many apples
```

### `$t->choice()` shortcut

`choice($key, $count, $replace)` is `get($key, $replace, $count)` with `'count' => $count` auto-merged into placeholders:

```php
$t->choice('items.count', 5);                          // → "5 items"
$t->choice('items.count', 5, ['attribute' => 'cart']); // count merged automatically
```

## Using it with the validator

Pass a translator to `Validator` for localised error messages:

```php
use Lift\Validation\Validator;

// Global default — every Validator that doesn't pass its own translator uses this
Validator::setTranslator(new Translator('ru'));

// Per-instance override
$v = new Validator($input, $rules, [], new Translator('fr'));

// Inside a FormRequest
public function translator(): ?Translator
{
    return new Translator('de');
}
```

The translator looks up `validation.<rule>` keys — `'validation.required'`, `'validation.email'`, `'validation.min'`, etc. Override the bundled English values by shipping your own file at `lang/ru.php`:

```php
return [
    'validation.required'  => 'Поле :attribute обязательно для заполнения.',
    'validation.email'     => ':attribute должен быть валидным email.',
    'validation.min'       => 'Минимальная длина :attribute — :min.',
];
```

## Using it in views

If you registered a translator with the view factory, templates get `$view->t()` and `$view->tc()` for free:

```php
$app->views()->setTranslator($app->make(Translator::class));
```

```php
<h1><?= $view->t('welcome', ['name' => $user->name]) ?></h1>
<p><?= $view->tc('cart.items_count', count($items)) ?></p>
```

## Switching locale per request

A small middleware that reads the `Accept-Language` header (or a query string / session value) and updates the global translator:

```php
final class LocaleMiddleware implements MiddlewareInterface
{
    private const SUPPORTED = ['en', 'ru', 'de', 'fr'];

    public function __construct(private readonly Translator $t) {}

    public function process($req, $next): ResponseInterface
    {
        $locale = $this->detect($req);
        $this->t->setLocale($locale);
        return $next->handle($req->withAttribute('locale', $locale));
    }

    private function detect(ServerRequestInterface $req): string
    {
        // 1. Explicit ?lang=…
        if ($lang = $req->getQueryParams()['lang'] ?? null) {
            if (in_array($lang, self::SUPPORTED, true)) return $lang;
        }
        // 2. Session
        if ($session = $req->getAttribute('session')) {
            if ($pref = $session->get('locale')) return $pref;
        }
        // 3. Accept-Language
        foreach (explode(',', $req->getHeaderLine('Accept-Language')) as $tag) {
            $code = strtolower(substr(trim(explode(';', $tag)[0]), 0, 2));
            if (in_array($code, self::SUPPORTED, true)) return $code;
        }
        return 'en';
    }
}
```

Bind the translator as a **singleton** so the same instance is shared across the request:

```php
$app->singleton(Translator::class, function () {
    $t = new Translator('en');
    $t->addPath(__DIR__ . '/../lang');
    return $t;
});
$app->use(LocaleMiddleware::class);
```

## Real-world example — `cart.empty` page

`lang/en.php`:

```php
return [
    'cart.empty.title'   => 'Your cart is empty',
    'cart.empty.cta'     => 'Browse products',
    'cart.items_count'   => '{0} No items|{1} :count item|[2,*] :count items',
];
```

`lang/ru.php`:

```php
return [
    'cart.empty.title'   => 'Корзина пуста',
    'cart.empty.cta'     => 'Перейти к товарам',
    'cart.items_count'   => '{0} Нет товаров|{1} :count товар|[2,4] :count товара|[5,*] :count товаров',
];
```

Template:

```php
<h1><?= $view->e($view->t('cart.empty.title')) ?></h1>
<p><?= $view->e($view->tc('cart.items_count', count($items))) ?></p>
<a href="/"><?= $view->e($view->t('cart.empty.cta')) ?></a>
```

`tc(...)` automatically passes `:count` so you don't have to.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Page renders the raw key (`'cart.empty.title'`) | Missing translation in both current and fallback locales | Add the key, or set a fallback locale that has it. |
| `:name` shows literally in output | Forgot to pass it in the `$replace` array | Always include every `:placeholder` referenced in the string. |
| Plural form chosen incorrectly for Russian | Interval syntax not used | Russian needs 3 forms: `[1] X товар|[2,4] X товара|[5,*] X товаров`. Two-form doesn't work. |
| Validator still in English after `setLocale('ru')` | The validator wasn't given a translator | Call `Validator::setTranslator(...)` or pass per-instance. |
| New translations not picked up after `addPath()` | Cached for the current locale | `addPath()` clears the cache for you; if you bypassed it, recreate the Translator. |
| Locale file syntax error breaks the whole app | A typo in `ru.php` → `require` throws | Run `php -l lang/ru.php` after editing. |

## Cheat sheet

```php
$t = new Translator('en', fallback: 'en');
$t->addPath(__DIR__ . '/../lang');
$t->addMessages('en', ['key' => 'value']);

$t->get('welcome', ['name' => 'Alice']);              // string
$t->choice('items.count', 5, ['attribute' => 'X']);    // plural
$t->setLocale('ru'); $t->setFallback('en');

// Wire up
Validator::setTranslator($t);
$app->views()->setTranslator($t);

// File format
return [
    'welcome'     => 'Welcome, :name!',
    'items.count' => '{0} no items|{1} :count item|[2,*] :count items',
];
```

[JSON-RPC →](json-rpc)
