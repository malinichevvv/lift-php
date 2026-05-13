<?php

declare(strict_types=1);

namespace Lift\Attribute;

use Attribute;

/**
 * Maps a method or function to a PUT HTTP route.
 *
 * ```php
 * #[Put('/users/{id}')]
 * public function replace(Request $req): Response { ... }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final class Put implements HttpAttributeInterface
{
    public function __construct(
        public readonly string $path,
        public readonly ?string $name = null,
    ) {}

    public function getMethod(): string { return 'PUT'; }
    public function getPath(): string   { return $this->path; }
    public function getName(): ?string  { return $this->name; }
}
