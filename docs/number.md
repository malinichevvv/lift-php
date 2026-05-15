---
layout: page
title: Number
nav_order: 42
---

# Number

`Lift\Support\Number` provides static helpers for formatting numbers, money, file sizes, and large values. When the PHP `intl` extension is present, `money()` and locale-specific formatting use `NumberFormatter` for correct locale output; otherwise a built-in fallback covers the most common currencies.

```php
use Lift\Support\Number;

Number::money(1234.50, 'USD');           // "$1,234.50"
Number::money(1234.50, 'EUR', 'de_DE'); // "1.234,50 €"
Number::percent(85.6);                  // "85.6%"
Number::fileSize(1_572_864);            // "1.5 MB"
Number::abbreviate(2_300_000);          // "2.3M"
Number::ordinal(21);                    // "21st"
```

## money()

```php
Number::money(float|int $amount, string $currency = 'USD', string $locale = 'en_US'): string
```

Formats an amount with the correct currency symbol, grouping, and decimal places for the given locale.

```php
Number::money(1234.5);                    // "$1,234.50"      (USD, en_US)
Number::money(1234.5,  'EUR', 'fr_FR');   // "1 234,50 €"
Number::money(1234.5,  'GBP', 'en_GB');   // "£1,234.50"
Number::money(150_000, 'JPY', 'ja_JP');   // "¥150,000"       (no decimals)
Number::money(9900,    'UAH', 'uk_UA');   // "9 900,00 ₴"
```

When `intl` is not installed, the fallback covers USD, EUR, GBP, JPY, CAD, AUD, INR, RUB, UAH, and a dozen more. Unknown currencies are prefixed with their ISO code (`CHF 1,234.50`).

## format()

```php
Number::format(float|int $value, int $decimals = 2, string $decimal = '.', string $thousands = ','): string
```

General-purpose number formatter with configurable separators.

```php
Number::format(1234567.891);                      // "1,234,567.89"
Number::format(1234567.891, 0);                   // "1,234,568"
Number::format(1234567.891, 2, ',', '.');          // "1.234.567,89"  (European)
Number::format(0.5, 4);                            // "0.5000"
```

## percent()

```php
Number::percent(float|int $value, int $decimals = 1): string
```

Pass the value as a plain percentage — **not** a fraction.

```php
Number::percent(85.6);      // "85.6%"
Number::percent(100, 0);    // "100%"
Number::percent(33.333, 2); // "33.33%"
Number::percent(0.5);       // "0.5%"
```

## fileSize()

```php
Number::fileSize(int $bytes, int $decimals = 1): string
```

Formats a byte count with binary prefixes (1 KB = 1 024 B).

```php
Number::fileSize(0);               // "0 B"
Number::fileSize(512);             // "512 B"
Number::fileSize(1_024);           // "1.0 KB"
Number::fileSize(1_572_864);       // "1.5 MB"
Number::fileSize(1_073_741_824);   // "1.0 GB"
Number::fileSize(1_099_511_627_776); // "1.0 TB"
Number::fileSize(1_572_864, 2);    // "1.50 MB"
```

## abbreviate()

```php
Number::abbreviate(float|int $value, int $decimals = 1): string
```

Shortens large numbers with K / M / B / T suffixes.

```php
Number::abbreviate(999);              // "999"
Number::abbreviate(1_000);            // "1.0K"
Number::abbreviate(1_500);            // "1.5K"
Number::abbreviate(2_300_000);        // "2.3M"
Number::abbreviate(4_100_000_000);    // "4.1B"
Number::abbreviate(1_200_000_000_000);// "1.2T"
Number::abbreviate(2_300_000, 2);     // "2.30M"
```

## ordinal()

```php
Number::ordinal(int $value): string
```

Appends the correct English ordinal suffix.

```php
Number::ordinal(1);    // "1st"
Number::ordinal(2);    // "2nd"
Number::ordinal(3);    // "3rd"
Number::ordinal(4);    // "4th"
Number::ordinal(11);   // "11th"   (special case)
Number::ordinal(12);   // "12th"
Number::ordinal(21);   // "21st"
Number::ordinal(101);  // "101st"
```

## Practical recipes

### API price response

```php
$app->get('/products/{id}', function (Request $req) use ($db) {
    $product = $db->table('products')->where('id', $req->param('id'))->first();

    return Response::json([
        'name'          => $product['name'],
        'price'         => Number::money($product['price_cents'] / 100, 'USD'),
        'price_raw'     => $product['price_cents'],
        'rating'        => Number::percent($product['rating'] * 10, 0),
    ]);
});
```

### Storage usage display

```php
$used  = $user['storage_bytes'];
$limit = 5 * 1024 ** 3;  // 5 GB

echo sprintf(
    'Using %s of %s (%s)',
    Number::fileSize($used),
    Number::fileSize($limit),
    Number::percent($used / $limit * 100, 1),
);
// "Using 1.2 GB of 5.0 GB (24.0%)"
```

### Leaderboard position

```php
foreach ($leaderboard as $i => $entry) {
    echo Number::ordinal($i + 1) . '. ' . $entry['name'];
}
// "1st. Alice", "2nd. Bob", "3rd. Carol", …
```

## Cheat sheet

```php
use Lift\Support\Number;

Number::money(1234.5, 'USD')                         // "$1,234.50"
Number::money(1234.5, 'EUR', 'de_DE')                // "1.234,50 €"
Number::format(1234567.89, 2)                        // "1,234,567.89"
Number::percent(85.6)                                // "85.6%"
Number::fileSize(1_572_864)                          // "1.5 MB"
Number::abbreviate(2_300_000)                        // "2.3M"
Number::ordinal(21)                                  // "21st"
```

[Date →](date)
