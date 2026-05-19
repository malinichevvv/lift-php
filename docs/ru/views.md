---
layout: page
title: Шаблоны (views)
nav_order: 10
---

# Шаблоны (views)

В Lift есть небольшой движок шаблонов без зависимостей: **обычные PHP-шаблоны** с макетами, секциями, частичными шаблонами, общими переменными, URL ресурсов и экранированием HTML. Никакого шага компиляции, никакого непрозрачного DSL — просто `<?php ... ?>` и помощник `$view`.

> Ментальная модель: «шаблон» — это PHP-файл под `views/`. Фабрика шаблонов находит его, выполняет с переданными переменными, захватывает его вывод и опционально оборачивает его в макет.

## Настройка

Сообщите приложению, где живут ваши шаблоны (один раз, при загрузке):

```php
$app->views(__DIR__ . '/../views', extension: 'php', assetBase: '/assets');
```

| Аргумент     | По умолчанию | Значение                                                                 |
|--------------|--------------|--------------------------------------------------------------------------|
| `path`       | —            | Абсолютный путь к корневому каталогу шаблонов.                           |
| `extension`  | `'php'`      | Расширение файла при разрешении имён (без ведущей точки).                |
| `assetBase`  | `'/assets'`  | Префикс URL, используемый `$view->asset('app.css')` → `/assets/app.css`. |

## Рендеринг шаблона из обработчика

Два эквивалентных способа:

```php
// 1. Получить отрендеренный HTML как строку (оборачиваете как хотите)
$html = $app->views()->render('home', ['user' => $user]);
return Response::html($html);

// 2. Получить Response напрямую
return $app->view('home', ['user' => $user]);
```

Точечная нотация позволяет вкладывать шаблоны в папки:

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

> **Заметка о безопасности:** только точечные имена — `/` и `\` отклоняются — так что вы не можете выйти за корень шаблонов через пользовательский ввод.

## Внутри шаблона

Каждый файл шаблона получает помощник `$view` плюс переданные вами переменные:

```php
<?php /** @var \Lift\View\ViewContext $view */ ?>
<?php /** @var array{name:string, email:string} $user */ ?>

<h1>Hello, <?= $view->e($user['name']) ?></h1>
<p>Reach me at <?= $view->e($user['email']) ?>.</p>
```

Критично: **всегда оборачивайте динамические строки в `$view->e(...)`** для экранирования HTML. Этот единственный вызов эквивалентен `htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.

## Макеты + секции

Это паттерн «обернуть вашу страницу в мастер-шаблон».

```
views/
├── layouts/
│   └── app.php        ← мастер HTML-каркас
├── home.php           ← дочерний шаблон
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

Что происходит:

1. `$view->layout('layouts.app')` говорит *«после того, как я закончу, оберни меня в это»*.
2. `$view->section('title')` … `$view->end()` захватывает всё между ними в именованный слот.
3. Макет вытягивает слоты обратно через `$view->yield('title', $default)`.
4. Всё вне именованных секций — это *содержимое по умолчанию* → `$view->content()`.

## Частичные шаблоны (`include`)

Для переиспользуемых фрагментов — навигационных панелей, карточек, модальных окон:

```php
<?= $view->include('partials.card', ['title' => 'Hello', 'body' => 'World']) ?>
```

`views/partials/card.php` получает `$title` и `$body` как локальные переменные, плюс все родительские переменные. Каждый `include()` возвращает строку — идеально для `<?= ... ?>`.

## Разделение переменных между всеми шаблонами

```php
$app->views()->share('appName', 'My App');
$app->views()->share(['user' => $currentUser, 'csrf' => $token]);
```

Внутри каждого шаблона `$appName`, `$user`, `$csrf` доступны без передачи их на каждом вызове `view(...)`.

## URL ресурсов

```php
<?= $view->asset('css/app.css') ?>          // → /assets/css/app.css
<?= $view->asset('img/logo.svg') ?>         // → /assets/img/logo.svg
<?= $view->asset('https://cdn/lib.js') ?>   // → без изменений (абсолютный URL)
```

Полезно для cache-busting с хешем деплоя, если вы вычисляете базу:

```php
$app->views(__DIR__ . '/../views', assetBase: '/assets/' . $deployHash);
```

## Переводы в шаблонах

Если вы вызвали `$app->views()->setTranslator($translator)` (или подключили `Translator` через контейнер — Lift делает это автоматически, когда он привязан), шаблоны получают `$view->t()` и `$view->tc()`:

```php
<h1><?= $view->t('welcome', ['name' => 'Alice']) ?></h1>
<p><?= $view->tc('items.count', $count) ?></p>
```

Формат сообщений см. в [Локализации](localization).

## Кэшированный рендеринг

Если вывод шаблона не зависит от данных конкретного запроса, вы можете кэшировать его через любой [кэш PSR-16](cache):

```php
$app->views()->setCacheDriver($app->make(\Lift\Cache\CacheInterface::class));

