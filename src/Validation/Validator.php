<?php

declare(strict_types=1);

namespace Lift\Validation;

use Closure;
use Lift\Translation\Translator;

/**
 * Rule-based input validator.
 *
 * Rules may be written as pipe-delimited strings (Laravel-style) or as arrays
 * that mix strings, {@see RuleInterface} objects, and closures.
 *
 * ### Basic usage
 *
 * ```php
 * $v = new Validator($_POST, [
 *     'name'     => 'required|string|max:255',
 *     'email'    => 'required|email',
 *     'age'      => 'required|integer|min:18|max:120',
 *     'password' => 'required|min_length:8|confirmed',
 *     'role'     => 'sometimes|required|in:admin,user',
 *     'website'  => 'nullable|url',
 *     'tags'     => 'array|list|distinct|min_items:1',
 *     'tags.*'   => 'string|max:50',
 *     'status'   => 'required_if:publish,1',
 *     'token'    => 'prohibited_if:role,guest',
 * ]);
 *
 * if ($v->fails()) {
 *     return Response::json(['errors' => $v->errors()], 422);
 * }
 *
 * $data = $v->validated(); // only fields that have rules
 * ```
 *
 * ### Inline rule objects and closures
 *
 * Pass an array to mix strings with {@see RuleInterface} instances or closures.
 * Closures receive a `$fail` callback — call it with an error message to fail.
 *
 * ```php
 * $v = new Validator($data, [
 *     'phone' => [
 *         'required',
 *         new PhoneRule(),                           // implements RuleInterface
 *         function ($field, $value, $all, $fail) {   // inline closure
 *             if (!str_starts_with($value, '+')) {
 *                 $fail('Phone must start with +.');
 *             }
 *         },
 *     ],
 * ]);
 * ```
 *
 * ### Global custom rule registration
 *
 * Register a named rule once (e.g. in a service provider) and use it by name
 * everywhere. The closure must return `bool`; the optional third argument is the
 * error template (`:attribute` is replaced with the field label).
 *
 * ```php
 * Validator::extend(
 *     'isbn13',
 *     fn ($field, $value, $data) => strlen($value) === 13 && str_starts_with($value, '978'),
 *     'The :attribute must be a valid ISBN-13.',
 * );
 *
 * // Now usable in any rule string:
 * $v = new Validator($data, ['book_id' => 'required|isbn13']);
 * ```
 *
 * You can also pass a {@see RuleInterface} instance — its `message()` is used
 * when no third argument is provided:
 *
 * ```php
 * Validator::extend('luhn', new LuhnRule());
 * ```
 *
 * ### Custom error messages
 *
 * Pass an array of messages as the third constructor argument.  Keys follow the
 * pattern `"field.rule"` (most specific) or just `"rule"` (fallback for all
 * fields). `:attribute`, `:min`, `:max`, `:value`, `:other`, `:when`, `:values`
 * placeholders are replaced automatically.
 *
 * ```php
 * $v = new Validator($data, $rules, [
 *     'email.required' => 'Please enter your email address.',
 *     'required'       => 'This field cannot be blank.',
 *     'age.min'        => ':attribute must be at least :min years old.',
 * ]);
 * ```
 *
 * ### Localization / pluralization
 *
 * Set a global translator once (e.g. in bootstrap) or pass one per instance.
 * Bundled locales: `en`, `ru`. Add more via {@see Translator::addPath()}.
 *
 * ```php
 * // Global default (affects all instances that don't supply their own)
 * Validator::setTranslator(new Translator('ru'));
 *
 * // Per-instance (takes priority over the global default)
 * $v = new Validator($data, $rules, [], new Translator('de'));
 * ```
 *
 * ---
 *
 * **Presence & conditional rules**
 * - `required`              — field must be present and non-empty.
 * - `required_if:f,v`       — required when field *f* equals *v*.
 * - `required_unless:f,v`   — required unless field *f* equals *v*.
 * - `required_with:f1,f2`   — required if any listed field is non-empty.
 * - `required_without:f1,f2`— required if any listed field is absent/empty.
 * - `nullable`              — skip remaining rules when absent/empty.
 * - `sometimes`             — skip all rules when the key is not in the input at all.
 * - `present`               — key must exist (value may be null/empty).
 * - `filled`                — if present, must not be empty.
 * - `prohibited`            — must be absent / empty.
 * - `prohibited_if:f,v`     — prohibited when field *f* equals *v*.
 * - `prohibited_unless:f,v` — prohibited unless field *f* equals *v*.
 *
 * **Type rules**
 * `string`, `integer`/`int`, `float`/`numeric`, `boolean`/`bool`, `array`
 *
 * **Format rules**
 * `email`, `url`, `ip`, `ipv4`, `ipv6`, `alpha`, `alpha_num`, `digits`,
 * `digits_between:min,max`, `date`, `date_format:fmt`, `json`,
 * `regex:/pat/`, `not_regex:/pat/`, `uuid`, `mac_address`,
 * `lowercase`, `uppercase`
 *
 * **Value rules**
 * `min:n`, `max:n`, `between:min,max`, `size:n`,
 * `min_length:n`, `max_length:n`,
 * `in:a,b,c`, `not_in:a,b,c`,
 * `accepted`, `declined`, `multiple_of:n`,
 * `confirmed`, `same:other`, `different:other`,
 * `starts_with:pfx`, `ends_with:sfx`
 *
 * **Array rules**
 * `list`, `distinct`, `min_items:n`, `max_items:n`
 */
