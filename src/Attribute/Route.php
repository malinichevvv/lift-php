<?php

declare(strict_types=1);

namespace Lift\Attribute;

use Attribute;

/**
 * Generic HTTP route attribute. Use when no verb-specific shorthand fits.
 *
 * ```php
 * #[Route('GET', '/users')]
 * #[Route('POST', '/users')]
 * public function handle(Request $req): mixed { ... }
 * ```
 *
 * Can be repeated on the same method (IS_REPEATABLE).
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final class Route implements HttpAttributeInterface
{
    /**
     * @param string      $method HTTP verb (case-insensitive: "get", "POST", etc.)
     * @param string      $path   URL pattern, e.g. "/posts/{id:\d+}"
     * @param string|null $name   Named-route identifier for URL generation
     */
    public function __construct(
        private readonly string $method,
        public readonly string $path,
        public readonly ?string $name = null,
    ) {}

    public function getMethod(): string  { return strtoupper($this->method); }
    public function getPath(): string    { return $this->path; }
    public function getName(): ?string   { return $this->name; }
}
