---
layout: page
title: Date
nav_order: 41
---

# Date

`Lift\Support\Date` — это набор **чистых статических помощников** для работы с датой/временем с учётом часовых поясов. Все методы принимают стандартные значения `DateTimeInterface` и возвращают `DateTimeImmutable` — ничего не мутируется.

> Ментальная модель: `Date` — это не класс, который вы создаёте. Это набор функций, сгруппированных под одним пространством имён. Передайте `DateTimeImmutable`, получите его обратно.

## Пример за 30 секунд

```php
use Lift\Support\Date;

$now    = Date::now('Europe/Kyiv');
$past   = Date::sub($now, '2 hours');
$future = Date::add($now, '3 days');

echo Date::diffForHumans($past);              // "2 hours ago"
echo Date::diffForHumans($future);            // "in 3 days"
echo Date::format($now, 'D, d M Y', 'UTC');   // "Fri, 15 May 2026"
echo Date::startOf($now, 'month')->format('Y-m-d'); // "2026-05-01"
```

## Создание дат

```php
Date::now();                         // DateTimeImmutable — текущий момент, часовой пояс PHP по умолчанию
Date::now('America/New_York');       // текущий момент в конкретном часовом поясе

Date::parse('2026-05-15 10:00:00');
Date::parse('next monday');
Date::parse('first day of next month');
Date::parse($existingDateTime, 'UTC');  // обернуть + конвертировать часовой пояс
```

`parse()` принимает любую строку, понятную `DateTimeImmutable`, плюс любой экземпляр `DateTimeInterface`.

## Конвертация часового пояса

```php
$utc  = Date::parse('2026-05-15 12:00:00', 'UTC');
$kyiv = Date::inTimezone($utc, 'Europe/Kyiv');   // 2026-05-15 15:00:00 +03:00

// Форматировать прямо в другом часовом поясе
echo Date::format($utc, 'H:i', 'America/New_York');  // "08:00"
```

## Арифметика

`add()` и `sub()` принимают:

- Человекочитаемые строки: `"3 days"`, `"2 months"`, `"90 minutes"`, `"1 year"`
- Интервалы ISO 8601: `"P1Y"`, `"P2M"`, `"PT30M"`, `"P1Y2M3DT4H"`
- Экземпляры `DateInterval`

```php
$date = Date::parse('2026-01-31');

Date::add($date, '1 month');          // 2026-03-03 (PHP добавляет 31 день; переполнение Jan → Feb)
Date::add($date, 'P1M');             // то же
Date::add($date, new \DateInterval('P7D'));  // + 7 дней

Date::sub($date, '2 years');
Date::sub($date, '90 minutes');
```

## Календарные границы

```php
$date = Date::parse('2026-05-15 14:37:22');

// Начало единицы (время обнулено)
Date::startOf($date, 'minute'); // 2026-05-15 14:37:00
Date::startOf($date, 'hour');   // 2026-05-15 14:00:00
Date::startOf($date, 'day');    // 2026-05-15 00:00:00
Date::startOf($date, 'week');   // 2026-05-11 00:00:00  (понедельник)
Date::startOf($date, 'month');  // 2026-05-01 00:00:00
Date::startOf($date, 'year');   // 2026-01-01 00:00:00

// Конец единицы
Date::endOf($date, 'day');    // 2026-05-15 23:59:59
Date::endOf($date, 'week');   // 2026-05-17 23:59:59  (воскресенье)
Date::endOf($date, 'month');  // 2026-05-31 23:59:59
Date::endOf($date, 'year');   // 2026-12-31 23:59:59
```

Неделя начинается с **понедельника** (стандарт ISO 8601).

## Человекочитаемая разница

`diffForHumans()` вычисляет разницу между датой и *сейчас* (или собственной базой) и возвращает человеческую строку:

```php
Date::diffForHumans($date);                        // относительно сейчас
Date::diffForHumans($date, $customBase);           // относительно $customBase
```

| Разница | Вывод |
|---|---|
| < 30 с | `"just now"` |
| 30 с – 89 с | `"1 minute ago"` |
| 90 с – 59 м | `"N minutes ago"` |
| ~1 ч | `"1 hour ago"` |
| 1 ч – 23 ч | `"N hours ago"` |
| ~1 день | `"1 day ago"` |
| 2 – 6 дней | `"N days ago"` |
| ~1 неделя | `"1 week ago"` |
| 2 – 4 недели | `"N weeks ago"` |
| ~1 месяц | `"1 month ago"` |
| 2 – 11 месяцев | `"N months ago"` |
| ~1 год | `"1 year ago"` |
| 2+ года | `"N years ago"` |

Будущие даты используют форму `"in N …"`: `"in 3 days"`, `"in 2 hours"` и т. д.

## Предикаты

```php
Date::isToday($date);           // bool — тот же календарный день, что сегодня (в часовом поясе даты)
Date::isPast($date);            // bool — строго до сейчас
Date::isFuture($date);          // bool — строго после сейчас
Date::isSameDay($dateA, $dateB);// bool — тот же календарный день (используется часовой пояс $dateA)
```

## Практические рецепты

### Метки времени в ответе API

```php
$app->get('/events/{id}', function (Request $req) use ($db) {
    $event = $db->table('events')->where('id', $req->param('id'))->first();
    $date  = Date::parse($event['starts_at'], 'UTC');

    return Response::json([
        'id'          => $event['id'],
        'starts_at'   => Date::format($date, \DateTimeInterface::RFC3339),
        'starts_in'   => Date::diffForHumans($date),   // "in 3 days"
    ]);
});
```

### Запрос к БД с областью — «заказы этой недели»

```php
$start = Date::startOf(Date::now(), 'week');
$end   = Date::endOf(Date::now(), 'week');

$orders = $db->table('orders')
    ->where('created_at', '>=', Date::format($start, 'Y-m-d H:i:s'))
    ->where('created_at', '<=', Date::format($end,   'Y-m-d H:i:s'))
    ->get();
```

### Отображение времени в часовом поясе пользователя

```php
$userTz   = $request->getAttribute('user')['timezone'] ?? 'UTC';
$postedAt = Date::parse($post['created_at'], 'UTC');

echo Date::format($postedAt, 'D d M Y H:i', $userTz);
```

### Каст модели + помощник

```php
class Article extends Model
{
    protected array $casts = ['published_at' => 'datetime'];
}

$article = Article::find(1);
$published = $article->get('published_at');  // DateTimeImmutable

echo Date::diffForHumans($published);        // "3 days ago"
echo Date::isToday($published) ? 'today' : 'older';
```

## Шпаргалка

```php
use Lift\Support\Date;

Date::now('Europe/Kyiv')
Date::parse('2026-05-15', 'UTC')
Date::inTimezone($date, 'America/New_York')
Date::format($date, 'Y-m-d H:i:s', 'UTC')

Date::add($date, '3 days')
Date::sub($date, '2 months')

Date::startOf($date, 'month')    // 'minute'|'hour'|'day'|'week'|'month'|'year'
Date::endOf($date,   'year')

Date::diffForHumans($date)       // "2 hours ago" / "in 3 days" / "just now"
Date::isToday($date)
Date::isPast($date)
Date::isFuture($date)
Date::isSameDay($a, $b)
```

[Number →](number)
