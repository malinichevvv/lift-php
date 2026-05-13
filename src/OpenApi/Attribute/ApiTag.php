<?php

declare(strict_types=1);

namespace Lift\OpenApi\Attribute;

use Attribute;

/** Tags applied to all operations in a controller class. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class ApiTag
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
    ) {}
}