return $app->views()->responseCached(
    view: 'pages.about',
    data: [],
    cacheKey: 'about-page',
    ttl: 3600,
);
```

Второй вызов в пределах `ttl` пропускает весь рендеринг и возвращает закэшированный HTML.

> Не кэшируйте страницы, включающие контент конкретного пользователя (имя, число товаров в корзине и т. д.) — вы отдадите HTML одного пользователя другому. Либо стройте ключ кэша на пользователя, либо рендерьте пользовательские части через JavaScript / SSI.

## Сквозной пример

Раскладка проекта:

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

Это настоящий многостраничный сайт в ~40 строк.

## Помощник `$view` в одной таблице

| Метод                                   | Использование                                                               |
|------------------------------------------|------------------------------------------------------------------------------|
| `$view->e($value)`                      | Экранировать для HTML. **Используйте на каждой динамической строке.**         |
| `$view->layout('layouts.app')`          | Обернуть этот шаблон в именованный макет.                                     |
| `$view->section('name')` … `$view->end()` | Захватить именованный блок вывода.                                          |
| `$view->yield('name', $default = '')`   | Вывести именованную секцию (используется в макетах).                          |
| `$view->content()`                      | Вывести содержимое дочернего шаблона по умолчанию (используется в макетах).    |
| `$view->include('partial', $extra)`     | Отрендерить под-шаблон и вернуть его HTML.                                    |
| `$view->asset('path/to/file.css')`      | Построить URL с использованием настроенной базы ресурсов.                     |
| `$view->t($key, $replace = [])`         | Перевести через привязанный `Translator` (no-op, если его нет).               |
| `$view->tc($key, $count, $replace = [])`| Перевести с множественным числом.                                            |

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| `View [foo] was not found at [.../foo.php]` | Опечатка в имени шаблона или `$app->views(...)` не был вызван | Проверьте путь к файлу; передайте правильный корень. |
| Вывод HTML содержит `&lt;b&gt;` вместо `<b>` | Вы вызвали `$view->e(...)` на намеренном HTML | Уберите `e()` для *доверенного* HTML; держите его для пользовательского ввода. |
| XSS (`<script>` выполняется) | Забыли `$view->e(...)` на пользовательском вводе | Всегда экранируйте. |
| Макет показывает пустой `<title>` | Дочерний шаблон не определил секцию `title`, а у `yield('title')` не было значения по умолчанию | Передайте значение по умолчанию: `$view->yield('title', 'My App')`. |
| `Invalid view name` выброшено на точечной нотации | Имя шаблона содержало `/` или `\` | Используйте только точки, никогда разделители путей. |
| Закэшированные шаблоны отдают устаревший контент | Слишком долгий `ttl`, нет ручного сброса после деплоя | Используйте ключ кэша с префиксом-хешем деплоя или меньший TTL. |

[Сессии →](sessions)
