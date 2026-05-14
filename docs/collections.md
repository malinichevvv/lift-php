---
layout: page
title: Collections
nav_order: 23
---

# Collections

`Lift\Support\Collection` is a fluent, immutable-by-default wrapper around PHP arrays. It turns chains like *"filter the active users, group by country, map to email, count each group"* into one expression.

> Mental model: a `Collection` is to `array` what a stream is to a list — a chainable object where each method returns a **new** Collection holding the transformed data. Mutation is opt-in via a handful of clearly-named methods.

## When to use it

- You're tempted to nest `array_map(array_filter(array_values($x), …), …)`.
- You're writing a `foreach` just to compute a single summary value.
- You want `pluck`, `groupBy`, `keyBy`, `sortBy('field')` etc. without manually fiddling with keys.

For one-shot work (a single `array_map`), stay with PHP arrays — Collections are about chaining.

## Two-second demo

```php
use Lift\Support\Collection;

$activeEmails = Collection::make($users)
    ->filter(fn($u) => $u['active'])
    ->sortBy('name')
    ->pluck('email')
    ->values();

// $activeEmails is a Collection. Get back to an array when you're done:
$array = $activeEmails->all();        // ['a@b.c', …]
```

## Building one

```php
Collection::make();                          // empty
Collection::make([1, 2, 3]);
Collection::make(['a' => 1, 'b' => 2]);
new Collection($items);                       // same thing
```

## Transformation — returns new Collection

```php
->map(fn($v, $k) => $v * 2)
->flatMap(fn($v) => [$v, $v])              // map + flatten one level
->filter(fn($v) => $v > 0)                 // keep matches; values() re-indexed
->reject(fn($v) => $v > 0)                 // opposite of filter
->reduce(fn($acc, $v) => $acc + $v, 0)     // returns the accumulator (not a Collection)
```

Note: `filter()` and `reject()` re-index the result (sequential integer keys). Use `where(...)` if you want to preserve keys.

## Extraction & slicing

```php
->first();                              // first element or null
->first(fn($v) => $v > 5);              // first matching
->first(fn($v) => $v > 5, $default);    // with default

->last();
->last(fn($v) => $v > 5, $default);

->take(3);                              // first 3
->take(-3);                             // last 3
->skip(2);                              // drop first 2
->slice(2, 5);                          // [2, 7)

->chunk(2);                             // Collection of Collections of 2 each
```

## Grouping / keying / plucking

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

`groupBy` and `keyBy` also accept a callback: `keyBy(fn($u) => "user-{$u['id']}")`.

## Sorting

```php
->sort();                                // basic asc
->sort(fn($a, $b) => $a['age'] <=> $b['age']);

->sortBy('age');                         // ascending by field
->sortByDesc('age');
->sortBy(fn($u) => strtolower($u['name'])); // by computed value

->sortKeys();
->reverse();
```

## Set operations

```php
->unique();                          // dedup
->unique('email');                   // dedup by field
->flatten();                         // recursive
->flatten(1);                        // one level

->merge([10, 11]);                   // append
->diff([2, 3]);                      // values not in argument
->intersect([2, 3]);                 // values present in both
```

## Keys / values / flip

```php
->keys();          // Collection of keys
->values();        // Collection of values (re-indexed)
->flip();          // swap keys/values  (each value must be hashable)
```

## Search / check

```php
->contains(42);
->contains(fn($v) => $v > 50);
->has('email');                          // key check (not value)

->where('status', 'active');             // filter that preserves keys
```

## Aggregates

```php
->count();
->sum();
->sum('amount');                         // by field
->sum(fn($x) => $x['price'] * $x['qty']);// by callback

->avg();
->avg('rating');

->min();
->min('price');

->max();
->max('price');

->isEmpty();
->isNotEmpty();
```

## Access & export

```php
$c->get('email');                        // 'a@b.c' or null
$c->get('email', 'default@x.y');

$c->all();                               // raw underlying array
$c->toArray();                           // recursive (JsonSerializables are unwrapped)
$c->toJson();                            // JSON string
$c->jsonSerialize();                     // for json_encode()
```

