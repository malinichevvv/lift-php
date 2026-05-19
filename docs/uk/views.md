---
layout: page
title: Шаблони (views)
nav_order: 10
---

# Шаблони (views)

У Lift є невеликий рушій шаблонів без залежностей: **звичайні PHP-шаблони** з макетами, секціями, частковими шаблонами, спільними змінними, URL ресурсів і екрануванням HTML. Жодного кроку компіляції, жодного непрозорого DSL — просто `<?php ... ?>` і помічник `$view`.

> Ментальна модель: «шаблон» — це PHP-файл під `views/`. Фабрика шаблонів знаходить його, виконує з переданими змінними, захоплює його вивід і опційно загортає його в макет.

## Налаштування

Повідомте застосунку, де живуть ваші шаблони (один раз, під час завантаження):

```php
$app->views(__DIR__ . '/../views', extension: 'php', assetBase: '/assets');
```

| Аргумент     | За замовчуванням | Значення                                                                 |
|--------------|------------------|--------------------------------------------------------------------------|
| `path`       | —                | Абсолютний шлях до кореневого каталогу шаблонів.                         |
| `extension`  | `'php'`          | Розширення файлу під час розв’язання імен (без початкової крапки).       |
| `assetBase`  | `'/assets'`      | Префікс URL, що використовується `$view->asset('app.css')` → `/assets/app.css`. |

## Рендеринг шаблону з обробника

Два еквівалентні способи:

```php
// 1. Отримати відрендерений HTML як рядок (загортаєте як хочете)
$html = $app->views()->render('home', ['user' => $user]);
return Response::html($html);

// 2. Отримати Response напряму
return $app->view('home', ['user' => $user]);
```

Крапкова нотація дозволяє вкладати шаблони в папки:

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

> **Заувага про безпеку:** лише крапкові імена — `/` і `\` відхиляються — тож ви не можете вийти за корінь шаблонів через користувацький ввід.

## Усередині шаблону

Кожен файл шаблону отримує помічник `$view` плюс передані вами змінні:

```php
<?php /** @var \Lift\View\ViewContext $view */ ?>
<?php /** @var array{name:string, email:string} $user */ ?>

<h1>Hello, <?= $view->e($user['name']) ?></h1>
<p>Reach me at <?= $view->e($user['email']) ?>.</p>
```

Критично: **завжди загортайте динамічні рядки у `$view->e(...)`** для екранування HTML. Цей єдиний виклик еквівалентний `htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.

## Макети + секції

Це патерн «загорнути вашу сторінку в майстер-шаблон».

```
views/
├── layouts/
│   └── app.php        ← майстер HTML-каркас
├── home.php           ← дочірній шаблон
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

Що відбувається:

1. `$view->layout('layouts.app')` каже *«після того, як я закінчу, загорни мене в це»*.
2. `$view->section('title')` … `$view->end()` захоплює все між ними в іменований слот.
3. Макет витягує слоти назад через `$view->yield('title', $default)`.
4. Усе поза іменованими секціями — це *вміст за замовчуванням* → `$view->content()`.

## Часткові шаблони (`include`)

Для повторно використовуваних фрагментів — навігаційних панелей, карток, модальних вікон:

```php
<?= $view->include('partials.card', ['title' => 'Hello', 'body' => 'World']) ?>
```

`views/partials/card.php` отримує `$title` і `$body` як локальні змінні, плюс усі батьківські змінні. Кожен `include()` повертає рядок — ідеально для `<?= ... ?>`.

## Розділення змінних між усіма шаблонами

```php
$app->views()->share('appName', 'My App');
$app->views()->share(['user' => $currentUser, 'csrf' => $token]);
```

Усередині кожного шаблону `$appName`, `$user`, `$csrf` доступні без передавання їх на кожному виклику `view(...)`.

## URL ресурсів

```php
<?= $view->asset('css/app.css') ?>          // → /assets/css/app.css
<?= $view->asset('img/logo.svg') ?>         // → /assets/img/logo.svg
<?= $view->asset('https://cdn/lib.js') ?>   // → без змін (абсолютний URL)
```

Корисно для cache-busting із хешем деплою, якщо ви обчислюєте базу:

```php
$app->views(__DIR__ . '/../views', assetBase: '/assets/' . $deployHash);
```

## Переклади у шаблонах

Якщо ви викликали `$app->views()->setTranslator($translator)` (або під’єднали `Translator` через контейнер — Lift робить це автоматично, коли він прив’язаний), шаблони отримують `$view->t()` і `$view->tc()`:

```php
<h1><?= $view->t('welcome', ['name' => 'Alice']) ?></h1>
<p><?= $view->tc('items.count', $count) ?></p>
```

Формат повідомлень див. у [Локалізації](localization).

## Кешований рендеринг

Якщо вивід шаблону не залежить від даних конкретного запиту, ви можете кешувати його через будь-який [кеш PSR-16](cache):

```php
$app->views()->setCacheDriver($app->make(\Lift\Cache\CacheInterface::class));

