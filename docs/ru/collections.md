---
layout: page
title: Коллекции
nav_order: 23
---

# Коллекции

`Lift\Support\Collection` — это текучая, неизменяемая по умолчанию обёртка над массивами PHP. Она превращает цепочки вроде *«отфильтровать активных пользователей, сгруппировать по стране, отобразить в email, посчитать каждую группу»* в одно выражение.

> Ментальная модель: `Collection` для `array` — то же, что поток для списка: сцепляемый объект, где каждый метод возвращает **новую** Collection с преобразованными данными. Мутация включается явно через несколько ясно названных методов.

## Когда её использовать

- Вас тянет вложить `array_map(array_filter(array_values($x), …), …)`.
- Вы пишете `foreach` только чтобы вычислить одно итоговое значение.
- Вам нужны `pluck`, `groupBy`, `keyBy`, `sortBy('field')` и т. д. без ручной возни с ключами.

Для одноразовой работы (один `array_map`) оставайтесь с массивами PHP — Collection нужны для цепочек.

## Демо за две секунды

```php
use Lift\Support\Collection;

$activeEmails = Collection::make($users)
    ->filter(fn($u) => $u['active'])
    ->sortBy('name')
    ->pluck('email')
    ->values();

// $activeEmails — это Collection. Вернитесь к массиву, когда закончите:
$array = $activeEmails->all();        // ['a@b.c', …]
```

## Создание

```php
Collection::make();                          // пустая
Collection::make([1, 2, 3]);
Collection::make(['a' => 1, 'b' => 2]);
new Collection($items);                       // то же самое
```

## Преобразование — возвращает новую Collection

```php
->map(fn($v, $k) => $v * 2)
->flatMap(fn($v) => [$v, $v])              // map + выравнивание на один уровень
->filter(fn($v) => $v > 0)                 // оставить совпадения; values() переиндексирует
->reject(fn($v) => $v > 0)                 // противоположность filter
->reduce(fn($acc, $v) => $acc + $v, 0)     // возвращает аккумулятор (не Collection)
```

Примечание: `filter()` и `reject()` переиндексируют результат (последовательные целочисленные ключи). Используйте `where(...)`, если хотите сохранить ключи.

## Извлечение и нарезка

```php
->first();                              // первый элемент или null
->first(fn($v) => $v > 5);              // первый совпадающий
->first(fn($v) => $v > 5, $default);    // со значением по умолчанию

->last();
->last(fn($v) => $v > 5, $default);

->take(3);                              // первые 3
->take(-3);                             // последние 3
->skip(2);                              // отбросить первые 2
->slice(2, 5);                          // [2, 7)

->chunk(2);                             // Collection из Collection по 2 каждая
```

## Группировка / создание ключей / извлечение

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

`groupBy` и `keyBy` также принимают callback: `keyBy(fn($u) => "user-{$u['id']}")`.

## Сортировка

```php
->sort();                                // базовая по возрастанию
->sort(fn($a, $b) => $a['age'] <=> $b['age']);

->sortBy('age');                         // по возрастанию по полю
->sortByDesc('age');
->sortBy(fn($u) => strtolower($u['name'])); // по вычисленному значению

->sortKeys();
->reverse();
```

## Операции над множествами

```php
->unique();                          // дедупликация
->unique('email');                   // дедупликация по полю
->flatten();                         // рекурсивно
->flatten(1);                        // один уровень

->merge([10, 11]);                   // добавить в конец
->diff([2, 3]);                      // значения, которых нет в аргументе
->intersect([2, 3]);                 // значения, присутствующие в обоих
```

## Ключи / значения / переворот

```php
->keys();          // Collection ключей
->values();        // Collection значений (переиндексирована)
->flip();          // поменять местами ключи/значения  (каждое значение должно быть хешируемым)
```

## Поиск / проверка

```php
->contains(42);
->contains(fn($v) => $v > 50);
->has('email');                          // проверка ключа (не значения)

->where('status', 'active');             // фильтр, сохраняющий ключи
```

## Агрегаты

```php
->count();
->sum();
->sum('amount');                         // по полю
->sum(fn($x) => $x['price'] * $x['qty']);// по callback

->avg();
->avg('rating');

->min();
->min('price');

->max();
->max('price');

->isEmpty();
->isNotEmpty();
```

## Доступ и экспорт

```php
$c->get('email');                        // 'a@b.c' или null
$c->get('email', 'default@x.y');

$c->all();                               // сырой нижележащий массив
$c->toArray();                           // рекурсивно (JsonSerializable разворачиваются)
$c->toJson();                            // строка JSON
$c->jsonSerialize();                     // для json_encode()
```

