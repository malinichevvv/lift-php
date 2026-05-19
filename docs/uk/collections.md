---
layout: page
title: Колекції
nav_order: 23
---

# Колекції

`Lift\Support\Collection` — це плавна, незмінна за замовчуванням обгортка над масивами PHP. Вона перетворює ланцюжки на кшталт *«відфільтрувати активних користувачів, згрупувати за країною, відобразити в email, порахувати кожну групу»* в один вираз.

> Ментальна модель: `Collection` для `array` — те саме, що потік для списку: зчіплюваний об’єкт, де кожен метод повертає **нову** Collection із перетвореними даними. Мутація вмикається явно через кілька ясно названих методів.

## Коли її використовувати

- Вас тягне вкласти `array_map(array_filter(array_values($x), …), …)`.
- Ви пишете `foreach` лише щоб обчислити одне підсумкове значення.
- Вам потрібні `pluck`, `groupBy`, `keyBy`, `sortBy('field')` тощо без ручної мороки з ключами.

Для одноразової роботи (один `array_map`) залишайтеся з масивами PHP — Collection потрібні для ланцюжків.

## Демо за дві секунди

```php
use Lift\Support\Collection;

$activeEmails = Collection::make($users)
    ->filter(fn($u) => $u['active'])
    ->sortBy('name')
    ->pluck('email')
    ->values();

// $activeEmails — це Collection. Поверніться до масиву, коли закінчите:
$array = $activeEmails->all();        // ['a@b.c', …]
```

## Створення

```php
Collection::make();                          // порожня
Collection::make([1, 2, 3]);
Collection::make(['a' => 1, 'b' => 2]);
new Collection($items);                       // те саме
```

## Перетворення — повертає нову Collection

```php
->map(fn($v, $k) => $v * 2)
->flatMap(fn($v) => [$v, $v])              // map + вирівнювання на один рівень
->filter(fn($v) => $v > 0)                 // залишити збіги; values() переіндексує
->reject(fn($v) => $v > 0)                 // протилежність filter
->reduce(fn($acc, $v) => $acc + $v, 0)     // повертає акумулятор (не Collection)
```

Примітка: `filter()` і `reject()` переіндексують результат (послідовні цілочислові ключі). Використовуйте `where(...)`, якщо хочете зберегти ключі.

## Витяг і нарізка

```php
->first();                              // перший елемент або null
->first(fn($v) => $v > 5);              // перший збіглий
->first(fn($v) => $v > 5, $default);    // зі значенням за замовчуванням

->last();
->last(fn($v) => $v > 5, $default);

->take(3);                              // перші 3
->take(-3);                             // останні 3
->skip(2);                              // відкинути перші 2
->slice(2, 5);                          // [2, 7)

->chunk(2);                             // Collection із Collection по 2 кожна
```

## Групування / створення ключів / витяг

```php
$users = [
    ['id' => 1, 'role' => 'admin', 'email' => 'a@x'],
    ['id' => 2, 'role' => 'user',  'email' => 'b@x'],
    ['id' => 3, 'role' => 'admin', 'email' => 'c@x'],
];

Collection::make($users)
    ->groupBy('role');
// [
//   'admin' => Collection [ user1, user3 ],
//   'user'  => Collection [ user2 ],
// ]

Collection::make($users)
    ->keyBy('id');
// [
//   1 => user1,
//   2 => user2,
//   3 => user3,
// ]

Collection::make($users)
    ->pluck('email');               // ['a@x', 'b@x', 'c@x']
Collection::make($users)
    ->pluck('email', 'id');         // [1 => 'a@x', 2 => 'b@x', 3 => 'c@x']
```

`groupBy` і `keyBy` також приймають callback: `keyBy(fn($u) => "user-{$u['id']}")`.

## Сортування

```php
->sort();                                // базове за зростанням
->sort(fn($a, $b) => $a['age'] <=> $b['age']);

->sortBy('age');                         // за зростанням за полем
->sortByDesc('age');
->sortBy(fn($u) => strtolower($u['name'])); // за обчисленим значенням

->sortKeys();
->reverse();
```

## Операції над множинами

```php
->unique();                          // дедуплікація
->unique('email');                   // дедуплікація за полем
->flatten();                         // рекурсивно
->flatten(1);                        // один рівень

->merge([10, 11]);                   // додати в кінець
->diff([2, 3]);                      // значення, яких немає в аргументі
->intersect([2, 3]);                 // значення, присутні в обох
```

## Ключі / значення / переворот

```php
->keys();          // Collection ключів
->values();        // Collection значень (переіндексована)
->flip();          // поміняти місцями ключі/значення  (кожне значення має бути хешовним)
```

## Пошук / перевірка

```php
->contains(42);
->contains(fn($v) => $v > 50);
->has('email');                          // перевірка ключа (не значення)

->where('status', 'active');             // фільтр, що зберігає ключі
```

## Агрегати

```php
->count();
->sum();
->sum('amount');                         // за полем
->sum(fn($x) => $x['price'] * $x['qty']);// за callback

->avg();
->avg('rating');

->min();
->min('price');

->max();
->max('price');

->isEmpty();
->isNotEmpty();
```

