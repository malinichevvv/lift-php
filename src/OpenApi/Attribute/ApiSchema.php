<?php

declare(strict_types=1);

namespace Lift\OpenApi\Attribute;

use Attribute;

/** Marks a class as an OpenAPI schema component. */
#[Attribute(Attribute::TARGET_CLASS)]
final class ApiSchema
{
    public function __construct(
        public readonly string $name = '',
        public readonly string $description = '',
    ) {}
}
