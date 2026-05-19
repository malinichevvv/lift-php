---
layout: page
title: Date
nav_order: 41
---

# Date

`Lift\Support\Date` — це набір **чистих статичних помічників** для роботи з датою/часом з урахуванням часових поясів. Усі методи приймають стандартні значення `DateTimeInterface` і повертають `DateTimeImmutable` — нічого не мутується.

> Ментальна модель: `Date` — це не клас, який ви створюєте. Це набір функцій, згрупованих під одним простором імен. Передайте `DateTimeImmutable`, отримайте його назад.

## Приклад за 30 секунд

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

## Створення дат

```php
Date::now();                         // DateTimeImmutable — поточний момент, часовий пояс PHP за замовчуванням
Date::now('America/New_York');       // поточний момент у конкретному часовому поясі

Date::parse('2026-05-15 10:00:00');
Date::parse('next monday');
Date::parse('first day of next month');
Date::parse($existingDateTime, 'UTC');  // загорнути + конвертувати часовий пояс
```

`parse()` приймає будь-який рядок, зрозумілий `DateTimeImmutable`, плюс будь-який екземпляр `DateTimeInterface`.

## Конвертація часового поясу

```php
$utc  = Date::parse('2026-05-15 12:00:00', 'UTC');
$kyiv = Date::inTimezone($utc, 'Europe/Kyiv');   // 2026-05-15 15:00:00 +03:00

// Форматувати прямо в іншому часовому поясі
echo Date::format($utc, 'H:i', 'America/New_York');  // "08:00"
```

## Арифметика

`add()` і `sub()` приймають:

- Людиночитані рядки: `"3 days"`, `"2 months"`, `"90 minutes"`, `"1 year"`
- Інтервали ISO 8601: `"P1Y"`, `"P2M"`, `"PT30M"`, `"P1Y2M3DT4H"`
- Екземпляри `DateInterval`

```php
$date = Date::parse('2026-01-31');

Date::add($date, '1 month');          // 2026-03-03 (PHP додає 31 день; переповнення Jan → Feb)
Date::add($date, 'P1M');             // те саме
Date::add($date, new \DateInterval('P7D'));  // + 7 днів

Date::sub($date, '2 years');
Date::sub($date, '90 minutes');
```

## Календарні межі

```php
$date = Date::parse('2026-05-15 14:37:22');

// Початок одиниці (час обнулено)
Date::startOf($date, 'minute'); // 2026-05-15 14:37:00
Date::startOf($date, 'hour');   // 2026-05-15 14:00:00
Date::startOf($date, 'day');    // 2026-05-15 00:00:00
Date::startOf($date, 'week');   // 2026-05-11 00:00:00  (понеділок)
Date::startOf($date, 'month');  // 2026-05-01 00:00:00
Date::startOf($date, 'year');   // 2026-01-01 00:00:00

// Кінець одиниці
Date::endOf($date, 'day');    // 2026-05-15 23:59:59
Date::endOf($date, 'week');   // 2026-05-17 23:59:59  (неділя)
Date::endOf($date, 'month');  // 2026-05-31 23:59:59
Date::endOf($date, 'year');   // 2026-12-31 23:59:59
```

Тиждень починається з **понеділка** (стандарт ISO 8601).

## Людиночитана різниця

`diffForHumans()` обчислює різницю між датою і *зараз* (або власною базою) і повертає людський рядок:

```php
Date::diffForHumans($date);                        // відносно зараз
Date::diffForHumans($date, $customBase);           // відносно $customBase
```

| Різниця | Вивід |
|---|---|
| < 30 с | `"just now"` |
| 30 с – 89 с | `"1 minute ago"` |
| 90 с – 59 хв | `"N minutes ago"` |
| ~1 год | `"1 hour ago"` |
| 1 год – 23 год | `"N hours ago"` |
| ~1 день | `"1 day ago"` |
| 2 – 6 днів | `"N days ago"` |
| ~1 тиждень | `"1 week ago"` |
| 2 – 4 тижні | `"N weeks ago"` |
| ~1 місяць | `"1 month ago"` |
| 2 – 11 місяців | `"N months ago"` |
| ~1 рік | `"1 year ago"` |
| 2+ роки | `"N years ago"` |

Майбутні дати використовують форму `"in N …"`: `"in 3 days"`, `"in 2 hours"` тощо.

## Предикати

```php
Date::isToday($date);           // bool — той самий календарний день, що сьогодні (у часовому поясі дати)
Date::isPast($date);            // bool — строго до зараз
Date::isFuture($date);          // bool — строго після зараз
Date::isSameDay($dateA, $dateB);// bool — той самий календарний день (використовується часовий пояс $dateA)
```

## Практичні рецепти

### Мітки часу у відповіді API

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

### Запит до БД з областю — «замовлення цього тижня»

```php
$start = Date::startOf(Date::now(), 'week');
$end   = Date::endOf(Date::now(), 'week');

$orders = $db->table('orders')
    ->where('created_at', '>=', Date::format($start, 'Y-m-d H:i:s'))
    ->where('created_at', '<=', Date::format($end,   'Y-m-d H:i:s'))
    ->get();
```

### Відображення часу в часовому поясі користувача

```php
$userTz   = $request->getAttribute('user')['timezone'] ?? 'UTC';
$postedAt = Date::parse($post['created_at'], 'UTC');

echo Date::format($postedAt, 'D d M Y H:i', $userTz);
```

### Каст моделі + помічник

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
