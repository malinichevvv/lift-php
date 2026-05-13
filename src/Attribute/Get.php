<?php

declare(strict_types=1);

namespace Lift\Attribute;

use Attribute;

/**
 * Maps a method or function to a GET HTTP route.
 *
 * ```php
 * #[Get('/users')]
 * public function index(): array { ... }
 *
 * #[Get('/users/{id:\d+}', name: 'users.show')]
 * public function show(Request $req): Response { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final class Get implements HttpAttributeInterface
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $name = null,
    ) {}

    public function getMethod(): string { return 'GET'; }
    public function getPath(): string   { return $this->path; }
    public function getName(): ?string  { return $this->name; }
}