final class Validator
{
    /** @var array<string, string[]> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validatedData = [];

    private bool $ran = false;

    // -----------------------------------------------------------------
    // Static registry
    // -----------------------------------------------------------------

    /** @var array<string, array{handler: RuleInterface|Closure, message: string|null}> */
    private static array $extensions = [];

    private static ?Translator $defaultTranslator = null;

    // Meta-rule names that are handled in validateField, not in applyBuiltinOrExtension
    private const META_RULES = [
        'required', 'nullable', 'sometimes', 'present', 'filled',
        'required_if', 'required_unless', 'required_with', 'required_without',
        'prohibited', 'prohibited_if', 'prohibited_unless',
    ];

    // -----------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed>                                       $data
     * @param array<string, string|array<string|RuleInterface|Closure>>  $rules
     * @param array<string, string>                                       $messages
     *   Custom messages keyed by `"field.rule"` or `"rule"`.
     * @param Translator|null $translator
     *   Per-instance translator; overrides the global default.
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages = [],
        private readonly ?Translator $translator = null,
    ) {}

    // -----------------------------------------------------------------
    // Static configuration
    // -----------------------------------------------------------------

    /**
     * Register a named custom rule for all Validator instances.
     *
     * Closure signature: `function(string $field, mixed $value, array $data): bool`
     */
    public static function extend(string $name, RuleInterface|Closure $handler, ?string $message = null): void
    {
        self::$extensions[$name] = ['handler' => $handler, 'message' => $message];
    }

    public static function setTranslator(Translator $translator): void
    {
        self::$defaultTranslator = $translator;
    }

    public static function resetExtensions(): void
    {
        self::$extensions = [];
    }

    // -----------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------

    public function passes(): bool
    {
        $this->runIfNeeded();
        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string, string[]> */
    public function errors(): array
    {
        $this->runIfNeeded();
        return $this->errors;
    }

    /**
     * @throws ValidationException
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
    // Validation engine
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
        foreach ($this->rules as $field => $ruleSet) {
            if (str_ends_with($field, '.*')) {
                $parent = substr($field, 0, -2);
                $values = $this->getValue($parent);
                if (!is_array($values)) {
                    continue;
                }
                foreach ($values as $i => $item) {
                    $this->validateField("{$parent}.{$i}", $item, $ruleSet);
                }
                continue;
            }

            $this->validateField($field, $this->getValue($field), $ruleSet);
        }
    }

    private function validateField(string $field, mixed $value, string|array $ruleSet): void
    {
        $rules = is_array($ruleSet)
            ? $ruleSet
            : array_values(array_filter(explode('|', $ruleSet)));

        // ── Step 1: 'sometimes' — skip entirely when key was never submitted ──
        if ($this->hasRule($rules, 'sometimes') && !$this->hasKey($field)) {
            return;
        }

        $missing   = $value === null || $value === '';
        $keyExists = $this->hasKey($field);

        // ── Step 2: handle absence ──────────────────────────────────────────
        if ($missing) {
            if ($this->hasRule($rules, 'present') && !$keyExists) {
                $this->addError($field, $this->resolveMessage($field, 'present', []));
            }
            if ($this->hasRule($rules, 'filled') && $keyExists) {
                $this->addError($field, $this->resolveMessage($field, 'filled', []));
            }

            $reqRule = $this->effectiveRequiredRule($field, $rules);
            if ($reqRule !== null) {
                [$rName, $rParams] = $this->parseRule($reqRule);
                $this->addError($field, $this->resolveMessage($field, $rName, $rParams));
            }
            return;
        }

        // ── Step 3: check prohibitions ──────────────────────────────────────
        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                continue;
            }
            [$rName, $rParams] = $this->parseRule($rule);
            if ($rName === 'prohibited') {
                $this->addError($field, $this->resolveMessage($field, 'prohibited', []));
                return;
            }
            if ($rName === 'prohibited_if'
                && (string) $this->getValue($rParams[0] ?? '') === ($rParams[1] ?? '')) {
                $this->addError($field, $this->resolveMessage($field, 'prohibited_if', $rParams));
                return;
            }
            if ($rName === 'prohibited_unless'
                && (string) $this->getValue($rParams[0] ?? '') !== ($rParams[1] ?? '')) {
                $this->addError($field, $this->resolveMessage($field, 'prohibited_unless', $rParams));
                return;
            }
        }

        // ── Step 4: validate value ──────────────────────────────────────────
        $this->validatedData[$field] = $value;

        foreach ($rules as $rule) {
            if ($rule instanceof RuleInterface) {
                if (!$rule->passes($field, $value, $this->data)) {
                    $this->addError($field, $this->resolveInlineClassMessage($field, $rule));
                }
                continue;
            }

            if ($rule instanceof Closure) {
                $failed  = false;
                $failMsg = '';
                $rule($field, $value, $this->data, static function (string $msg) use (&$failed, &$failMsg): void {
                    $failed  = true;
                    $failMsg = $msg;
                });
                if ($failed) {
                    $this->addError($field, $failMsg);
                }
                continue;
            }

            [$rName, $rParams] = $this->parseRule($rule);
            if (in_array($rName, self::META_RULES, true)) {
                continue;
            }
            $this->applyBuiltinOrExtension($field, $value, $rName, $rParams);
        }
    }

    private function applyBuiltinOrExtension(string $field, mixed $value, string $rule, array $params): void
    {
        if (isset(self::$extensions[$rule])) {
            $ext     = self::$extensions[$rule];
            $handler = $ext['handler'];
            $passes  = $handler instanceof RuleInterface
                ? $handler->passes($field, $value, $this->data)
                : $handler($field, $value, $this->data);
            if (!$passes) {
                $extMsg = $ext['message']
                    ?? ($handler instanceof RuleInterface ? $handler->message() : null);
                $this->addError($field, $this->resolveMessage($field, $rule, $params, $extMsg));
            }
            return;
        }

        $ok = match ($rule) {
            // ── Type ───────────────────────────────────────────────────────
            'string'              => is_string($value),
            'integer', 'int'      => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'float', 'numeric'    => is_numeric($value),
            'boolean', 'bool'     => in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false'], true),
            'array'               => is_array($value),
            // ── Format ─────────────────────────────────────────────────────
            'email'               => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url'                 => filter_var($value, FILTER_VALIDATE_URL) !== false,
            'ip'                  => filter_var($value, FILTER_VALIDATE_IP) !== false,
            'ipv4'                => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
            'ipv6'                => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
            'alpha'               => is_string($value) && ctype_alpha($value),
            'alpha_num'           => is_string($value) && ctype_alnum($value),
            'digits'              => is_string($value) && ctype_digit($value),
            'digits_between'      => $this->validateDigitsBetween($value, (int) ($params[0] ?? 0), (int) ($params[1] ?? PHP_INT_MAX)),
            'date'                => $this->isValidDate($value),
            'date_format'         => $this->validateDateFormat($value, $params[0] ?? 'Y-m-d'),
            'json'                => is_string($value) && json_validate($value),
            'uuid'                => is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1,
            'mac_address'         => is_string($value) && preg_match('/^([0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}$/', $value) === 1,
            'regex'               => is_string($value) && preg_match($params[0] ?? '//', $value) === 1,
            'not_regex'           => is_string($value) && preg_match($params[0] ?? '//', $value) === 0,
            'lowercase'           => is_string($value) && $value === mb_strtolower($value),
            'uppercase'           => is_string($value) && $value === mb_strtoupper($value),
            // ── Value ──────────────────────────────────────────────────────
            'min'                 => $this->validateMin($value, (float) ($params[0] ?? 0)),
            'max'                 => $this->validateMax($value, (float) ($params[0] ?? 0)),
            'min_length'          => is_string($value) && strlen($value) >= (int) ($params[0] ?? 0),
            'max_length'          => is_string($value) && strlen($value) <= (int) ($params[0] ?? 0),
            'size'                => $this->validateSize($value, (int) ($params[0] ?? 0)),
            'between'             => $this->validateBetween($value, (float) ($params[0] ?? 0), (float) ($params[1] ?? 0)),
            'multiple_of'         => $this->validateMultipleOf($value, (float) ($params[0] ?? 1)),
            'in'                  => in_array((string) $value, $params, true),
            'not_in'              => !in_array((string) $value, $params, true),
            'accepted'            => in_array($value, ['yes', 'on', '1', 1, true, 'true'], true),
            'declined'            => in_array($value, ['no', 'off', '0', 0, false, 'false'], true),
            'confirmed'           => $this->validateConfirmed($field, $value),
            'same'                => $this->getValue($params[0] ?? '') === $value,
            'different'           => $this->getValue($params[0] ?? '') !== $value,
            'starts_with'         => is_string($value) && str_starts_with($value, $params[0] ?? ''),
            'ends_with'           => is_string($value) && str_ends_with($value, $params[0] ?? ''),
            // ── Array ──────────────────────────────────────────────────────
            'list'                => is_array($value) && array_keys($value) === range(0, count($value) - 1),
            'distinct'            => is_array($value) && count($value) === count(array_unique(array_map('serialize', $value))),
            'min_items'           => is_array($value) && count($value) >= (int) ($params[0] ?? 0),
            'max_items'           => is_array($value) && count($value) <= (int) ($params[0] ?? 0),
            default               => true,
        };

        if (!$ok) {
            $this->addError($field, $this->resolveMessage($field, $rule, $params));
        }
    }

    // -----------------------------------------------------------------
    // Conditional required evaluation
    // -----------------------------------------------------------------

    /**
     * Returns the first rule string that makes the field effectively required,
     * or null if the field is not required.
     */
    private function effectiveRequiredRule(string $field, array $rules): ?string
    {
        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                continue;
            }
            [$name, $params] = $this->parseRule($rule);

            if ($name === 'required') {
                return 'required';
            }

            if ($name === 'required_if' && isset($params[0], $params[1])) {
                if ((string) $this->getValue($params[0]) === $params[1]) {
                    return $rule;
                }
            }

            if ($name === 'required_unless' && isset($params[0], $params[1])) {
                if ((string) $this->getValue($params[0]) !== $params[1]) {
                    return $rule;
                }
            }

            if ($name === 'required_with' && !empty($params)) {
                foreach ($params as $f) {
                    $v = $this->getValue(trim($f));
                    if ($v !== null && $v !== '') {
                        return $rule;
                    }
                }
            }

            if ($name === 'required_without' && !empty($params)) {
                foreach ($params as $f) {
                    $v = $this->getValue(trim($f));
                    if ($v === null || $v === '') {
                        return $rule;
                    }
                }
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Message resolution
    //
    //  1. Per-field-per-rule custom message  →  $messages['field.rule']
    //  2. Per-rule custom message            →  $messages['rule']
    //  3. Extension / class template         →  $overrideTemplate
    //  4. Translator                         →  Translator::get() / choice()
    //  5. Built-in English fallback
    // -----------------------------------------------------------------

    private function resolveMessage(
        string  $field,
        string  $rule,
        array   $params,
        ?string $overrideTemplate = null,
    ): string {
        $label = $this->fieldLabel($field);

        $custom = $this->messages["{$field}.{$rule}"] ?? $this->messages[$rule] ?? null;
        if ($custom !== null) {
            return $this->fillPlaceholders($custom, $label, $params);
        }

        if ($overrideTemplate !== null) {
            return $this->fillPlaceholders($overrideTemplate, $label, $params);
        }

        $translator = $this->translator ?? self::$defaultTranslator;
        if ($translator !== null) {
            $replace = $this->buildReplace($label, $params);
            $count   = $this->pluralCountFor($rule, $params);
            return $count !== null
                ? $translator->choice($rule, $count, $replace)
                : $translator->get($rule, $replace);
        }

        return $this->builtinMessage($rule, $label, $params);
    }

    private function resolveInlineClassMessage(string $field, RuleInterface $rule): string
    {
        $label     = $this->fieldLabel($field);
        $shortName = (new \ReflectionClass($rule))->getShortName(); // @phpstan-ignore-line
        $custom    = $this->messages["{$field}.{$shortName}"] ?? null;
        if ($custom !== null) {
            return str_replace(':attribute', $label, $custom);
        }
        return str_replace(':attribute', $label, $rule->message());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function fieldLabel(string $field): string
    {
        return ucfirst(str_replace(['_', '.'], ' ', $field));
    }

    /** @return array<string, string> */
    private function buildReplace(string $label, array $params): array
    {
        return [
            'attribute' => $label,
            'min'       => $params[0] ?? '',
            'max'       => $params[1] ?? ($params[0] ?? ''),
            'size'      => $params[0] ?? '',
            'format'    => $params[0] ?? '',
            'values'    => implode(', ', $params),
            'other'     => $params[0] ?? '',
            'value'     => $params[0] ?? '',
            'when'      => $params[1] ?? '',
            'count'     => $params[0] ?? '',
        ];
    }

    private function pluralCountFor(string $rule, array $params): ?int
    {
        return match ($rule) {
            'min_length', 'max_length', 'min_items', 'max_items' => (int) ($params[0] ?? 0),
            default => null,
        };
    }

    private function fillPlaceholders(string $template, string $label, array $params): string
    {
        $replace = array_merge(['attribute' => $label], $this->buildReplace($label, $params));
        foreach ($replace as $k => $v) {
            $template = str_replace(':' . $k, (string) $v, $template);
        }
        return $template;
    }

    /** Check if a rule name (or name with any params) exists in the rule list. */
    private function hasRule(array $rules, string $name): bool
    {
        foreach ($rules as $rule) {
            if (!is_string($rule)) {
                continue;
            }
            if ($rule === $name || str_starts_with($rule, $name . ':')) {
                return true;
            }
        }
        return false;
    }

    /** Check whether the field key exists in $data (handles dot-notation). */
    private function hasKey(string $field): bool
    {
        if (array_key_exists($field, $this->data)) {
            return true;
        }
        $parts  = explode('.', $field);
        $cursor = $this->data;
        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return false;
            }
            $cursor = $cursor[$part];
        }
        return true;
    }

    // -----------------------------------------------------------------
    // Built-in English messages (last-resort fallback)
    // -----------------------------------------------------------------

    private function builtinMessage(string $rule, string $label, array $params): string
    {
        $p0 = $params[0] ?? '';
        $p1 = $params[1] ?? '';
        $n  = (int) $p0;

        return match ($rule) {
            // Presence
            'present'           => "The {$label} field must be present.",
            'filled'            => "The {$label} field must have a value.",
            'prohibited'        => "The {$label} field is prohibited.",
            'prohibited_if'     => "The {$label} field is prohibited when {$p0} is {$p1}.",
            'prohibited_unless' => "The {$label} field is prohibited unless {$p0} is {$p1}.",
            'required_if'       => "The {$label} field is required when {$p0} is {$p1}.",
            'required_unless'   => "The {$label} field is required unless {$p0} is {$p1}.",
            'required_with'     => "The {$label} field is required when " . implode(', ', $params) . " is present.",
            'required_without'  => "The {$label} field is required when " . implode(', ', $params) . " is not present.",
            // Type
            'string'            => "The {$label} must be a string.",
            'integer', 'int'    => "The {$label} must be an integer.",
            'float', 'numeric'  => "The {$label} must be a number.",
            'boolean', 'bool'   => "The {$label} must be true or false.",
            'array'             => "The {$label} must be an array.",
            // Format
            'email'             => "The {$label} must be a valid email address.",
            'url'               => "The {$label} must be a valid URL.",
            'ip', 'ipv4'        => "The {$label} must be a valid IP address.",
            'ipv6'              => "The {$label} must be a valid IPv6 address.",
            'alpha'             => "The {$label} may only contain letters.",
            'alpha_num'         => "The {$label} may only contain letters and numbers.",
            'digits'            => "The {$label} must be numeric digits only.",
            'digits_between'    => "The {$label} must be between {$p0} and {$p1} digits.",
            'date'              => "The {$label} must be a valid date.",
            'date_format'       => "The {$label} must match the format {$p0}.",
            'json'              => "The {$label} must be valid JSON.",
            'uuid'              => "The {$label} must be a valid UUID.",
            'mac_address'       => "The {$label} must be a valid MAC address.",
            'regex', 'not_regex'=> "The {$label} format is invalid.",
            'lowercase'         => "The {$label} must be lowercase.",
            'uppercase'         => "The {$label} must be uppercase.",
            // Value
            'min'               => "The {$label} must be at least {$p0}.",
            'max'               => "The {$label} must not exceed {$p0}.",
            'min_length'        => "The {$label} must be at least {$n} " . ($n === 1 ? 'character' : 'characters') . '.',
            'max_length'        => "The {$label} must not exceed {$n} " . ($n === 1 ? 'character' : 'characters') . '.',
            'size'              => "The {$label} must be exactly {$p0}.",
            'between'           => "The {$label} must be between {$p0} and {$p1}.",
            'multiple_of'       => "The {$label} must be a multiple of {$p0}.",
            'in'                => "The {$label} must be one of: " . implode(', ', $params) . '.',
            'not_in'            => "The {$label} must not be one of: " . implode(', ', $params) . '.',
            'accepted'          => "The {$label} must be accepted.",
            'declined'          => "The {$label} must be declined.",
            'confirmed'         => "The {$label} confirmation does not match.",
            'same'              => "The {$label} must match the {$p0} field.",
            'different'         => "The {$label} must be different from the {$p0} field.",
            'starts_with'       => "The {$label} must start with {$p0}.",
            'ends_with'         => "The {$label} must end with {$p0}.",
            // Array
            'list'              => "The {$label} must be a list.",
            'distinct'          => "The {$label} field has a duplicate value.",
            'min_items'         => "The {$label} must have at least {$n} " . ($n === 1 ? 'item' : 'items') . '.',
            'max_items'         => "The {$label} must not have more than {$n} " . ($n === 1 ? 'item' : 'items') . '.',
            default             => "The {$label} is invalid.",
        };
    }

    // -----------------------------------------------------------------
    // Rule helpers
    // -----------------------------------------------------------------

    private function validateMin(mixed $value, float $min): bool
    {
        if (is_array($value))   return count($value) >= $min;
        if (is_numeric($value)) return (float) $value >= $min;
        return is_string($value) && strlen($value) >= $min;
    }

    private function validateMax(mixed $value, float $max): bool
    {
        if (is_array($value))   return count($value) <= $max;
        if (is_numeric($value)) return (float) $value <= $max;
        return is_string($value) && strlen($value) <= $max;
    }

    private function validateSize(mixed $value, int $size): bool
    {
        if (is_array($value))   return count($value) === $size;
        if (is_numeric($value)) return (int) $value === $size;
        return is_string($value) && strlen($value) === $size;
    }

    private function validateBetween(mixed $value, float $min, float $max): bool
    {
        if (!is_numeric($value)) return false;
        $v = (float) $value;
        return $v >= $min && $v <= $max;
    }

    private function validateMultipleOf(mixed $value, float $divisor): bool
    {
        if (!is_numeric($value) || $divisor === 0.0) return false;
        return fmod((float) $value, $divisor) === 0.0;
    }

    private function validateDigitsBetween(mixed $value, int $min, int $max): bool
    {
        if (!is_string($value) || !ctype_digit($value)) return false;
        $len = strlen($value);
        return $len >= $min && $len <= $max;
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
}