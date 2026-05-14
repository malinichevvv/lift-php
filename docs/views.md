---
layout: page
title: Views (templates)
nav_order: 10
---

# Views (templates)

Lift has a small, dependency-free view engine: **plain PHP templates** with layouts, sections, partials, shared variables, asset URLs, and HTML escaping. No compile step, no opaque DSL — just `<?php ... ?>` and a `$view` helper.

> Mental model: a "view" is a PHP file under `views/`. The view factory locates it, runs it with the variables you pass, captures its output, and optionally wraps it in a layout.

## Setup

Tell the app where your templates live (once, at boot):

```php
$app->views(__DIR__ . '/../views', extension: 'php', assetBase: '/assets');
```

| Argument     | Default     | Meaning                                                                  |
|--------------|-------------|--------------------------------------------------------------------------|
| `path`       | —           | Absolute path to the views root directory.                               |
| `extension`  | `'php'`     | File extension when resolving names (no leading dot).                    |
| `assetBase`  | `'/assets'` | URL prefix used by `$view->asset('app.css')` → `/assets/app.css`.        |

## Rendering a view from a handler

Two equivalent ways:

```php
// 1. Get the rendered HTML as a string (you wrap it however you like)
$html = $app->views()->render('home', ['user' => $user]);
return Response::html($html);

// 2. Get a Response back directly
return $app->view('home', ['user' => $user]);
```

Dot-notation lets you nest views into folders:

```
views/
├── home.php           ←  'home'
├── partials/
│   └── card.php       ←  'partials.card'
└── users/
    └── show.php       ←  'users.show'
```

```php
return $app->view('users.show', ['user' => $user]);
```

> **Security note:** dot-only names — `/` and `\` are rejected — so you can't escape the views root through user input.

## Inside a template

Every view file receives a `$view` helper plus the variables you passed:

```php
<?php /** @var \Lift\View\ViewContext $view */ ?>
<?php /** @var array{name:string, email:string} $user */ ?>

<h1>Hello, <?= $view->e($user['name']) ?></h1>
<p>Reach me at <?= $view->e($user['email']) ?>.</p>
```

Crucial: **always wrap dynamic strings in `$view->e(...)`** to escape HTML. That single call equals `htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.

## Layouts + sections

This is the "wrap your page in a master template" pattern.

```
views/
├── layouts/
│   └── app.php        ← master HTML scaffold
├── home.php           ← child view
└── about.php
```

`views/layouts/app.php`:

```php
<?php /** @var \Lift\View\ViewContext $view */ ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= $view->yield('title', 'My App') ?></title>
    <link rel="stylesheet" href="<?= $view->asset('app.css') ?>">
</head>
<body>
    <header>
        <?= $view->include('partials.nav') ?>
    </header>
    <main>
        <?= $view->content() ?>
    </main>
</body>
</html>
```

`views/home.php`:

```php
<?php /** @var \Lift\View\ViewContext $view */ $view->layout('layouts.app'); ?>

<?php $view->section('title'); ?>Welcome<?php $view->end(); ?>

<h1>Hello, world!</h1>
<p>This text becomes <code>$view->content()</code> in the layout.</p>
```

What's happening:

1. `$view->layout('layouts.app')` says *"after I'm done, wrap me in this"*.
2. `$view->section('title')` … `$view->end()` captures everything between into a named slot.
3. The layout pulls slots back out with `$view->yield('title', $default)`.
4. Anything outside named sections is the *default* content → `$view->content()`.

## Partials (`include`)

For reusable fragments — nav bars, cards, modals:

```php
<?= $view->include('partials.card', ['title' => 'Hello', 'body' => 'World']) ?>
```

`views/partials/card.php` receives `$title` and `$body` as locals, plus all parent vars. Each `include()` returns a string — perfect for `<?= ... ?>`.

## Sharing variables across every view

```php
$app->views()->share('appName', 'My App');
$app->views()->share(['user' => $currentUser, 'csrf' => $token]);
```

Inside every template `$appName`, `$user`, `$csrf` are available without you passing them on each `view(...)` call.

## Asset URLs

```php
<?= $view->asset('css/app.css') ?>          // → /assets/css/app.css
<?= $view->asset('img/logo.svg') ?>         // → /assets/img/logo.svg
<?= $view->asset('https://cdn/lib.js') ?>   // → unchanged (absolute URL)
```

Useful for cache-busting with a deploy hash if you compute the base:

```php
$app->views(__DIR__ . '/../views', assetBase: '/assets/' . $deployHash);
```

## Translations in views

If you call `$app->views()->setTranslator($translator)` (or wire the `Translator` through the container — Lift does it automatically when one is bound), templates gain `$view->t()` and `$view->tc()`:

