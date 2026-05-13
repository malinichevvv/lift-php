<?php

declare(strict_types=1);

namespace Lift\Http\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Starts a driver-backed session, exposes it as a request attribute, and
 * writes the `Set-Cookie` header on every response so the browser always
 * holds the correct session ID.
 *
 * The session is available inside handlers via the request attribute (default `"session"`):
 * ```php
 * $session = $request->getAttribute('session'); // Session instance
 * ```
 *
 * The middleware:
 * 1. Starts the session (hydrates from the backing store).
 * 2. Attaches the `Session` as a request attribute.
 * 3. Calls `ageFlashData()` then `save()` in a `finally` block so the session
 *    is always persisted even when the handler throws.
 * 4. Appends the `Set-Cookie` header to the response.
 */
class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly string $attribute = 'session',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->session->start();
        try {
            $response = $handler->handle($request->withAttribute($this->attribute, $this->session));
        } finally {
            $this->session->ageFlashData();
            $this->session->save();
        }

        $secure = strtolower($request->getUri()->getScheme()) === 'https';
        return $response->withAddedHeader('Set-Cookie', $this->session->toCookieHeader($secure));
    }
}
