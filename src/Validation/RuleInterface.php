<?php

declare(strict_types=1);

namespace Lift\Validation;

interface RuleInterface
{
    public function passes(string $field, mixed $value, array $data): bool;

    /** Error template; may use :attribute placeholder. */
    public function message(): string;
}