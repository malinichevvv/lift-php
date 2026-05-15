<?php

declare(strict_types=1);

namespace Lift\Support;

/**
 * Timezone-aware date/time utilities.
 *
 * All methods are pure functions: they never mutate their input and always
 * return a new `DateTimeImmutable`. The class carries no state — use it as a
 * collection of static helpers.
 *
 * ```php
 * use Lift\Support\Date;
 *
 * $now     = Date::now('Europe/Kyiv');
 * $in3days = Date::add($now, '3 days');
 * echo Date::diffForHumans($in3days);        // "in 3 days"
 * echo Date::format($now, 'Y-m-d', 'UTC');
 * ```
 */
final class Date
{
    // -----------------------------------------------------------------
    // Construction
    // -----------------------------------------------------------------

    /** Return the current moment in the given timezone (default: PHP default). */
    public static function now(?string $timezone = null): \DateTimeImmutable
    {
        $tz = $timezone !== null ? new \DateTimeZone($timezone) : null;
        return new \DateTimeImmutable('now', $tz);
    }

    /**
     * Parse a date string or wrap an existing `DateTimeInterface`.
     *
     * When `$timezone` is provided the result is converted to that zone.
     *
     * ```php
     * Date::parse('2026-05-15 10:00:00', 'America/New_York');
     * Date::parse('next monday');
     * Date::parse($existingDateTime, 'UTC');
     * ```
     */
    public static function parse(string|\DateTimeInterface $value, ?string $timezone = null): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            $dt = $value;
        } elseif ($value instanceof \DateTimeInterface) {
            $dt = \DateTimeImmutable::createFromInterface($value);
        } else {
            $dt = new \DateTimeImmutable($value);
        }

        if ($timezone !== null) {
            $dt = $dt->setTimezone(new \DateTimeZone($timezone));
        }

        return $dt;
    }

    // -----------------------------------------------------------------
    // Conversion
    // -----------------------------------------------------------------

    /** Convert a date to a different timezone without changing the instant. */
    public static function inTimezone(\DateTimeInterface $date, string $timezone): \DateTimeImmutable
    {
        return self::immutable($date)->setTimezone(new \DateTimeZone($timezone));
    }

    /** Format a date, optionally converting to a timezone first. */
    public static function format(\DateTimeInterface $date, string $format, ?string $timezone = null): string
    {
        $dt = $timezone !== null ? self::inTimezone($date, $timezone) : self::immutable($date);
        return $dt->format($format);
    }

    // -----------------------------------------------------------------
    // Arithmetic
    // -----------------------------------------------------------------

    /**
     * Add an interval to a date.
     *
     * Accepts an ISO 8601 interval string, a human-readable string, or a
     * `DateInterval` instance.
     *
     * ```php
     * Date::add($date, '3 days');
     * Date::add($date, '2 months');
     * Date::add($date, '90 minutes');
     * Date::add($date, 'P1Y2M');        // ISO 8601
     * Date::add($date, new \DateInterval('P7D'));
     * ```
     */
    public static function add(\DateTimeInterface $date, string|\DateInterval $interval): \DateTimeImmutable
    {
        return self::immutable($date)->add(self::interval($interval));
    }

    /** Subtract an interval from a date. */
    public static function sub(\DateTimeInterface $date, string|\DateInterval $interval): \DateTimeImmutable
    {
        return self::immutable($date)->sub(self::interval($interval));
    }

    // -----------------------------------------------------------------
    // Boundary helpers
    // -----------------------------------------------------------------

    /**
     * Return the start of a calendar unit (time is zeroed out).
     *
     * Supported units: `second`, `minute`, `hour`, `day`, `week` (Monday),
     * `month`, `year`.
     *
     * ```php
     * Date::startOf($date, 'month');  // 2026-05-01 00:00:00
     * Date::startOf($date, 'week');   // previous or current Monday 00:00:00
     * ```
     */
    public static function startOf(\DateTimeInterface $date, string $unit): \DateTimeImmutable
    {
        $dt = self::immutable($date);

        return match (strtolower($unit)) {
            'second' => $dt,
            'minute' => $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), 0),
            'hour'   => $dt->setTime((int) $dt->format('H'), 0, 0),
            'day'    => $dt->setTime(0, 0, 0),
            'week'   => $dt->modify('monday this week')->setTime(0, 0, 0),
            'month'  => $dt->modify('first day of this month')->setTime(0, 0, 0),
            'year'   => $dt->modify('first day of January this year')->setTime(0, 0, 0),
            default  => throw new \InvalidArgumentException("Unknown date unit: [{$unit}]"),
        };
    }

    /**
     * Return the end of a calendar unit (time is set to the last moment).
     *
     * ```php
     * Date::endOf($date, 'month');   // 2026-05-31 23:59:59
     * Date::endOf($date, 'year');    // 2026-12-31 23:59:59
     * ```
     */
    public static function endOf(\DateTimeInterface $date, string $unit): \DateTimeImmutable
    {
        $dt = self::immutable($date);

        return match (strtolower($unit)) {
            'second' => $dt,
            'minute' => $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), 59),
            'hour'   => $dt->setTime((int) $dt->format('H'), 59, 59),
            'day'    => $dt->setTime(23, 59, 59),
            'week'   => $dt->modify('sunday this week')->setTime(23, 59, 59),
            'month'  => $dt->modify('last day of this month')->setTime(23, 59, 59),
            'year'   => $dt->modify('last day of December this year')->setTime(23, 59, 59),
            default  => throw new \InvalidArgumentException("Unknown date unit: [{$unit}]"),
        };
    }

    // -----------------------------------------------------------------
    // Human-readable diff
    // -----------------------------------------------------------------

    /**
     * Return a human-readable difference relative to now (or a custom base).
     *
     * ```php
     * Date::diffForHumans(Date::sub(Date::now(), '2 hours'));  // "2 hours ago"
     * Date::diffForHumans(Date::add(Date::now(), '3 days'));   // "in 3 days"
     * Date::diffForHumans($date);                              // "just now"
     * ```
     */
    public static function diffForHumans(
        \DateTimeInterface $date,
        ?\DateTimeInterface $now = null,
    ): string {
        $now  ??= new \DateTimeImmutable('now', $date->getTimezone());
        $diff   = (int) $date->getTimestamp() - (int) $now->getTimestamp();
        $abs    = abs($diff);
        $past   = $diff < 0;

        [$value, $unit] = match (true) {
            $abs < 30              => [null, 'just now'],
            $abs < 90              => [1,    'minute'],
            $abs < 3_600           => [(int) round($abs / 60),   'minute'],
            $abs < 5_400           => [1,    'hour'],
            $abs < 86_400          => [(int) round($abs / 3_600), 'hour'],
            $abs < 129_600         => [1,    'day'],
            $abs < 604_800         => [(int) round($abs / 86_400), 'day'],
            $abs < 907_200         => [1,    'week'],
            $abs < 2_592_000       => [(int) round($abs / 604_800), 'week'],
            $abs < 3_888_000       => [1,    'month'],
            $abs < 31_536_000      => [(int) round($abs / 2_592_000), 'month'],
            $abs < 47_304_000      => [1,    'year'],
            default                => [(int) round($abs / 31_536_000), 'year'],
        };

        if ($value === null) {
            return 'just now';
        }

        $label = $value === 1 ? $unit : "{$unit}s";
        return $past ? "{$value} {$label} ago" : "in {$value} {$label}";
    }

    // -----------------------------------------------------------------
    // Predicates
    // -----------------------------------------------------------------

    /** True when the date falls on the same calendar day as today. */
    public static function isToday(\DateTimeInterface $date): bool
    {
        return $date->format('Y-m-d') === (new \DateTimeImmutable('now', $date->getTimezone()))->format('Y-m-d');
    }

    /** True when the date is strictly in the past. */
    public static function isPast(\DateTimeInterface $date): bool
    {
        return $date->getTimestamp() < time();
    }

    /** True when the date is strictly in the future. */
    public static function isFuture(\DateTimeInterface $date): bool
    {
        return $date->getTimestamp() > time();
    }

    /** True when both dates fall on the same calendar day (timezone of `$a` is used). */
    public static function isSameDay(\DateTimeInterface $a, \DateTimeInterface $b): bool
    {
        $bInA = \DateTimeImmutable::createFromInterface($b)->setTimezone($a->getTimezone());
        return $a->format('Y-m-d') === $bInA->format('Y-m-d');
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    private static function immutable(\DateTimeInterface $date): \DateTimeImmutable
    {
        return $date instanceof \DateTimeImmutable
            ? $date
            : \DateTimeImmutable::createFromInterface($date);
    }

    private static function interval(string|\DateInterval $interval): \DateInterval
    {
        if ($interval instanceof \DateInterval) {
            return $interval;
        }

        // Try ISO 8601 first (P1Y, PT30M, …), then human-readable ("3 days")
        if (str_starts_with(strtoupper($interval), 'P')) {
            return new \DateInterval($interval);
        }

        $parsed = \DateInterval::createFromDateString($interval);
        if ($parsed === false) {
            throw new \InvalidArgumentException("Cannot parse interval: [{$interval}]");
        }

        return $parsed;
    }
}
