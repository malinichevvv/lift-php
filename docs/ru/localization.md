---
layout: page
title: Локализация
nav_order: 31
---

# Локализация

`Lift\Translation\Translator` — это небольшой загрузчик каталога сообщений с подстановкой плейсхолдеров и выбором формы множественного числа. Он используется валидатором для сообщений об ошибках и помощником шаблонов (`$view->t()`), но вы можете применять его для любой строки в вашем приложении.

> Ментальная модель: каждая локаль — это PHP-файл, возвращающий `['key' => 'string with :placeholders']`. Переводчик загружает их по требованию, откатывается к локали по умолчанию, когда ключ отсутствует, и выбирает правильную форму множественного числа, когда задан счётчик.

## Тур за 30 секунд

```php
use Lift\Translation\Translator;

$t = new Translator('ru', fallback: 'en');
$t->addPath(__DIR__ . '/../lang');

echo $t->get('welcome', ['name' => 'Alice']);
// → "Добро пожаловать, Alice!"

echo $t->choice('items.count', 5, ['count' => 5]);
// → "5 предметов"
```

Файл сообщений `lang/ru.php`:

```php
<?php
return [
    'welcome'     => 'Добро пожаловать, :name!',
    'items.count' => '{0} нет предметов|{1} :count предмет|[2,4] :count предмета|[5,*] :count предметов',
];
```

## Файлы сообщений

Файл локали — это один PHP-файл, который **возвращает** ассоциативный массив. Ключи — плоские строки; используйте точки для пространств имён (`'auth.failed'`, `'cart.empty'`). Один файл на локаль:

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

## Пути загрузки

Переводчик сначала загружает свои **встроенные переводы** (под `resources/lang/` во фреймворке), так что ключи `validation.*` работают «из коробки». Затем он добавляет любые пути, которые вы зарегистрировали:

```php
$t = new Translator('en');
$t->addPath(__DIR__ . '/../lang');                   // ваш проект
$t->addPath(__DIR__ . '/../vendor/some-pkg/lang');   // пакет
```

Ключи в **добавленных позже** путях переопределяют более ранние. Так что `'validation.required'` вашего проекта побеждает встроенное значение по умолчанию.

Для значений, которые вы не хотите помещать в файл (например, контент из базы данных), вызовите `addMessages()`:

```php
$t->addMessages('en', ['feature.banner' => 'Now in beta!']);
```

## Чтение сообщения

```php
$t->get('welcome');                              // 'Welcome, :name!'  (плейсхолдеры оставлены как есть)
$t->get('welcome', ['name' => 'Alice']);         // 'Welcome, Alice!'
```

Если ключа нет в текущей локали, переводчик пробует **запасную локаль**. Если и там ничего нет, возвращается **сама строка ключа**. (Так что отсутствующий перевод никогда не выбрасывает исключение — он просто рендерится как, например, `welcome`.)

## Плейсхолдеры

Каждый токен `:name` подставляется из второго аргумента:

```php
$t->get('min_length', [
    'attribute' => 'Name',
    'min'       => 3,
]);
```

Числа, строки, числа с плавающей точкой и объекты с `__toString()` все работают. Всё остальное приводится к строке PHP.

Общепринятые плейсхолдеры, используемые встроенными сообщениями валидации: `:attribute`, `:min`, `:max`, `:value`, `:other`, `:values`, `:when`, `:count`, `:format`.

## Множественное число

Третий аргумент `get()` (или специальный помощник `choice()`) — это счётчик. Когда он предоставлен, сообщение разбивается по `|` и выбирается правильный сегмент.

Два синтаксиса — смешивайте свободно в одном сообщении:

### Интервальная нотация

```
{0} no items|{1} :count item|[2,4] :count items|[5,*] :count items
```

| Паттерн   | Соответствует      |
|-----------|--------------------|
| `{n}`     | Ровно этому счётчику |
| `[a,b]`   | a ≤ счётчик ≤ b    |
| `[a,*]`   | a ≤ счётчик        |
| `[*,b]`   | счётчик ≤ b        |

Побеждает первый совпавший сегмент, слева направо.

### Простая двухформенная

Если в сообщении ровно два сегмента и нет синтаксиса интервалов/`{n}`, первый используется для count=1, второй — иначе:

```
one apple|many apples
```

### Сокращение `$t->choice()`

`choice($key, $count, $replace)` — это `get($key, $replace, $count)` с автоматически добавленным `'count' => $count` в плейсхолдеры:

