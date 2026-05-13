<?php

declare(strict_types=1);

namespace Lift\Attribute;

use Attribute;

/**
 * Prepends a common URL prefix to every route in the annotated controller.
 *
 * Applied at class level; the prefix is concatenated before each method's path.
 *
 * ```php
 * #[Group('/api/v1')]
 * #[Middleware(AuthMiddleware::class)]
 * class UserController
 * {
 *     #[Get('/users')]          // → GET /api/v1/users
 *     public function index() { ... }
 *
 *     #[Get('/users/{id}')]     // → GET /api/v1/users/{id}
 *     public function show(Request $req) { ... }
 * }
 * ```
 *
 * @see \Lift\Routing\AttributeLoader
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Group
{
    /**
     * @param string $prefix Leading slash is normalised automatically.
     */
    public function __construct(
        public readonly string $prefix,
    ) {}
}
