<?php

declare(strict_types=1);

namespace Lift\OpenApi\Attribute;

use Attribute;

/** Documents one HTTP response for an operation. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final class ApiResponse
{
    public function __construct(
        public readonly int    $status = 200,
        public readonly string $description = 'OK',
        public readonly string $mediaType = 'application/json',
        /** Fully qualified class name or inline JSON schema array (as JSON string). */
        public readonly string $schema = '',
    ) {}
}
