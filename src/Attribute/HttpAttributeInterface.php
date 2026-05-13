<?php

declare(strict_types=1);

namespace Lift\Attribute;

/**
 * Marker interface for HTTP-verb route attributes (#[Get], #[Post], etc.).
 *
 * Every attribute that maps a controller method to an HTTP route must implement
 * this interface so that {@see \Lift\Routing\AttributeLoader} can handle them
 * uniformly via reflection.
 */
interface HttpAttributeInterface
{
    /**
     * HTTP verb this attribute represents (uppercase, e.g. "GET").
     */
    public function getMethod(): string;

    /**
     * URL path pattern, e.g. "/users/{id:\d+}".
     */
    public function getPath(): string;

    /**
     * Optional named-route identifier.
     */
    public function getName(): ?string;
}
