<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Cache\RedisCache;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Http\Session\ArraySessionStore;
use Lift\Http\Session\Session;
use Lift\Http\Session\SessionMiddleware;
use Lift\Http\Uri;
use Lift\Middleware\RateLimitMiddleware;
use Lift\Redis\RedisClientInterface;
use Lift\Routing\Route;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CriticalBugFixesTest extends TestCase
{
    public function testSessionMiddlewareUsesFreshSessionPerRequest(): void
    {
        $middleware = new SessionMiddleware(new Session(new ArraySessionStore()));

        $middleware->process(
            new Request('GET', new Uri('/')),
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $request->getAttribute('session')->set('user_id', 123);
                    return Response::text('ok');
                }
            },
        );

        $seen = null;
        $middleware->process(
            new Request('GET', new Uri('/')),
            new class($seen) implements RequestHandlerInterface {
                public function __construct(private mixed &$seen) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->seen = $request->getAttribute('session')->get('user_id');
                    return Response::text('ok');
                }
            },
        );

        self::assertNull($seen);
    }

    public function testRedisRateLimiterKeepsCounterAsRawInteger(): void
    {
        $redis = new CriticalFakeRedis();
        $cache = new RedisCache($redis);
        $middleware = new RateLimitMiddleware($cache, maxRequests: 10, windowSeconds: 60);
        $request = new Request('GET', new Uri('/'), serverParams: ['REMOTE_ADDR' => '127.0.0.1']);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return Response::text('ok');
            }
        };

        $middleware->process($request, $handler);
        $middleware->process($request, $handler);

        self::assertSame('2', $redis->get('lift:cache:lift:rl:127.0.0.1'));
        self::assertSame(60, $redis->ttl('lift:cache:lift:rl:127.0.0.1'));
    }

    public function testInvalidRouteParameterNameFailsEarly(): void
    {
        $route = new Route(['GET'], '/users/{123}', static fn() => Response::text('bad'));

        $this->expectException(\InvalidArgumentException::class);
        $route->pathMatches('/users/123');
    }
}

final class CriticalFakeRedis implements RedisClientInterface
{
    /** @var array<string, string> */
    private array $values = [];
    /** @var array<string, int> */
    private array $ttls = [];

    public function get(string $key): string|false { return $this->values[$key] ?? false; }
    public function set(string $key, string $value, int $ttl = 0): bool { $this->values[$key] = $value; $this->ttls[$key] = $ttl; return true; }
    public function del(string ...$keys): int { foreach ($keys as $key) unset($this->values[$key], $this->ttls[$key]); return count($keys); }
    public function exists(string $key): int { return isset($this->values[$key]) ? 1 : 0; }
    public function expire(string $key, int $ttl): bool { $this->ttls[$key] = $ttl; return true; }
    public function ttl(string $key): int { return $this->ttls[$key] ?? -1; }
    public function incr(string $key): int { return $this->incrBy($key, 1); }

    public function incrBy(string $key, int $by): int
    {
        $current = $this->values[$key] ?? '0';
        if (!preg_match('/^-?\d+$/', $current)) {
            throw new \RuntimeException('ERR value is not an integer or out of range');
        }
        $next = (int) $current + $by;
        $this->values[$key] = (string) $next;
        return $next;
    }

    public function lPush(string $key, string ...$values): int { return 0; }
    public function rPop(string $key): string|false { return false; }
    public function lLen(string $key): int { return 0; }
    public function zAdd(string $key, float $score, string $member): int { return 0; }
    public function zRangeByScore(string $key, string $min, string $max): array { return []; }
    public function zRem(string $key, string ...$members): int { return 0; }
    public function ping(): bool { return true; }
    public function select(int $db): bool { return true; }
}
