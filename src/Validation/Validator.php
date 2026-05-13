<?php

declare(strict_types=1);

namespace Lift\Validation;

/**
 * Rule-based input validator.
 *
 * Rules are defined as pipe-delimited strings — identical to Laravel's syntax
 * so the learning curve is near-zero for PHP developers.
 *
 * ```php
 * $validator = new Validator($_POST, [
 *     'name'     => 'required|string|max:255',
 *     'email'    => 'required|email',
 *     'age'      => 'required|integer|min:18|max:120',
 *     'password' => 'required|min_length:8|confirmed',
 *     'role'     => 'required|in:admin,user,moderator',
 *     'website'  => 'nullable|url',
 *     'tags'     => 'array',
 *     'tags.*'   => 'string',
 * ]);
 *
 * if ($validator->fails()) {
 *     return Response::json(['errors' => $validator->errors()], 422);
 * }
 * $data = $validator->validated();
 * ```
 *
 * Rules supported:
 * `required`, `nullable`, `string`, `integer`/`int`, `float`/`numeric`, `boolean`/`bool`,
 * `array`, `email`, `url`, `alpha`, `alpha_num`, `digits`, `date`,
 * `min`, `max`, `min_length`, `max_length`, `size`, `between`,
 * `in`, `not_in`, `regex`, `confirmed`, `same`, `different`
 */
final class Validator
{
    /** @var array<string, string[]> */
    private array $errors = [];
    /** @var array<string, mixed> */
    private array $validatedData = [];
    private bool $ran = false;

    /**
     * @param array<string, mixed>  $data  Input data (from request, $_POST, etc.)
     * @param array<string, string|string[]> $rules  Field → rule string or array.
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
    ) {}

    public function passes(): bool
    {
        $this->runIfNeeded();
        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * @return array<string, string[]>
     */
    public function errors(): array
    {
        $this->runIfNeeded();
        return $this->errors;
    }

