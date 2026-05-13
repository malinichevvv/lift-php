<?php

declare(strict_types=1);

namespace Lift\Attribute;

use Attribute;

/**
 * Maps a method or function to a PATCH HTTP route.
 *
 * ```php
 * #[Patch('/users/{id}')]
 * public function update(Request $req): Response { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final class Patch implements HttpAttributeInterface
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $name = null,
    ) {}

    public function getMethod(): string { return 'PATCH'; }
    public function getPath(): string   { return $this->path; }
    public function getName(): ?string  { return $this->name; }
}