```php
$t->choice('items.count', 5);                          // → "5 items"
$t->choice('items.count', 5, ['attribute' => 'cart']); // count добавляется автоматически
```

## Использование с валидатором

Передайте переводчик в `Validator` для локализованных сообщений об ошибках:

```php
use Lift\Validation\Validator;

// Глобальное значение по умолчанию — каждый Validator, не передающий собственный переводчик, использует этот
Validator::setTranslator(new Translator('ru'));

// Переопределение на экземпляр
$v = new Validator($input, $rules, [], new Translator('fr'));

// Внутри FormRequest
public function translator(): ?Translator
{
    return new Translator('de');
}
```

Переводчик ищет ключи `validation.<rule>` — `'validation.required'`, `'validation.email'`, `'validation.min'` и т. д. Переопределите встроенные английские значения, поставив свой файл `lang/ru.php`:

```php
return [
    'validation.required'  => 'Поле :attribute обязательно для заполнения.',
    'validation.email'     => ':attribute должен быть валидным email.',
    'validation.min'       => 'Минимальная длина :attribute — :min.',
];
```

## Использование в шаблонах

Если вы зарегистрировали переводчик в фабрике шаблонов, шаблоны получают `$view->t()` и `$view->tc()` бесплатно:

```php
$app->views()->setTranslator($app->make(Translator::class));
```

```php
<h1><?= $view->t('welcome', ['name' => $user->name]) ?></h1>
<p><?= $view->tc('cart.items_count', count($items)) ?></p>
```

## Переключение локали на запрос

Небольшой middleware, который читает заголовок `Accept-Language` (или значение строки запроса / сессии) и обновляет глобальный переводчик:

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
        // 1. Явный ?lang=…
        if ($lang = $req->getQueryParams()['lang'] ?? null) {
            if (in_array($lang, self::SUPPORTED, true)) return $lang;
        }
        // 2. Сессия
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

Привяжите переводчик как **синглтон**, чтобы один и тот же экземпляр разделялся в рамках запроса:

```php
$app->singleton(Translator::class, function () {
    $t = new Translator('en');
    $t->addPath(__DIR__ . '/../lang');
    return $t;
});
$app->use(LocaleMiddleware::class);
```

## Реальный пример — страница `cart.empty`

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

Шаблон:

```php
<h1><?= $view->e($view->t('cart.empty.title')) ?></h1>
<p><?= $view->e($view->tc('cart.items_count', count($items))) ?></p>
<a href="/"><?= $view->e($view->t('cart.empty.cta')) ?></a>
```

`tc(...)` автоматически передаёт `:count`, так что вам не нужно.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Страница рендерит сырой ключ (`'cart.empty.title'`) | Отсутствует перевод и в текущей, и в запасной локали | Добавьте ключ или задайте запасную локаль, в которой он есть. |
| `:name` показывается буквально в выводе | Забыли передать его в массиве `$replace` | Всегда включайте каждый `:placeholder`, упомянутый в строке. |
| Форма множественного числа выбрана неверно для русского | Не использован синтаксис интервалов | Русскому нужны 3 формы: `[1] X товар|[2,4] X товара|[5,*] X товаров`. Двухформенная не работает. |
| Валидатор всё ещё на английском после `setLocale('ru')` | Валидатору не дали переводчик | Вызовите `Validator::setTranslator(...)` или передайте на экземпляр. |
| Новые переводы не подхватываются после `addPath()` | Закэшированы для текущей локали | `addPath()` очищает кэш за вас; если вы это обошли, пересоздайте Translator. |
| Синтаксическая ошибка в файле локали ломает всё приложение | Опечатка в `ru.php` → `require` выбрасывает исключение | Запустите `php -l lang/ru.php` после редактирования. |

## Шпаргалка

```php
$t = new Translator('en', fallback: 'en');
$t->addPath(__DIR__ . '/../lang');
$t->addMessages('en', ['key' => 'value']);

$t->get('welcome', ['name' => 'Alice']);              // string
$t->choice('items.count', 5, ['attribute' => 'X']);    // множественное число
$t->setLocale('ru'); $t->setFallback('en');

// Подключение
Validator::setTranslator($t);
$app->views()->setTranslator($t);

// Формат файла
return [
    'welcome'     => 'Welcome, :name!',
    'items.count' => '{0} no items|{1} :count item|[2,*] :count items',
];
```

[JSON-RPC →](json-rpc)