`Collection` реализует `JsonSerializable`, `Countable`, `IteratorAggregate` и `ArrayAccess`, поэтому:

```php
foreach ($collection as $key => $value) { … }       // итерируема
count($collection);                                   // работает
$collection[0];                                       // работает
return $collection;                                   // обработчик маршрута автоматически кодирует в JSON
```

## Изменяющие помощники — возвращают `$this`

Эти **действительно** мутируют на месте. Используйте умеренно; они для редкого случая, когда неизменяемость вредит производительности:

```php
$c->push($value);                        // добавить в конец
$c->put($key, $value);                   // установить по ключу
$c->forget($key);                        // удалить
$c->each(fn($v, $k) => …);               // foreach с ранним выходом, если callback возвращает false
$c->transform(fn($v) => $v * 2);         // map на месте
```

## Реальный пример — отчёт о продажах

```php
$sales = Collection::make($orders)
    ->filter(fn($o) => $o['status'] === 'paid')
    ->groupBy(fn($o) => substr($o['paid_at'], 0, 7))   // 'YYYY-MM'
    ->map(fn(Collection $month) => [
        'count'   => $month->count(),
        'revenue' => $month->sum('total'),
        'top_country' => $month
            ->groupBy('country')
            ->map->count()                                // сокращение не поддерживается — делайте так:
            ->sortByDesc(fn($x) => $x)
            ->keys()
            ->first(),
    ])
    ->sortKeys();

return Response::json($sales);
```

Вывод — это карта месяц → статистика, целиком построенная без единого явного цикла.

## Идиомы

### Преобразовать элементы Paginator

```php
$page = $db->table('posts')->paginate(1, 20);
$tags = Collection::make($page->items())
    ->pluck('tags')
    ->flatten()
    ->unique()
    ->values();
```

### Сгруппировать строки по внешнему ключу (ручная жадная загрузка)

```php
$users = $db->table('users')->whereIn('id', $userIds)->get();
$byId  = Collection::make($users)->keyBy('id')->all();

foreach ($posts as $post) {
    $post['author'] = $byId[$post['user_id']] ?? null;
}
```

### Быстро что-то агрегировать

```php
$avgRating = Collection::make($reviews)
    ->where('product_id', $productId)
    ->avg('rating');
```

### Разбить на чанки для пакетной обработки

```php
Collection::make($emails)
    ->chunk(100)
    ->each(fn(Collection $batch) => $mailer->sendBulk($batch->all()));
```

## Заметки о производительности

- Каждый неизменяющий метод выделяет новую Collection с новым массивом. Для очень горячих циклов по миллионам элементов обычный `foreach` быстрее.
- `sortBy('field')` — это `O(n log n)` с вызываемым компаратором — годится для тысяч, медленно для миллионов.
- `Collection` не вычисляет лениво. Для ленивых конвейеров над генераторами пишите `foreach` с `yield`.

Для типичных веб-нагрузок (десятки–тысячи строк) выигрыш в читаемости доминирует над потерей производительности.

## Частые подводные камни

| Симптом | Причина | Исправление |
|---|---|---|
| Результат имеет ключи вместо `[0, 1, 2…]` | `filter()` переиндексирует, но `where()` и `unique('field')` сохраняют ключи | Вызовите `->values()` в конце, если нужен список. |
| `pluck('foo')` на объектах возвращает `null` | Объекты не предоставляют `foo` как публичное свойство (или элемент массива) | Сделайте свойство публичным или сначала извлеките: `map(fn($o) => $o->getFoo())`. |
| `merge()` перезаписал мои числовые ключи | `array_merge` перенумеровывает целочисленные ключи | Используйте семантику `+` вручную, если нужно их сохранить: `$c->all() + $other`. |
| `groupBy` создаёт `Collection` из `Collection`, а не массивов | Это так задумано — продолжайте цепочку или вызовите `->toArray()`. |
| `first(fn ...)` возвращает `null` для ложных, но валидных совпадений вроде `0` | По умолчанию — `null`; вам может понадобиться сигнальное значение | Передайте явное значение по умолчанию: `first($cb, $sentinel)`. |

## Шпаргалка

```php
Collection::make($items)
    ->filter(fn($x) => $x['active'])
    ->map(fn($x) => $x['email'])
    ->unique()
    ->sort()
    ->values()
    ->all();

// Агрегаты
Collection::make($items)->sum('amount');
Collection::make($items)->groupBy('country')->map->count();   // (не поддерживается; см. реальный пример)

// Итерация
foreach (Collection::make($items) as $item) { … }
count($collection);
$collection[0];
return $collection;          // сериализуется в JSON автоматически
```

[Безопасность →](security)
