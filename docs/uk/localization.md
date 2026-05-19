---
layout: page
title: Локалізація
nav_order: 31
---

# Локалізація

`Lift\Translation\Translator` — це невеликий завантажувач каталогу повідомлень із підстановкою плейсхолдерів і вибором форми множини. Він використовується валідатором для повідомлень про помилки та помічником шаблонів (`$view->t()`), але ви можете застосовувати його для будь-якого рядка у вашому застосунку.

> Ментальна модель: кожна локаль — це PHP-файл, що повертає `['key' => 'string with :placeholders']`. Перекладач завантажує їх на вимогу, відкочується до локалі за замовчуванням, коли ключ відсутній, і обирає правильну форму множини, коли задано лічильник.

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

Файл повідомлень `lang/ru.php`:

```php
<?php
return [
    'welcome'     => 'Добро пожаловать, :name!',
    'items.count' => '{0} нет предметов|{1} :count предмет|[2,4] :count предмета|[5,*] :count предметов',
];
```

## Файли повідомлень

Файл локалі — це один PHP-файл, який **повертає** асоціативний масив. Ключі — плоскі рядки; використовуйте крапки для просторів імен (`'auth.failed'`, `'cart.empty'`). Один файл на локаль:

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

## Шляхи завантаження

Перекладач спершу завантажує свої **вбудовані переклади** (під `resources/lang/` у фреймворку), тож ключі `validation.*` працюють «з коробки». Потім він додає будь-які шляхи, які ви зареєстрували:

```php
$t = new Translator('en');
$t->addPath(__DIR__ . '/../lang');                   // ваш проєкт
$t->addPath(__DIR__ . '/../vendor/some-pkg/lang');   // пакет
```

Ключі у **доданих пізніше** шляхах перевизначають ранніші. Тож `'validation.required'` вашого проєкту перемагає вбудоване значення за замовчуванням.

Для значень, які ви не хочете розміщувати у файлі (наприклад, контент із бази даних), викличте `addMessages()`:

```php
$t->addMessages('en', ['feature.banner' => 'Now in beta!']);
```

## Читання повідомлення

```php
$t->get('welcome');                              // 'Welcome, :name!'  (плейсхолдери залишені як є)
$t->get('welcome', ['name' => 'Alice']);         // 'Welcome, Alice!'
```

Якщо ключа немає в поточній локалі, перекладач пробує **запасну локаль**. Якщо й там нічого немає, повертається **сам рядок ключа**. (Тож відсутній переклад ніколи не викидає виняток — він просто рендериться як, наприклад, `welcome`.)

## Плейсхолдери

Кожен токен `:name` підставляється з другого аргументу:

```php
$t->get('min_length', [
    'attribute' => 'Name',
    'min'       => 3,
]);
```

Числа, рядки, числа з плаваючою комою та об’єкти з `__toString()` усі працюють. Усе інше приводиться до рядка PHP.

Загальноприйняті плейсхолдери, що використовуються вбудованими повідомленнями валідації: `:attribute`, `:min`, `:max`, `:value`, `:other`, `:values`, `:when`, `:count`, `:format`.

## Множина

Третій аргумент `get()` (або спеціальний помічник `choice()`) — це лічильник. Коли він наданий, повідомлення розбивається за `|` і обирається правильний сегмент.

Два синтаксиси — змішуйте вільно в одному повідомленні:

### Інтервальна нотація

```
{0} no items|{1} :count item|[2,4] :count items|[5,*] :count items
```

| Патерн    | Відповідає         |
|-----------|--------------------|
| `{n}`     | Рівно цьому лічильнику |
| `[a,b]`   | a ≤ лічильник ≤ b  |
| `[a,*]`   | a ≤ лічильник      |
| `[*,b]`   | лічильник ≤ b      |

Перемагає перший збіглий сегмент, зліва направо.

### Проста двоформена

Якщо в повідомленні рівно два сегменти і немає синтаксису інтервалів/`{n}`, перший використовується для count=1, другий — інакше:

```
one apple|many apples
```

### Скорочення `$t->choice()`

`choice($key, $count, $replace)` — це `get($key, $replace, $count)` з автоматично доданим `'count' => $count` у плейсхолдери:

