<?php

declare(strict_types=1);

namespace Lift\Middleware;

use Lift\Cache\CacheInterface;
use Lift\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Token-bucket / fixed-window rate limiting middleware.
 *
 * Uses a {@see CacheInterface} as the counter store, so it works with both
 * {@see \Lift\Cache\ArrayCache} (per-process, for development) and
 * {@see \Lift\Cache\RedisCache} (shared, for production).
 *
 * Standard `RateLimit-*` response headers are added to every response
 * and a `429 Too Many Requests` is returned when the limit is exceeded.
 *
 * ```php
 * // Development: in-memory, no shared state
 * $app->use(new RateLimitMiddleware(new ArrayCache(), maxRequests: 60, windowSeconds: 60));
 *
 * // Production: shared Redis counter
 * $app->use(new RateLimitMiddleware(
 *     store: new RedisCache(new RedisClient()),
 *     maxRequests: 100,
 *     windowSeconds: 60,
 *     keyResolver: fn(Request $req) => $req->getAttribute('user_id') ?? $req->getServerParams()['REMOTE_ADDR'],
 * ));
 * ```
 *
 * @see https://www.ietf.org/archive/id/draft-ietf-httpapi-ratelimit-headers-07.txt
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    /**
     * @param CacheInterface $store         Counter store. Use {@see ArrayCache} for dev/test.
     * @param int            $maxRequests   Maximum requests allowed per window.
     * @param int            $windowSeconds Fixed window duration in seconds.
     * @param callable|null  $keyResolver   Callable(Request): string — returns the rate-limit key.
     *                                      Defaults to client IP from REMOTE_ADDR.
     * @param string         $prefix        Cache key prefix.
     */
    public function __construct(
        private readonly CacheInterface $store,
        private readonly int $maxRequests = 60,
        private readonly int $windowSeconds = 60,
        private readonly mixed $keyResolver = null,
        private readonly string $prefix = 'lift:rl:',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $key     = $this->prefix . $this->resolveKey($request);
        $current = $this->store->increment($key);

        // First hit in this window — set TTL
        if ($current === 1) {
            $this->store->set($key, 1, $this->windowSeconds);
        }

        $remaining = max(0, $this->maxRequests - $current);
        $resetAt   = time() + $this->windowSeconds;

        if ($current > $this->maxRequests) {
            return Response::json(['error' => 'Too Many Requests'], 429)
                ->withHeader('RateLimit-Limit', (string) $this->maxRequests)
                ->withHeader('RateLimit-Remaining', '0')
                ->withHeader('RateLimit-Reset', (string) $resetAt)
                ->withHeader('Retry-After', (string) $this->windowSeconds);
        }

        $response = $handler->handle($request);

        return $response
            ->withHeader('RateLimit-Limit', (string) $this->maxRequests)
            ->withHeader('RateLimit-Remaining', (string) $remaining)
            ->withHeader('RateLimit-Reset', (string) $resetAt);
    }

    private function resolveKey(ServerRequestInterface $request): string
    {
        if ($this->keyResolver !== null) {
            /** @var callable $resolver */
            $resolver = $this->keyResolver;
            return (string) $resolver($request);
        }

        return $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
    }
}