    /**
     * Return validated data.
     *
     * @throws ValidationException If validation failed.
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        if ($this->fails()) {
            throw ValidationException::withErrors($this->errors);
        }
        return $this->validatedData;
    }

    // -----------------------------------------------------------------
    // Internal execution
    // -----------------------------------------------------------------

    private function runIfNeeded(): void
    {
        if ($this->ran) {
            return;
        }
        $this->ran = true;
        $this->validate();
    }

    private function validate(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            // Support wildcard: "tags.*"
            if (str_ends_with($field, '.*')) {
                $parent = substr($field, 0, -2);
                $values = $this->getValue($parent);
                if (!is_array($values)) {
                    continue;
                }
                foreach ($values as $i => $item) {
                    $this->validateField("{$parent}.{$i}", $item, $ruleString);
                }
                continue;
            }

            $value = $this->getValue($field);
            $this->validateField($field, $value, $ruleString);
        }
    }

    private function validateField(string $field, mixed $value, string|array $ruleString): void
    {
        $rules = is_array($ruleString)
            ? $ruleString
            : array_filter(explode('|', $ruleString));

        $nullable  = in_array('nullable', $rules, true);
        $required  = in_array('required', $rules, true);
        $missing   = $value === null || $value === '';

        if ($missing) {
            if ($required) {
                $this->addError($field, "The {$field} field is required.");
            }
            if ($nullable || !$required) {
                // Skip further checks for absent/nullable fields
                return;
            }
            return;
        }

        // Field is present — collect in validated data
        $this->validatedData[$field] = $value;

        foreach ($rules as $rule) {
            if ($rule === 'required' || $rule === 'nullable') {
                continue;
            }

            [$name, $params] = $this->parseRule($rule);
            $this->applyRule($field, $value, $name, $params);
        }
    }

    private function applyRule(string $field, mixed $value, string $rule, array $params): void
    {
        $label = ucfirst(str_replace(['_', '.'], [' ', ' '], $field));

        $ok = match ($rule) {
            'string'                => is_string($value),
            'integer', 'int'        => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'float', 'numeric'      => is_numeric($value),
            'boolean', 'bool'       => in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true),
            'array'                 => is_array($value),
            'email'                 => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url'                   => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'alpha'                 => is_string($value) && ctype_alpha($value),
            'alpha_num'             => is_string($value) && ctype_alnum($value),
            'digits'                => is_string($value) && ctype_digit($value),
            'date'                  => $this->isValidDate($value),
            'min'                   => $this->validateMin($value, (float) ($params[0] ?? 0)),
            'max'                   => $this->validateMax($value, (float) ($params[0] ?? 0)),
            'min_length'            => is_string($value) && strlen($value) >= (int) ($params[0] ?? 0),
            'max_length'            => is_string($value) && strlen($value) <= (int) ($params[0] ?? 0),
            'size'                  => $this->validateSize($value, (int) ($params[0] ?? 0)),
            'between'               => $this->validateBetween($value, (float) ($params[0] ?? 0), (float) ($params[1] ?? 0)),
            'in'                    => in_array((string) $value, $params, true),
            'not_in'                => !in_array((string) $value, $params, true),
            'regex'                 => is_string($value) && (bool) preg_match($params[0] ?? '//', $value),
            'confirmed'             => $this->validateConfirmed($field, $value),
            'same'                  => $this->getValue($params[0] ?? '') === $value,
            'different'             => $this->getValue($params[0] ?? '') !== $value,
            'date_format'           => $this->validateDateFormat($value, $params[0] ?? 'Y-m-d'),
            'starts_with'           => is_string($value) && str_starts_with($value, $params[0] ?? ''),
            'ends_with'             => is_string($value) && str_ends_with($value, $params[0] ?? ''),
            'ip'                    => filter_var($value, FILTER_VALIDATE_IP) !== false,
            'ipv4'                  => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'ipv6'                  => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'json'                  => is_string($value) && json_validate($value),
            default                 => true, // Unknown rules pass silently
        };

        if (!$ok) {
            $this->addError($field, $this->message($rule, $label, $params));
        }
    }

    private function validateMin(mixed $value, float $min): bool
    {
        if (is_array($value)) return count($value) >= $min;
        if (is_numeric($value)) return (float) $value >= $min;
        return is_string($value) && strlen($value) >= $min;
    }

    private function validateMax(mixed $value, float $max): bool
    {
        if (is_array($value)) return count($value) <= $max;
        if (is_numeric($value)) return (float) $value <= $max;
        return is_string($value) && strlen($value) <= $max;
    }

    private function validateSize(mixed $value, int $size): bool
    {
        if (is_array($value)) return count($value) === $size;
        if (is_numeric($value)) return (int) $value === $size;
        return is_string($value) && strlen($value) === $size;
    }

    private function validateBetween(mixed $value, float $min, float $max): bool
    {
        if (!is_numeric($value)) return false;
        $v = (float) $value;
        return $v >= $min && $v <= $max;
    }

    private function validateConfirmed(string $field, mixed $value): bool
    {
        return $this->getValue("{$field}_confirmation") === $value;
    }

    private function isValidDate(mixed $value): bool
    {
        if (!is_string($value) && !is_int($value)) return false;
        return strtotime((string) $value) !== false;
    }

    private function validateDateFormat(mixed $value, string $format): bool
    {
        if (!is_string($value)) return false;
        $d = \DateTime::createFromFormat($format, $value);
        return $d !== false && $d->format($format) === $value;
    }

    private function parseRule(string $rule): array
    {
        if (!str_contains($rule, ':')) {
            return [$rule, []];
        }
        [$name, $paramStr] = explode(':', $rule, 2);
        return [$name, explode(',', $paramStr)];
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /** Resolve a dot-notation field path from $data. */
    private function getValue(string $field): mixed
    {
        if (array_key_exists($field, $this->data)) {
            return $this->data[$field];
        }

        $parts  = explode('.', $field);
        $cursor = $this->data;
        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return null;
            }
            $cursor = $cursor[$part];
        }
        return $cursor;
    }

    private function message(string $rule, string $label, array $params): string
    {
        return match ($rule) {
            'string'            => "The {$label} must be a string.",
            'integer', 'int'    => "The {$label} must be an integer.",
            'float', 'numeric'  => "The {$label} must be a number.",
            'boolean', 'bool'   => "The {$label} must be true or false.",
            'array'             => "The {$label} must be an array.",
            'email'             => "The {$label} must be a valid email address.",
            'url'               => "The {$label} must be a valid URL.",
            'alpha'             => "The {$label} may only contain letters.",
            'alpha_num'         => "The {$label} may only contain letters and numbers.",
            'digits'            => "The {$label} must be numeric digits only.",
            'date'              => "The {$label} must be a valid date.",
            'date_format'       => "The {$label} must match the format {$params[0]}.",
            'min'               => "The {$label} must be at least {$params[0]}.",
            'max'               => "The {$label} must not exceed {$params[0]}.",
            'min_length'        => "The {$label} must be at least {$params[0]} characters.",
            'max_length'        => "The {$label} must not exceed {$params[0]} characters.",
            'size'              => "The {$label} must be exactly {$params[0]}.",
            'between'           => "The {$label} must be between {$params[0]} and {$params[1]}.",
            'in'                => "The {$label} must be one of: " . implode(', ', $params) . '.',
            'not_in'            => "The {$label} must not be one of: " . implode(', ', $params) . '.',
            'regex'             => "The {$label} format is invalid.",
            'confirmed'         => "The {$label} confirmation does not match.",
            'same'              => "The {$label} must match the {$params[0]} field.",
            'different'         => "The {$label} must be different from the {$params[0]} field.",
            'starts_with'       => "The {$label} must start with {$params[0]}.",
            'ends_with'         => "The {$label} must end with {$params[0]}.",
            'ip', 'ipv4','ipv6' => "The {$label} must be a valid IP address.",
            'json'              => "The {$label} must be valid JSON.",
            default             => "The {$label} is invalid.",
        };
    }
}
