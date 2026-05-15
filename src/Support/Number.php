<?php

declare(strict_types=1);

namespace Lift\Support;

/**
 * Number and money formatting utilities.
 *
 * When the `intl` PHP extension is available, `money()` and `format()` use
 * `NumberFormatter` for locale-aware output. Otherwise a built-in fallback
 * is used — correct for `en_US` style formatting and the most common currencies.
 *
 * ```php
 * use Lift\Support\Number;
 *
 * Number::money(1234.5, 'USD');          // "$1,234.50"
 * Number::money(9900,   'EUR', 'de_DE'); // "9.900,00 €"
 * Number::percent(0.856);               // "85.6%"
 * Number::fileSize(1_572_864);          // "1.5 MB"
 * Number::abbreviate(1_234_567);        // "1.2M"
 * ```
 */
final class Number
{
    // -----------------------------------------------------------------
    // General formatting
    // -----------------------------------------------------------------

    /**
     * Format a number with grouped thousands and a fixed decimal places.
     *
     * ```php
     * Number::format(1234567.891, 2);          // "1,234,567.89"
     * Number::format(1234567.891, 2, ',', '.'); // "1.234.567,89"  (European)
     * ```
     */
    public static function format(
        float|int $value,
        int $decimals = 2,
        string $decimal = '.',
        string $thousands = ',',
    ): string {
        return number_format((float) $value, $decimals, $decimal, $thousands);
    }

    // -----------------------------------------------------------------
    // Money
    // -----------------------------------------------------------------

    /**
     * Format a monetary amount with currency symbol and locale-aware grouping.
     *
     * Uses PHP's `intl` extension (`NumberFormatter`) when available.
     * Falls back to a built-in table for the most common currencies.
     *
     * ```php
     * Number::money(1234.5,  'USD');           // "$1,234.50"
     * Number::money(1234.5,  'EUR', 'de_DE');  // "1.234,50 €"
     * Number::money(1234.5,  'GBP', 'en_GB');  // "£1,234.50"
     * Number::money(150000,  'JPY', 'ja_JP');  // "¥150,000"
     * ```
     *
     * @param float|int $amount   The monetary amount.
     * @param string    $currency ISO 4217 currency code.
     * @param string    $locale   ICU locale string (used only when `intl` is available).
     */
    public static function money(
        float|int $amount,
        string $currency = 'USD',
        string $locale = 'en_US',
    ): string {
        if (class_exists(\NumberFormatter::class)) {
            $fmt    = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            $result = $fmt->formatCurrency((float) $amount, strtoupper($currency));
            if ($result !== false) {
                return $result;
            }
        }

        return self::moneyFallback((float) $amount, strtoupper($currency));
    }

    // -----------------------------------------------------------------
    // Percentages
    // -----------------------------------------------------------------

    /**
     * Format a value as a percentage string.
     *
     * The value is treated as a plain percentage (not a fraction), so pass
     * `85.6` — not `0.856`.
     *
     * ```php
     * Number::percent(85.6);    // "85.6%"
     * Number::percent(100, 0);  // "100%"
     * Number::percent(33.333, 2);  // "33.33%"
     * ```
     */
    public static function percent(float|int $value, int $decimals = 1): string
    {
        return number_format((float) $value, $decimals) . '%';
    }

    // -----------------------------------------------------------------
    // File sizes
    // -----------------------------------------------------------------

    /**
     * Format a byte count as a human-readable file size (binary prefixes).
     *
     * ```php
     * Number::fileSize(0);             // "0 B"
     * Number::fileSize(1024);          // "1.0 KB"
     * Number::fileSize(1_572_864);     // "1.5 MB"
     * Number::fileSize(1_073_741_824); // "1.0 GB"
     * ```
     */
    public static function fileSize(int $bytes, int $decimals = 1): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $exp   = (int) min(floor(log($bytes, 1024)), count($units) - 1);

        if ($exp === 0) {
            return "{$bytes} B";
        }

        $value = $bytes / (1024 ** $exp);
        return number_format($value, $decimals) . ' ' . $units[$exp];
    }

    // -----------------------------------------------------------------
    // Abbreviation
    // -----------------------------------------------------------------

    /**
     * Abbreviate a large number with a suffix (K, M, B, T).
     *
     * ```php
     * Number::abbreviate(999);           // "999"
     * Number::abbreviate(1_500);         // "1.5K"
     * Number::abbreviate(2_300_000);     // "2.3M"
     * Number::abbreviate(4_100_000_000); // "4.1B"
     * ```
     */
    public static function abbreviate(float|int $value, int $decimals = 1): string
    {
        $abs = abs((float) $value);

        [$divisor, $suffix] = match (true) {
            $abs >= 1_000_000_000_000 => [1_000_000_000_000, 'T'],
            $abs >= 1_000_000_000     => [1_000_000_000,     'B'],
            $abs >= 1_000_000         => [1_000_000,         'M'],
            $abs >= 1_000             => [1_000,             'K'],
            default                   => [1,                 ''],
        };

        if ($divisor === 1) {
            return (string) ($value == (int) $value ? (int) $value : round((float) $value, $decimals));
        }

        return number_format((float) $value / $divisor, $decimals) . $suffix;
    }

    // -----------------------------------------------------------------
    // Ordinals
    // -----------------------------------------------------------------

    /**
     * Add an English ordinal suffix to an integer.
     *
     * ```php
     * Number::ordinal(1);   // "1st"
     * Number::ordinal(2);   // "2nd"
     * Number::ordinal(3);   // "3rd"
     * Number::ordinal(11);  // "11th"
     * Number::ordinal(21);  // "21st"
     * ```
     */
    public static function ordinal(int $value): string
    {
        $abs = abs($value);
        if (in_array($abs % 100, [11, 12, 13], true)) {
            return "{$value}th";
        }
        $suffix = match ($abs % 10) {
            1       => 'st',
            2       => 'nd',
            3       => 'rd',
            default => 'th',
        };
        return "{$value}{$suffix}";
    }

    // -----------------------------------------------------------------
    // Internal fallback
    // -----------------------------------------------------------------

    private static function moneyFallback(float $amount, string $currency): string
    {
        // Currencies with zero decimal places
        static $zeroDp = ['JPY', 'KRW', 'VND', 'CLP', 'HUF', 'ISK', 'TWD', 'UGX'];

        $decimals = in_array($currency, $zeroDp, true) ? 0 : 2;
        $formatted = number_format($amount, $decimals);

        static $symbols = [
            'USD' => '$',     'CAD' => 'CA$',  'AUD' => 'A$',   'NZD' => 'NZ$',
            'SGD' => 'S$',    'HKD' => 'HK$',  'MXN' => 'MX$',  'EUR' => '€',
            'GBP' => '£',     'JPY' => '¥',    'CNY' => '¥',    'KRW' => '₩',
            'INR' => '₹',     'RUB' => '₽',    'TRY' => '₺',    'BRL' => 'R$',
            'PLN' => 'zł',    'SEK' => 'kr',   'NOK' => 'kr',   'DKK' => 'kr',
            'CHF' => 'CHF ',  'UAH' => '₴',
        ];

        $symbol = $symbols[$currency] ?? ($currency . ' ');
        return $symbol . $formatted;
    }
}
