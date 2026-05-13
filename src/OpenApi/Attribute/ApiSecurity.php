<?php

declare(strict_types=1);

namespace Lift\OpenApi\Attribute;

use Attribute;

/** Applies a security requirement to an operation or controller. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ApiSecurity
{
    /**
     * @param string   $scheme Security scheme name (must exist in components/securitySchemes).
     * @param string[] $scopes Required OAuth2/OIDC scopes (empty for API key / HTTP schemes).
     */
    public function __construct(
        public readonly string $scheme,
        public readonly array  $scopes = [],
    ) {}
}