```php
$t->choice('items.count', 5);                          // → "5 items"
$t->choice('items.count', 5, ['attribute' => 'cart']); // count додається автоматично
```

## Використання з валідатором

Передайте перекладач у `Validator` для локалізованих повідомлень про помилки:

```php
use Lift\Validation\Validator;

// Глобальне значення за замовчуванням — кожен Validator, що не передає власний перекладач, використовує цей
Validator::setTranslator(new Translator('ru'));

// Перевизначення на екземпляр
$v = new Validator($input, $rules, [], new Translator('fr'));

// Усередині FormRequest
public function translator(): ?Translator
{
    return new Translator('de');
}
```

Перекладач шукає ключі `validation.<rule>` — `'validation.required'`, `'validation.email'`, `'validation.min'` тощо. Перевизначте вбудовані англійські значення, поставивши свій файл `lang/ru.php`:

```php
return [
    'validation.required'  => 'Поле :attribute обязательно для заполнения.',
    'validation.email'     => ':attribute должен быть валидным email.',
    'validation.min'       => 'Минимальная длина :attribute — :min.',
];
```

## Використання в шаблонах

Якщо ви зареєстрували перекладач у фабриці шаблонів, шаблони отримують `$view->t()` і `$view->tc()` безкоштовно:

```php
$app->views()->setTranslator($app->make(Translator::class));
```

```php
<h1><?= $view->t('welcome', ['name' => $user->name]) ?></h1>
<p><?= $view->tc('cart.items_count', count($items)) ?></p>
```

## Перемикання локалі на запит

Невеликий middleware, який читає заголовок `Accept-Language` (або значення рядка запиту / сесії) і оновлює глобальний перекладач:

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
        // 1. Явний ?lang=…
        if ($lang = $req->getQueryParams()['lang'] ?? null) {
            if (in_array($lang, self::SUPPORTED, true)) return $lang;
        }
        // 2. Сесія
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

Прив’яжіть перекладач як **синглтон**, щоб один і той самий екземпляр розділявся в межах запиту:

```php
$app->singleton(Translator::class, function () {
    $t = new Translator('en');
    $t->addPath(__DIR__ . '/../lang');
    return $t;
});
$app->use(LocaleMiddleware::class);
```

## Реальний приклад — сторінка `cart.empty`

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

`tc(...)` автоматично передає `:count`, тож вам не потрібно.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Сторінка рендерить сирий ключ (`'cart.empty.title'`) | Відсутній переклад і в поточній, і в запасній локалі | Додайте ключ або задайте запасну локаль, у якій він є. |
| `:name` показується буквально у виводі | Забули передати його в масиві `$replace` | Завжди включайте кожен `:placeholder`, згаданий у рядку. |
| Форму множини обрано невірно для російської | Не використано синтаксис інтервалів | Російській потрібні 3 форми: `[1] X товар|[2,4] X товара|[5,*] X товаров`. Двоформена не працює. |
| Валідатор усе ще англійською після `setLocale('ru')` | Валідатору не дали перекладач | Викличте `Validator::setTranslator(...)` або передайте на екземпляр. |
| Нові переклади не підхоплюються після `addPath()` | Закешовані для поточної локалі | `addPath()` очищає кеш за вас; якщо ви це обійшли, перестворіть Translator. |
| Синтаксична помилка у файлі локалі ламає весь застосунок | Друкарська помилка в `ru.php` → `require` викидає виняток | Запустіть `php -l lang/ru.php` після редагування. |

## Шпаргалка

```php
$t = new Translator('en', fallback: 'en');
$t->addPath(__DIR__ . '/../lang');
$t->addMessages('en', ['key' => 'value']);

$t->get('welcome', ['name' => 'Alice']);              // string
$t->choice('items.count', 5, ['attribute' => 'X']);    // множина
$t->setLocale('ru'); $t->setFallback('en');

// Під’єднання
Validator::setTranslator($t);
$app->views()->setTranslator($t);

// Формат файлу
return [
    'welcome'     => 'Welcome, :name!',
    'items.count' => '{0} no items|{1} :count item|[2,*] :count items',
];
```

[JSON-RPC →](json-rpc)
