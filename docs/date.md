---
layout: page
title: Date
nav_order: 41
---

# Date

`Lift\Support\Date` is a collection of **pure static helpers** for timezone-aware date/time work. All methods accept standard `DateTimeInterface` values and return `DateTimeImmutable` — nothing is mutated.

> Mental model: `Date` is not a class you instantiate. It's a set of functions grouped under one namespace. Pass in a `DateTimeImmutable`, get one back.

## 30-second example

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

## Creating dates

```php
Date::now();                         // DateTimeImmutable — current moment, PHP default timezone
Date::now('America/New_York');       // current moment in a specific timezone

Date::parse('2026-05-15 10:00:00');
Date::parse('next monday');
Date::parse('first day of next month');
Date::parse($existingDateTime, 'UTC');  // wrap + convert timezone
```

`parse()` accepts any string that `DateTimeImmutable` understands plus any `DateTimeInterface` instance.

## Timezone conversion

```php
$utc  = Date::parse('2026-05-15 12:00:00', 'UTC');
$kyiv = Date::inTimezone($utc, 'Europe/Kyiv');   // 2026-05-15 15:00:00 +03:00

// Format directly in another timezone
echo Date::format($utc, 'H:i', 'America/New_York');  // "08:00"
```

## Arithmetic

`add()` and `sub()` accept:

- Human-readable strings: `"3 days"`, `"2 months"`, `"90 minutes"`, `"1 year"`
- ISO 8601 intervals: `"P1Y"`, `"P2M"`, `"PT30M"`, `"P1Y2M3DT4H"`
- `DateInterval` instances

```php
$date = Date::parse('2026-01-31');

Date::add($date, '1 month');          // 2026-03-03 (PHP adds 31 days; Jan → Feb overflow)
Date::add($date, 'P1M');             // same
Date::add($date, new \DateInterval('P7D'));  // + 7 days

Date::sub($date, '2 years');
Date::sub($date, '90 minutes');
```

## Calendar boundaries

```php
$date = Date::parse('2026-05-15 14:37:22');

// Start of unit (time zeroed)
Date::startOf($date, 'minute'); // 2026-05-15 14:37:00
Date::startOf($date, 'hour');   // 2026-05-15 14:00:00
Date::startOf($date, 'day');    // 2026-05-15 00:00:00
Date::startOf($date, 'week');   // 2026-05-11 00:00:00  (Monday)
Date::startOf($date, 'month');  // 2026-05-01 00:00:00
Date::startOf($date, 'year');   // 2026-01-01 00:00:00

// End of unit
Date::endOf($date, 'day');    // 2026-05-15 23:59:59
Date::endOf($date, 'week');   // 2026-05-17 23:59:59  (Sunday)
Date::endOf($date, 'month');  // 2026-05-31 23:59:59
Date::endOf($date, 'year');   // 2026-12-31 23:59:59
```

Week starts on **Monday** (ISO 8601 standard).

## Human-readable diff

`diffForHumans()` computes the difference between a date and *now* (or a custom base) and returns a human string:

```php
Date::diffForHumans($date);                        // relative to now
Date::diffForHumans($date, $customBase);           // relative to $customBase
```

| Difference | Output |
|---|---|
| < 30 s | `"just now"` |
| 30 s – 89 s | `"1 minute ago"` |
| 90 s – 59 m | `"N minutes ago"` |
| ~1 h | `"1 hour ago"` |
| 1 h – 23 h | `"N hours ago"` |
| ~1 day | `"1 day ago"` |
| 2 – 6 days | `"N days ago"` |
| ~1 week | `"1 week ago"` |
| 2 – 4 weeks | `"N weeks ago"` |
| ~1 month | `"1 month ago"` |
| 2 – 11 months | `"N months ago"` |
| ~1 year | `"1 year ago"` |
| 2+ years | `"N years ago"` |

Future dates use the `"in N …"` form: `"in 3 days"`, `"in 2 hours"`, etc.

## Predicates

```php
Date::isToday($date);           // bool — same calendar day as today (in date's timezone)
Date::isPast($date);            // bool — strictly before now
Date::isFuture($date);          // bool — strictly after now
Date::isSameDay($dateA, $dateB);// bool — same calendar day (timezone of $dateA used)
```

## Practical recipes

### API response timestamps

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

### Scoped DB query — "this week's orders"

```php
$start = Date::startOf(Date::now(), 'week');
$end   = Date::endOf(Date::now(), 'week');

$orders = $db->table('orders')
    ->where('created_at', '>=', Date::format($start, 'Y-m-d H:i:s'))
    ->where('created_at', '<=', Date::format($end,   'Y-m-d H:i:s'))
    ->get();
```

### Display time in user's timezone

```php
$userTz   = $request->getAttribute('user')['timezone'] ?? 'UTC';
$postedAt = Date::parse($post['created_at'], 'UTC');

echo Date::format($postedAt, 'D d M Y H:i', $userTz);
```

### Model cast + helper

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

## Cheat sheet

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