```php
<h1><?= $view->t('welcome', ['name' => 'Alice']) ?></h1>
<p><?= $view->tc('items.count', $count) ?></p>
```

See [Localization](localization) for the message format.

## Cached rendering

If a view's output doesn't depend on per-request data, you can cache it through any [PSR-16 cache](cache):

```php
$app->views()->setCacheDriver($app->make(\Lift\Cache\CacheInterface::class));

return $app->views()->responseCached(
    view: 'pages.about',
    data: [],
    cacheKey: 'about-page',
    ttl: 3600,
);
```

The second call within `ttl` skips the entire render and returns the cached HTML.

> Don't cache pages that include per-user content (name, cart count, etc.) — you'll serve one user's HTML to another. Either build the cache key per user, or render the user-specific parts via JavaScript / SSI.

## End-to-end example

Project layout:

```
my-app/
├── public/index.php
└── views/
    ├── layouts/app.php
    ├── partials/nav.php
    ├── home.php
    └── users/show.php
```

`public/index.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Lift\App;

$app = new App();
$app->views(__DIR__ . '/../views');
$app->views()->share('appName', 'My Blog');

$app->get('/', fn() => $app->view('home', [
    'title' => 'Welcome',
    'posts' => [
        ['slug' => 'hello',    'title' => 'Hello, World'],
        ['slug' => 'bye',      'title' => 'Goodbye, World'],
    ],
]));

$app->get('/users/{id:\d+}', function (\Lift\Http\Request $req) use ($app) {
    $user = ['id' => $req->param('id'), 'name' => 'Alice'];
    return $app->view('users.show', ['user' => $user]);
});

$app->run();
```

`views/layouts/app.php`:

```php
<!doctype html>
<html><head>
<title><?= $view->yield('title', $appName) ?> — <?= $view->e($appName) ?></title>
</head><body>
<?= $view->include('partials.nav') ?>
<main><?= $view->content() ?></main>
</body></html>
```

`views/partials/nav.php`:

```php
<nav>
    <a href="/">Home</a>
    <a href="/users/1">First user</a>
</nav>
```

`views/home.php`:

```php
<?php $view->layout('layouts.app'); ?>
<?php $view->section('title'); ?><?= $view->e($title) ?><?php $view->end(); ?>

<h1>Recent posts</h1>
<ul>
    <?php foreach ($posts as $post): ?>
        <li><a href="/posts/<?= $view->e($post['slug']) ?>"><?= $view->e($post['title']) ?></a></li>
    <?php endforeach; ?>
</ul>
```

`views/users/show.php`:

```php
<?php $view->layout('layouts.app'); ?>
<?php $view->section('title'); ?>User <?= $view->e($user['name']) ?><?php $view->end(); ?>

<h1>Hello, <?= $view->e($user['name']) ?> (#<?= $view->e($user['id']) ?>)</h1>
```

That's a real, multi-page site in ~40 lines.

## The `$view` helper, in one table

| Method                                  | Use                                                                         |
|-----------------------------------------|-----------------------------------------------------------------------------|
| `$view->e($value)`                      | Escape for HTML. **Use on every dynamic string.**                           |
| `$view->layout('layouts.app')`          | Wrap this view in the named layout.                                         |
| `$view->section('name')` … `$view->end()` | Capture a named block of output.                                          |
| `$view->yield('name', $default = '')`   | Output a named section (used in layouts).                                   |
| `$view->content()`                      | Output the child's default content (used in layouts).                       |
| `$view->include('partial', $extra)`     | Render a sub-view and return its HTML.                                      |
| `$view->asset('path/to/file.css')`      | Build a URL using the configured asset base.                                |
| `$view->t($key, $replace = [])`         | Translate using the bound `Translator` (no-op if none).                     |
| `$view->tc($key, $count, $replace = [])`| Translate with pluralisation.                                               |

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `View [foo] was not found at [.../foo.php]` | View name typo, or `$app->views(...)` wasn't called | Verify the file path; pass the right root. |
| HTML output contains `&lt;b&gt;` instead of `<b>` | You called `$view->e(...)` on intentional HTML | Drop `e()` for *trusted* HTML; keep it for user input. |
| XSS (`<script>` runs) | Forgot `$view->e(...)` on user input | Always escape. |
| Layout shows empty `<title>` | The child view didn't define a `title` section, and `yield('title')` had no default | Pass a default: `$view->yield('title', 'My App')`. |
| `Invalid view name` thrown on dot-notation | View name contained `/` or `\` | Use dots only, never path separators. |
| Cached views serve stale content | `ttl` too long, no manual flush after deploy | Use a deploy-hash-prefixed cache key, or shorter TTL. |

[Sessions →](sessions)
