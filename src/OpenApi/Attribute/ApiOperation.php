<?php

declare(strict_types=1);

namespace Lift\OpenApi\Attribute;

use Attribute;

/** Describes an HTTP operation on a route. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class ApiOperation
{
    public function __construct(
        public readonly string $summary = '',
        public readonly string $description = '',
        public readonly string $operationId = '',
        /** @var string[] */
        public readonly array $tags = [],
    ) {}
}
