<?php

declare(strict_types=1);

namespace Lift\Validation;

use Lift\Translation\Translator;

/**
 * Filtered request/input data that can be validated after lightweight casting.
 *
 * Filters are deliberately small transformations, not an output-escaping or SQL
 * safety mechanism. Continue to escape HTML at render time and bind SQL values
 * through the query builder/connection.
 *
 * ```php
 * $data = $request->filter([
 *     'email' => 'trim|lowercase',
 *     'price' => 'numeric_string_to_float',
 * ])->validate([
 *     'email' => 'required|email',
 *     'price' => 'required|numeric|min:0.01',
 * ]);
 * ```
 */
final class FilteredInput
{
    /** @var array<string, callable(mixed, list<string>): mixed> */
    private static array $filters = [];

    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data) {}

    /** Register a process-wide custom input filter. */
    public static function extend(string $name, callable $filter): void
    {
        self::$filters[$name] = $filter;
    }

    /** Reset custom filters, primarily for tests. */
    public static function resetFilters(): void
    {
        self::$filters = [];
    }

    /** Return the transformed data. */
    public function all(): array
    {
        return $this->data;
    }

    /** Validate the transformed data with the normal Validator. */
    public function validate(array $rules, array $messages = [], ?Translator $translator = null): array
    {
        return (new Validator($this->data, $rules, $messages, $translator))->validated();
    }

    /** Build a filtered input object from raw data and filter rules. */
    public static function from(array $data, array $filters): self
    {
        foreach ($filters as $field => $filterSet) {
            $value = self::getValue($data, (string) $field);
            $value = self::applyFilterSet($value, $filterSet);
            self::setValue($data, (string) $field, $value);
        }
        return new self($data);
    }

    private static function applyFilterSet(mixed $value, string|array $filterSet): mixed
    {
        $filters = is_array($filterSet) ? $filterSet : array_values(array_filter(explode('|', $filterSet)));
        foreach ($filters as $filter) {
            if (is_callable($filter)) {
                $value = $filter($value);
                continue;
            }
            if (!is_string($filter)) {
                continue;
            }
            [$name, $params] = self::parseFilter($filter);
            $value = self::applyFilter($name, $value, $params);
        }
        return $value;
    }

    /** @return array{0:string,1:list<string>} */
    private static function parseFilter(string $filter): array
    {
        if (!str_contains($filter, ':')) {
            return [$filter, []];
        }
        [$name, $paramString] = explode(':', $filter, 2);
        return [$name, explode(',', $paramString)];
    }

    /** @param list<string> $params */
    private static function applyFilter(string $name, mixed $value, array $params): mixed
    {
        if (isset(self::$filters[$name])) {
            return (self::$filters[$name])($value, $params);
        }

        return match ($name) {
            'trim' => is_string($value) ? trim($value) : $value,
            'lowercase', 'lower' => is_string($value) ? mb_strtolower($value) : $value,
            'uppercase', 'upper' => is_string($value) ? mb_strtoupper($value) : $value,
            'strip_tags' => is_string($value) ? strip_tags($value, $params[0] ?? '') : $value,
            'htmlencode' => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'sanitize_email' => is_string($value) ? filter_var($value, FILTER_SANITIZE_EMAIL) : $value,
            'sanitize_numbers' => is_string($value) ? preg_replace('/[^0-9+-]/', '', $value) : $value,
            'sanitize_floats' => is_string($value) ? preg_replace('/[^0-9+-.]/', '', $value) : $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            'integer', 'int' => filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : $value,
            'float', 'numeric_string_to_float' => is_numeric($value) ? (float) $value : $value,
            'slug' => self::slug((string) $value),
            default => $value,
        };
    }

    private static function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', '-', $value) ?? $value;
        return trim($value, '-');
    }

    private static function getValue(array $data, string $field): mixed
    {
        if (array_key_exists($field, $data)) {
            return $data[$field];
        }
        $cursor = $data;
        foreach (explode('.', $field) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return null;
            }
            $cursor = $cursor[$part];
        }
        return $cursor;
    }

    private static function setValue(array &$data, string $field, mixed $value): void
    {
        if (array_key_exists($field, $data)) {
            $data[$field] = $value;
            return;
        }
        $cursor =& $data;
        foreach (explode('.', $field) as $part) {
            if (!is_array($cursor)) {
                $cursor = [];
            }
            if (!array_key_exists($part, $cursor)) {
                $cursor[$part] = [];
            }
            $cursor =& $cursor[$part];
        }
        $cursor = $value;
    }
}
