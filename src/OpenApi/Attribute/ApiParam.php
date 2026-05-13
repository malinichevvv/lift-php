<?php

declare(strict_types=1);

namespace Lift\OpenApi\Attribute;

use Attribute;

/** Describes a single parameter (path, query, header, or cookie). */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final class ApiParam
{
    public function __construct(
        public readonly string $name,
        public readonly string $in = 'query',   // path|query|header|cookie
        public readonly string $description = '',
        public readonly bool   $required = false,
        public readonly string $type = 'string', // string|integer|number|boolean|array|object
        public readonly string $format = '',
        public readonly mixed  $example = null,
    ) {}
}
