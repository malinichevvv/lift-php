<?php

declare(strict_types=1);

namespace Lift\Attribute;

use Attribute;

/**
 * Maps a method or function to a DELETE HTTP route.
 *
 * ```php
 * #[Delete('/users/{id}')]
 * public function destroy(Request $req): Response { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final class Delete implements HttpAttributeInterface
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $name = null,
    ) {}

    public function getMethod(): string { return 'DELETE'; }
    public function getPath(): string   { return $this->path; }
    public function getName(): ?string  { return $this->name; }
}
