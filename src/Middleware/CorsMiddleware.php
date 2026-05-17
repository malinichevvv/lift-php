<?php

declare(strict_types=1);

namespace Lift\Middleware;

use Lift\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Cross-Origin Resource Sharing (CORS) middleware.
 *
 * Handles `OPTIONS` preflight requests and appends the appropriate
 * `Access-Control-*` headers to every response.
 *
 * ```php
 * $app->use(new CorsMiddleware(
 *     origins: ['https://app.example.com', 'https://www.example.com'],
 *     methods: ['GET', 'POST', 'PUT', 'DELETE'],
 *     headers: ['Content-Type', 'Authorization'],
 *     credentials: true,
 *     maxAge: 7200,
 * ));
 *
 * // Wildcard — allow all origins (not compatible with credentials: true)
 * $app->use(new CorsMiddleware(origins: '*'));
 * ```
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /** @var string|list<string> Allowed origins, or "*" for all. */
    private readonly string|array $origins;

    /**
     * @param string|list<string> $origins     Allowed origin(s). Use "*" to allow all.
     * @param list<string>        $methods     Allowed HTTP methods.
     * @param list<string>        $headers     Allowed request headers.
     * @param list<string>        $exposeHeaders Response headers the browser may read.
     * @param bool                $credentials  Whether to allow credentials (cookies, auth headers).
     *                                          Must be false when {@see $origins} is "*".
     * @param int                 $maxAge       Seconds the preflight response may be cached.
     */
    public function __construct(
        string|array $origins = '*',
        private readonly array $methods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private readonly array $headers = ['Content-Type', 'Authorization', 'Accept', 'X-Requested-With'],
        private readonly array $exposeHeaders = [],
        private readonly bool $credentials = false,
        private readonly int $maxAge = 86400,
    ) {
        // Wildcard origin + credentials is an insecure combination: it would
        // reflect ANY request Origin together with Access-Control-Allow-Credentials,
        // letting any site make credentialed cross-origin requests and read the
        // response. Fail fast instead of silently allowing it — pass an explicit
        // list of trusted origins when credentials are required.
        if ($origins === '*' && $credentials) {
            throw new \InvalidArgumentException(
                'CorsMiddleware: origins "*" cannot be combined with credentials: true. '
                . 'Specify an explicit list of allowed origins when credentials are enabled.'
            );
        }

        $this->origins = $origins;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // Non-CORS request
        if ($origin === '') {
            return $handler->handle($request);
        }

        // Preflight
        if ($request->getMethod() === 'OPTIONS') {
            return $this->buildPreflightResponse($origin);
        }

        $response = $handler->handle($request);
        return $this->addCorsHeaders($response, $origin);
    }

    private function buildPreflightResponse(string $origin): ResponseInterface
    {
        return $this->addCorsHeaders(Response::noContent(), $origin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->methods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->headers))
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
    }

    private function addCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        $allowedOrigin = $this->resolveAllowedOrigin($origin);
        if ($allowedOrigin === null) {
            return $response;
        }

        $response = $response->withHeader('Access-Control-Allow-Origin', $allowedOrigin);

        if ($this->credentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        if ($this->exposeHeaders !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->exposeHeaders));
        }

        if ($allowedOrigin !== '*') {
            $response = $response->withAddedHeader('Vary', 'Origin');
        }

        return $response;
    }

    /**
     * Return the origin value to put in `Access-Control-Allow-Origin`,
     * or null if the request origin is not allowed.
     */
    private function resolveAllowedOrigin(string $origin): ?string
    {
        if ($this->origins === '*') {
            return $this->credentials ? $origin : '*';
        }

        $allowed = is_array($this->origins) ? $this->origins : [$this->origins];

        foreach ($allowed as $pattern) {
            if ($this->matchOrigin($origin, $pattern)) {
                return $origin;
            }
        }

        return null;
    }

    /**
     * Match an origin against a pattern. Supports a leading wildcard subdomain:
     * `*.example.com` matches `https://api.example.com`.
     */
    private function matchOrigin(string $origin, string $pattern): bool
    {
        if ($origin === $pattern) {
            return true;
        }

        if (str_starts_with($pattern, '*.')) {
            $domain = substr($pattern, 2);
            // https://api.example.com matches *.example.com
            if (preg_match('/^https?:\/\/[^.]+\.' . preg_quote($domain, '/') . '$/', $origin)) {
                return true;
            }
        }

        return false;
    }
}