return $app->views()->responseCached(
    view: 'pages.about',
    data: [],
    cacheKey: 'about-page',
    ttl: 3600,
);
```

Другий виклик у межах `ttl` пропускає весь рендеринг і повертає закешований HTML.

> Не кешуйте сторінки, що включають вміст конкретного користувача (ім’я, кількість товарів у кошику тощо) — ви віддасте HTML одного користувача іншому. Або будуйте ключ кешу на користувача, або рендеріть користувацькі частини через JavaScript / SSI.

## Наскрізний приклад

Розкладка проєкту:

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

Це справжній багатосторінковий сайт у ~40 рядків.

## Помічник `$view` в одній таблиці

| Метод                                    | Використання                                                                |
|------------------------------------------|------------------------------------------------------------------------------|
| `$view->e($value)`                      | Екранувати для HTML. **Використовуйте на кожному динамічному рядку.**         |
| `$view->layout('layouts.app')`          | Загорнути цей шаблон в іменований макет.                                      |
| `$view->section('name')` … `$view->end()` | Захопити іменований блок виводу.                                            |
| `$view->yield('name', $default = '')`   | Вивести іменовану секцію (використовується в макетах).                        |
| `$view->content()`                      | Вивести вміст дочірнього шаблону за замовчуванням (використовується в макетах). |
| `$view->include('partial', $extra)`     | Відрендерити під-шаблон і повернути його HTML.                                |
| `$view->asset('path/to/file.css')`      | Побудувати URL із використанням налаштованої бази ресурсів.                   |
| `$view->t($key, $replace = [])`         | Перекласти через прив’язаний `Translator` (no-op, якщо його немає).           |
| `$view->tc($key, $count, $replace = [])`| Перекласти з множиною.                                                       |

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| `View [foo] was not found at [.../foo.php]` | Друкарська помилка в імені шаблону або `$app->views(...)` не було викликано | Перевірте шлях до файлу; передайте правильний корінь. |
| Вивід HTML містить `&lt;b&gt;` замість `<b>` | Ви викликали `$view->e(...)` на навмисному HTML | Приберіть `e()` для *довіреного* HTML; тримайте його для користувацького вводу. |
| XSS (`<script>` виконується) | Забули `$view->e(...)` на користувацькому вводі | Завжди екрануйте. |
| Макет показує порожній `<title>` | Дочірній шаблон не визначив секцію `title`, а в `yield('title')` не було значення за замовчуванням | Передайте значення за замовчуванням: `$view->yield('title', 'My App')`. |
| `Invalid view name` викинуто на крапковій нотації | Ім’я шаблону містило `/` або `\` | Використовуйте лише крапки, ніколи роздільники шляхів. |
| Закешовані шаблони віддають застарілий вміст | Надто довгий `ttl`, немає ручного скидання після деплою | Використовуйте ключ кешу з префіксом-хешем деплою або менший TTL. |

[Сесії →](sessions)
