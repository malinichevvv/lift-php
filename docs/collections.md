---
layout: page
title: Collections
nav_order: 11
---

# Collections

`Lift\Support\Collection` is a fluent, immutable-by-default array wrapper. Most methods return a **new** `Collection` instance; a small set of explicitly mutable helpers (`push`, `put`, `forget`, `transform`, `each`) mutate in place and return `$this` for chaining.

`Collection` implements `Countable`, `IteratorAggregate`, `JsonSerializable`, and `ArrayAccess`.

---

## Creating a collection

```php
use Lift\Support\Collection;

$c = Collection::make([1, 2, 3]);          // from an array
$c = new Collection(['a' => 1, 'b' => 2]); // constructor form
$c = Collection::make();                    // empty
```

---

## Transformation

These methods return a **new** collection.

### `map(callable $callback): static`

Apply a callback to every item.

```php
$doubled = Collection::make([1, 2, 3])->map(fn($v) => $v * 2);
// [2, 4, 6]
```

### `flatMap(callable $callback): static`

Map each item to an array (or Collection) and flatten the result by one level.

```php
Collection::make([1, 2])->flatMap(fn($v) => [$v, $v * 10]);
// [1, 10, 2, 20]
```

### `filter(?callable $callback = null): static`

Keep items that pass the callback. Without a callback, removes falsy values (`0`, `''`, `null`, `false`).

```php
Collection::make([1, 2, 3, 4])->filter(fn($v) => $v % 2 === 0); // [2, 4]
Collection::make([0, 1, '', 'a', null])->filter();               // [1, 'a']
```

### `reject(callable $callback): static`

Inverse of `filter` — keeps items where the callback returns `false`.

```php
Collection::make([1, 2, 3])->reject(fn($v) => $v === 2); // [1, 3]
```

### `reduce(callable $callback, mixed $initial = null): mixed`

Reduce the collection to a single value.

```php
$sum = Collection::make([1, 2, 3])->reduce(fn($carry, $v) => $carry + $v, 0); // 6
```

---

## Extraction

### `first(?callable $callback = null, mixed $default = null): mixed`

Return the first item, optionally matching a callback.

```php
Collection::make([1, 2, 3])->first();                     // 1
Collection::make([1, 2, 3])->first(fn($v) => $v > 1);   // 2
Collection::make([])->first(null, 'default');              // 'default'
```

### `last(?callable $callback = null, mixed $default = null): mixed`

Return the last item, optionally matching a callback.

### `take(int $limit): static`

Take the first `$limit` items. Negative values take from the end.

```php
Collection::make([1, 2, 3, 4])->take(2);  // [1, 2]
Collection::make([1, 2, 3, 4])->take(-2); // [3, 4]
```

### `skip(int $count): static`

Skip the first `$count` items.

```php
Collection::make([1, 2, 3, 4])->skip(2); // [3, 4]
```

### `slice(int $offset, ?int $length = null): static`

Return a slice, preserving keys.

### `chunk(int $size): static`

Split into chunks of `$size`, each returned as a `Collection`.

```php
$chunks = Collection::make([1, 2, 3, 4, 5])->chunk(2);
// Collection of: [1,2], [3,4], [5]
```

---

## Grouping, keying, plucking

### `pluck(string $key, ?string $indexBy = null): static`

Extract a column. Works on arrays and objects.

```php
$names = Collection::make($users)->pluck('name');
// ['Alice', 'Bob', 'Charlie']

// Indexed by another column
$emails = Collection::make($users)->pluck('email', 'id');
// [1 => 'alice@...', 2 => 'bob@...']
```

### `groupBy(string|callable $key): static`

Group items into sub-collections keyed by a column or callback.

```php
$byRole = Collection::make($users)->groupBy('role');
$byRole->get('admin')->count(); // number of admins
```

### `keyBy(string|callable $key): static`

Re-index the collection by a column or callback.

```php
$byId = Collection::make($users)->keyBy('id');
$byId->get(42); // user with id=42
```

---

## Sorting

### `sortBy(string|callable $key, bool $descending = false): static`