`Collection` implements `JsonSerializable`, `Countable`, `IteratorAggregate`, and `ArrayAccess`, so:

```php
foreach ($collection as $key => $value) { … }       // iterable
count($collection);                                   // works
$collection[0];                                       // works
return $collection;                                   // route handler auto-encodes to JSON
```

## Mutable helpers — return `$this`

These **do** mutate in place. Use sparingly; they're for the rare case where immutability hurts perf:

```php
$c->push($value);                        // append
$c->put($key, $value);                   // set by key
$c->forget($key);                        // delete
$c->each(fn($v, $k) => …);               // foreach with early-exit if callback returns false
$c->transform(fn($v) => $v * 2);         // in-place map
```

## Real-world example — sales report

```php
$sales = Collection::make($orders)
    ->filter(fn($o) => $o['status'] === 'paid')
    ->groupBy(fn($o) => substr($o['paid_at'], 0, 7))   // 'YYYY-MM'
    ->map(fn(Collection $month) => [
        'count'   => $month->count(),
        'revenue' => $month->sum('total'),
        'top_country' => $month
            ->groupBy('country')
            ->map->count()                                // shorthand not supported — do this:
            ->sortByDesc(fn($x) => $x)
            ->keys()
            ->first(),
    ])
    ->sortKeys();

return Response::json($sales);
```

The output is a month → stats map, all built without writing a single explicit loop.

## Idioms

### Convert a Paginator's items

```php
$page = $db->table('posts')->paginate(1, 20);
$tags = Collection::make($page->items())
    ->pluck('tags')
    ->flatten()
    ->unique()
    ->values();
```

### Group rows by a foreign key (manual eager-load)

```php
$users = $db->table('users')->whereIn('id', $userIds)->get();
$byId  = Collection::make($users)->keyBy('id')->all();

foreach ($posts as $post) {
    $post['author'] = $byId[$post['user_id']] ?? null;
}
```

### Aggregate something quick

```php
$avgRating = Collection::make($reviews)
    ->where('product_id', $productId)
    ->avg('rating');
```

### Chunk for batching

```php
Collection::make($emails)
    ->chunk(100)
    ->each(fn(Collection $batch) => $mailer->sendBulk($batch->all()));
```

## Performance notes

- Each immutable method allocates a new Collection holding a new array. For very hot loops over millions of items, a plain `foreach` is faster.
- `sortBy('field')` is `O(n log n)` with a callable comparator — fine for thousands, slow for millions.
- `Collection` doesn't lazy-evaluate. For lazy pipelines over generators, write a `foreach` with `yield`.

For typical web payloads (tens to thousands of rows), the readability win dominates the perf hit.

## Common pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Result has keys instead of `[0, 1, 2…]` | `filter()` re-indexes, but `where()` and `unique('field')` preserve keys | Call `->values()` at the end if you need a list. |
| `pluck('foo')` on objects returns `null`s | The objects don't expose `foo` as a public property (or array element) | Make the property public, or extract first: `map(fn($o) => $o->getFoo())`. |
| `merge()` overwrote my numeric keys | `array_merge` re-numbers integer keys | Use `+` semantics manually if you need to preserve them: `$c->all() + $other`. |
| `groupBy` produces `Collection` of `Collection`s, not arrays | That's by design — chain further or call `->toArray()`. |
| `first(fn ...)` returns `null` for falsey but valid matches like `0` | Default is `null`; you may want a sentinel | Pass an explicit default: `first($cb, $sentinel)`. |

## Cheat sheet

```php
Collection::make($items)
    ->filter(fn($x) => $x['active'])
    ->map(fn($x) => $x['email'])
    ->unique()
    ->sort()
    ->values()
    ->all();

// Aggregates
Collection::make($items)->sum('amount');
Collection::make($items)->groupBy('country')->map->count();   // (not supported; see real example)

// Iteration
foreach (Collection::make($items) as $item) { … }
count($collection);
$collection[0];
return $collection;          // JSON-serialised automatically
```

[Security →](security)
