<?php

declare(strict_types=1);

namespace Lift\Attribute;

use Attribute;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Attaches one or more middleware to a controller class or a single method.
 *
 * Middleware is resolved from the DI container when specified as a class name.
 * Can be repeated on the same target to add multiple middleware in declared order.
 *
 * ```php
 * #[Middleware(AuthMiddleware::class)]
 * #[Middleware(RateLimitMiddleware::class)]
 * class UserController { ... }
 *
 * #[Post('/admin/users')]
 * #[Middleware(AdminMiddleware::class)]
 * public function store(Request $req): Response { ... }
 * ```
 *
 * @see \Lift\Routing\AttributeLoader
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Middleware
{
    /**
     * @param class-string<MiddlewareInterface>|class-string<MiddlewareInterface>[] $middleware
     *        One middleware class name or an array of class names (applied left to right).
     */
    public function __construct(
        public readonly string|array $middleware,
    ) {}

    /**
     * Returns middleware class names as a flat list.
     *
     * @return class-string<MiddlewareInterface>[]
     */
    public function toArray(): array
    {
        return (array) $this->middleware;
    }
}