Sort by a column name or callback. Returns a new, re-indexed collection.

```php
Collection::make($users)->sortBy('name');
Collection::make($products)->sortBy('price', descending: true);
Collection::make($items)->sortBy(fn($item) => $item['price'] * $item['qty']);
```

### `sortByDesc(string|callable $key): static`

Shorthand for `sortBy($key, descending: true)`.

### `sort(?callable $callback = null): static`

Sort a flat list of scalars. Optionally provide a comparison callback.

```php
Collection::make([3, 1, 2])->sort(); // [1, 2, 3]
```

### `sortKeys(bool $descending = false): static`

Sort by key.

### `reverse(): static`

Reverse the order of items.

---

## Set operations

### `unique(?string $key = null): static`

Remove duplicates. Pass a column name to deduplicate objects/arrays by that field.

```php
Collection::make([1, 2, 2, 3])->unique();        // [1, 2, 3]
Collection::make($rows)->unique('email');         // one row per email
```

### `flatten(int $depth = PHP_INT_MAX): static`

Recursively flatten nested arrays/collections.

```php
Collection::make([[1, 2], [3, [4, 5]]])->flatten();    // [1,2,3,4,5]
Collection::make([[1, 2], [3, [4, 5]]])->flatten(1);   // [1,2,3,[4,5]]
```

### `merge(array|Collection $items): static`

Merge with another array or collection.

### `diff(array $items): static`

Return items not present in `$items`.

### `intersect(array $items): static`

Return items also present in `$items`.

---

## Keys and values

```php
$c->keys();    // new Collection of keys
$c->values();  // new Collection (re-indexed)
$c->flip();    // swap keys and values
```

---

## Search and checking

### `contains(mixed $valueOrCallback): bool`

```php
$c->contains(42);                        // strict equality
$c->contains(fn($v) => $v > 10);        // callback
```

### `has(mixed $key): bool`

Check if a key exists.

### `where(string $key, mixed $value): static`

Filter items where the given column equals the value (strict).

```php
Collection::make($users)->where('active', true);
```

---

## Aggregates

```php
$c->count();         // total items
$c->sum();           // sum of scalar items
$c->sum('price');    // sum of a column (arrays or objects)
$c->avg();           // average
$c->avg('score');    // average of a column
$c->min();           // minimum scalar
$c->min('price');    // minimum of a column
$c->max();           // maximum scalar
$c->max('rating');   // maximum of a column
```

---

## Info

```php
$c->isEmpty();    // true when count === 0
$c->isNotEmpty(); // true when count > 0
```

---

## Accessing items

```php
$c->get('key');           // item by key, null if missing
$c->get('key', 'default'); // with a fallback value
$c->all();                // raw array (all items)
$c->first();              // first item (no mutation)
$c->last();               // last item (no mutation)
```

---

## Exporting

```php
$c->toArray();   // array, with nested JsonSerializable objects resolved
$c->toJson();    // JSON string
json_encode($c); // uses JsonSerializable — same as toJson()
```

---

## Mutable helpers

These mutate the collection in place and return `$this`.

```php
$c->push($value);          // append
$c->put('key', $value);    // set by key
$c->forget('key');         // remove by key

$c->each(function ($value, $key) {
    // return false to stop iteration
});

$c->transform(fn($v) => strtoupper($v)); // mutate every item in place
```

---

## Interface support

### `Countable`

```php
count($c);       // same as $c->count()
```

### `IteratorAggregate`

```php
foreach ($c as $key => $value) {
    // ...
}
```

### `ArrayAccess`

```php
$c[] = 'appended';
$c['key'] = 'value';
$val = $c['key'];
unset($c['key']);
isset($c['key']); // bool
```

---

## Chaining example

```php
$report = Collection::make($orders)
    ->filter(fn($o) => $o['status'] === 'completed')
    ->sortByDesc('total')
    ->take(10)
    ->map(fn($o) => [
        'id'       => $o['id'],
        'customer' => $o['customer_name'],
        'total'    => number_format($o['total'], 2),
    ]);

return Response::json($report->values()->all());
```