## Доступ і експорт

```php
$c->get('email');                        // 'a@b.c' або null
$c->get('email', 'default@x.y');

$c->all();                               // сирий нижчележний масив
$c->toArray();                           // рекурсивно (JsonSerializable розгортаються)
$c->toJson();                            // рядок JSON
$c->jsonSerialize();                     // для json_encode()
```

`Collection` реалізує `JsonSerializable`, `Countable`, `IteratorAggregate` і `ArrayAccess`, тому:

```php
foreach ($collection as $key => $value) { … }       // ітеровна
count($collection);                                   // працює
$collection[0];                                       // працює
return $collection;                                   // обробник маршруту автоматично кодує в JSON
```

## Змінювальні помічники — повертають `$this`

Ці **справді** мутують на місці. Використовуйте помірно; вони для рідкісного випадку, коли незмінність шкодить продуктивності:

```php
$c->push($value);                        // додати в кінець
$c->put($key, $value);                   // установити за ключем
$c->forget($key);                        // видалити
$c->each(fn($v, $k) => …);               // foreach із раннім виходом, якщо callback повертає false
$c->transform(fn($v) => $v * 2);         // map на місці
```

## Реальний приклад — звіт про продажі

```php
$sales = Collection::make($orders)
    ->filter(fn($o) => $o['status'] === 'paid')
    ->groupBy(fn($o) => substr($o['paid_at'], 0, 7))   // 'YYYY-MM'
    ->map(fn(Collection $month) => [
        'count'   => $month->count(),
        'revenue' => $month->sum('total'),
        'top_country' => $month
            ->groupBy('country')
            ->map->count()                                // скорочення не підтримується — робіть так:
            ->sortByDesc(fn($x) => $x)
            ->keys()
            ->first(),
    ])
    ->sortKeys();

return Response::json($sales);
```

Вивід — це карта місяць → статистика, цілком побудована без жодного явного циклу.

## Ідіоми

### Перетворити елементи Paginator

```php
$page = $db->table('posts')->paginate(1, 20);
$tags = Collection::make($page->items())
    ->pluck('tags')
    ->flatten()
    ->unique()
    ->values();
```

### Згрупувати рядки за зовнішнім ключем (ручне жадібне завантаження)

```php
$users = $db->table('users')->whereIn('id', $userIds)->get();
$byId  = Collection::make($users)->keyBy('id')->all();

foreach ($posts as $post) {
    $post['author'] = $byId[$post['user_id']] ?? null;
}
```

### Швидко щось агрегувати

```php
$avgRating = Collection::make($reviews)
    ->where('product_id', $productId)
    ->avg('rating');
```

### Розбити на чанки для пакетної обробки

```php
Collection::make($emails)
    ->chunk(100)
    ->each(fn(Collection $batch) => $mailer->sendBulk($batch->all()));
```

## Нотатки про продуктивність

- Кожен незмінювальний метод виділяє нову Collection із новим масивом. Для дуже гарячих циклів по мільйонах елементів звичайний `foreach` швидший.
- `sortBy('field')` — це `O(n log n)` із викликаним компаратором — годиться для тисяч, повільно для мільйонів.
- `Collection` не обчислює ліниво. Для лінивих конвеєрів над генераторами пишіть `foreach` із `yield`.

Для типових вебнавантажень (десятки–тисячі рядків) виграш у читабельності домінує над втратою продуктивності.

## Часті підводні камені

| Симптом | Причина | Виправлення |
|---|---|---|
| Результат має ключі замість `[0, 1, 2…]` | `filter()` переіндексує, але `where()` і `unique('field')` зберігають ключі | Викличте `->values()` у кінці, якщо потрібен список. |
| `pluck('foo')` на об’єктах повертає `null` | Об’єкти не надають `foo` як публічну властивість (або елемент масиву) | Зробіть властивість публічною або спершу витягніть: `map(fn($o) => $o->getFoo())`. |
| `merge()` перезаписав мої числові ключі | `array_merge` перенумеровує цілочислові ключі | Використовуйте семантику `+` вручну, якщо потрібно їх зберегти: `$c->all() + $other`. |
| `groupBy` створює `Collection` із `Collection`, а не масивів | Це так задумано — продовжуйте ланцюжок або викличте `->toArray()`. |
| `first(fn ...)` повертає `null` для хибних, але валідних збігів на кшталт `0` | За замовчуванням — `null`; вам може знадобитися сигнальне значення | Передайте явне значення за замовчуванням: `first($cb, $sentinel)`. |

## Шпаргалка

```php
Collection::make($items)
    ->filter(fn($x) => $x['active'])
    ->map(fn($x) => $x['email'])
    ->unique()
    ->sort()
    ->values()
    ->all();

// Агрегати
Collection::make($items)->sum('amount');
Collection::make($items)->groupBy('country')->map->count();   // (не підтримується; див. реальний приклад)

// Ітерація
foreach (Collection::make($items) as $item) { … }
count($collection);
$collection[0];
return $collection;          // серіалізується в JSON автоматично
```

[Безпека →](security)
