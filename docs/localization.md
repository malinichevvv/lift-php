---
layout: page
title: Localization
nav_order: 9
---

# Localization

`Lift\Translation\Translator` loads locale message files and resolves plural
forms. It is used by the validator to translate error messages and by the view
layer to translate template strings.

## Setup

```php
use Lift\Translation\Translator;

$t = new Translator('ru');           // locale
$t = new Translator('ru', 'en');     // locale + fallback
```

The translator searches for locale files in:

1. **`resources/lang/`** inside the Lift package itself (bundled translations).
2. Any directories added via `addPath()` — loaded **after** the bundled ones,
   so keys in your files override the defaults.

Files must return a flat PHP array:

```
your-app/
└── lang/
    ├── en.php
    ├── ru.php
    └── de.php
```

Register your directory once:

```php
$t = new Translator('de');
$t->addPath(base_path('lang'));
```

---

## Basic translation

```php
$t->get('required');
// → "The :attribute field is required."

$t->get('required', ['attribute' => 'Email']);
// → "The Email field is required."
```

Placeholders use the `:name` prefix and are replaced by the matching `$replace`
key:

```php
$t->get('min', ['attribute' => 'Age', 'min' => '18']);
// → "The Age must be at least 18."
```

---

## Plural forms

Use `choice()` to select the correct form for a given count.  
Two plural styles are supported:

### Simple two-form

Split by `|`. First form for `count === 1`, second for everything else:

```php
'apples' => 'one apple|many apples'

$t->choice('apples', 1);  // → "one apple"
$t->choice('apples', 5);  // → "many apples"
```

### Interval notation

Use `{n}` for an exact count or `[min,max]` for a range. Use `*` for
open-ended ranges. Segments are tried left-to-right; the first match wins.

```php
'messages' => '{0} No messages|{1} One message|[2,*] :count messages'

$t->choice('messages', 0);  // → "No messages"
$t->choice('messages', 1);  // → "One message"
$t->choice('messages', 9);  // → "9 messages"  (:count replaced automatically)
```

Both styles may be mixed in the same file. Interval notation takes priority over
simple two-form split.

### Russian plurals (три формы)

Russian (and many Slavic languages) use three plural forms. Use the simplified
three-way split that covers the vast majority of practical values:

```php
// lang/ru.php
'comments' => '{1} :count комментарий|[2,4] :count комментария|[5,*] :count комментариев'

$t->choice('comments', 1);   // → "1 комментарий"
$t->choice('comments', 3);   // → "3 комментария"
$t->choice('comments', 11);  // → "11 комментариев"
```

For exhaustive coverage of 11–14 (genitive plural), add a more specific interval
before the catch-all:

```php
'comments' => '{1} :count комментарий|[2,4] :count комментария|[11,14] :count комментариев|[5,*] :count комментариев'
```

---

## Runtime message overrides

Inject messages directly at runtime without a file:

```php
$t = new Translator('en');

// Add or override individual keys for a locale
$t->addMessages('en', [
    'required'    => 'This field is mandatory.',
    'custom.rule' => 'Value is not acceptable.',
]);

// Override a key in a foreign locale without writing a file
$t->addMessages('fr', ['required' => 'Ce champ est obligatoire.']);
```

`addMessages()` merges over file-loaded messages (keys added later win).

---

## Locale switching

```php
$t = new Translator('en');

$t->setLocale('ru');
$t->getLocale();   // 'ru'

$t->setFallback('en');   // used when a key is missing in the current locale
```

---

## Writing a locale file

Create `lang/<locale>.php` in your application and return a flat array.
Keys match validator rule names (for use with the Validator) or any dot-notation
string (for use in views).

```php
<?php // lang/de.php
return [
    // Validator messages
    'required'   => 'Das Feld :attribute ist erforderlich.',
    'email'      => 'Das Feld :attribute muss eine gültige E-Mail-Adresse sein.',
    'min'        => 'Das Feld :attribute muss mindestens :min sein.',
    'min_length' => 'Das Feld :attribute muss mindestens :min Zeichen lang sein.|Das Feld :attribute muss mindestens :min Zeichen lang sein.',

    // Custom application messages
    'nav.home'      => 'Startseite',
    'nav.about'     => 'Über uns',
    'greeting'      => 'Hallo, :name!',
    'items_in_cart' => '{0} Warenkorb ist leer|{1} :count Artikel|[2,*] :count Artikel',
];
```

Register the directory in bootstrap:

```php
$t = new Translator('de', 'en');
$t->addPath(__DIR__ . '/lang');
```

---

## Bundled locales

| Locale | File | Coverage |
|--------|------|----------|
| `en` | `resources/lang/en.php` | All built-in validator rules |
| `ru` | `resources/lang/ru.php` | All built-in validator rules (three plural forms) |

---

## Using with the Validator

### Global default

Set once in bootstrap — all `Validator` instances use it unless they have their
own:

```php
use Lift\Translation\Translator;
use Lift\Validation\Validator;

Validator::setTranslator(new Translator('ru'));
```

### Per-instance

```php
$v = new Validator($data, $rules, messages: [], translator: new Translator('fr'));
```

### In FormRequest

Override `translator()` in the form request class:

```php
final class CreatePostRequest extends FormRequest
{
    public function rules(): array { /* ... */ }

    public function translator(): ?Translator
    {
        // Could resolve the locale from the session, Accept-Language header, etc.
        return new Translator('ru');
    }
}
```

---

## Using in Views

After calling `ViewFactory::setTranslator()` every template rendered by that
factory gets `$view->t()` and `$view->tc()`:

```php
$factory = new ViewFactory(__DIR__ . '/views');
$factory->setTranslator(new Translator('de'));
```

### `$view->t(key, replace)` — simple translation

```php
<title><?= $view->t('page.home.title') ?></title>
<p><?= $view->t('greeting', ['name' => $user->name]) ?></p>
```

### `$view->tc(key, count, replace)` — plural translation

```php
<span><?= $view->tc('items_in_cart', $cartCount, ['count' => $cartCount]) ?></span>
```

Both helpers fall back to the key string itself when no translator is configured,
so templates never break even without a locale set.

### Shared translator via `share()`

You can also share the translator as a view variable and call it directly:

```php
$factory->share('t', new Translator('ru'));

// In template:
<?= $t->get('nav.home') ?>
<?= $t->choice('comments', $n, ['count' => $n]) ?>
```

### Translation in partials and layouts

The translator is propagated transparently to every `include()` call and to the
layout — you don't need to pass it manually:

```php
// layout.php
<nav><?= $view->include('partials.nav') ?></nav>

// partials/nav.php — $view->t() works here automatically
<a href="/"><?= $view->t('nav.home') ?></a>
```

---

## Quick-reference

```php
// Create
$t = new Translator('ru', fallback: 'en', paths: [__DIR__ . '/lang']);

// Translate
$t->get('key');
$t->get('key', ['attribute' => 'Name', 'min' => '3']);

// Plural
$t->choice('key', $count);
$t->choice('key', $count, ['count' => $count, 'attribute' => 'Tags']);

// Configure
$t->setLocale('de');
$t->setFallback('en');
$t->addPath('/app/lang');
$t->addMessages('en', ['required' => 'This field is required.']);
```