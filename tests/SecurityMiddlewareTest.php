<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\App;
use Lift\Cache\ArrayCache;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Http\Stream;
use Lift\Http\Uri;
use Lift\Middleware\CorsMiddleware;
use Lift\Middleware\CsrfMiddleware;
use Lift\Middleware\RateLimitMiddleware;
use Lift\Middleware\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;

class SecurityMiddlewareTest extends TestCase
{
    private function app(): App
    {
        $app = new App();
        $app->get('/test', fn() => Response::text('ok'));
        $app->post('/test', fn() => Response::text('ok'));
        return $app;
    }

    private function request(string $method, string $uri, array $headers = [], ?string $body = null): Request
    {
        $stream = $body !== null ? Stream::fromString($body) : Stream::empty();
        $req    = new Request($method, new Uri($uri), headers: $headers, body: $stream);
        if ($body !== null) {
            $req = $req->withParsedBody(json_decode($body, true) ?? []);
        }
        return $req;
    }

    // -----------------------------------------------------------------
    // CORS
    // -----------------------------------------------------------------

    public function testCorsAddsHeaderForAllowedOrigin(): void
    {
        $app = $this->app();
        $app->use(new CorsMiddleware(origins: 'https://example.com'));

        $res = $app->handle($this->request('GET', 'http://localhost/test', ['Origin' => 'https://example.com']));
        self::assertSame('https://example.com', $res->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCorsWildcardAllowsAll(): void
    {
        $app = $this->app();
        $app->use(new CorsMiddleware(origins: '*'));
        $res = $app->handle($this->request('GET', 'http://localhost/test', ['Origin' => 'https://random.io']));
        self::assertSame('*', $res->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCorsPreflightReturns204(): void
    {
        $app = new App();
        $app->use(new CorsMiddleware());
        $res = $app->handle($this->request('OPTIONS', 'http://localhost/any', ['Origin' => 'https://x.com']));
        self::assertSame(204, $res->getStatusCode());
        self::assertNotEmpty($res->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testCorsRejectsUnknownOrigin(): void
    {
        $app = $this->app();
        $app->use(new CorsMiddleware(origins: ['https://safe.com']));
        $res = $app->handle($this->request('GET', 'http://localhost/test', ['Origin' => 'https://evil.com']));
        self::assertEmpty($res->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testCorsCredentials(): void
    {
        $app = $this->app();
        $app->use(new CorsMiddleware(origins: 'https://app.com', credentials: true));
        $res = $app->handle($this->request('GET', 'http://localhost/test', ['Origin' => 'https://app.com']));
        self::assertSame('true', $res->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    // -----------------------------------------------------------------
    // Security headers
    // -----------------------------------------------------------------

    public function testSecurityHeadersDefaults(): void
    {
        $app = $this->app();
        $app->use(new SecurityHeadersMiddleware());
        $res = $app->handle($this->request('GET', 'http://localhost/test'));

        self::assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $res->getHeaderLine('X-Frame-Options'));
        self::assertNotEmpty($res->getHeaderLine('Content-Security-Policy'));
        self::assertNotEmpty($res->getHeaderLine('Referrer-Policy'));
    }

    public function testSecurityHeadersCanBeDisabled(): void
    {
        $app = $this->app();
        $app->use(new SecurityHeadersMiddleware(csp: null, hsts: null, noSniff: false));
        $res = $app->handle($this->request('GET', 'http://localhost/test'));

        self::assertEmpty($res->getHeaderLine('Content-Security-Policy'));
        self::assertEmpty($res->getHeaderLine('Strict-Transport-Security'));
        self::assertEmpty($res->getHeaderLine('X-Content-Type-Options'));
    }

    public function testCustomCsp(): void
    {
        $app = $this->app();
        $app->use(new SecurityHeadersMiddleware(csp: "default-src 'none'"));
        $res = $app->handle($this->request('GET', 'http://localhost/test'));
        self::assertSame("default-src 'none'", $res->getHeaderLine('Content-Security-Policy'));
    }

    // -----------------------------------------------------------------
    // Rate limiting
    // -----------------------------------------------------------------

    public function testRateLimitPassesNormalRequest(): void
    {
        $app = $this->app();
        $app->use(new RateLimitMiddleware(new ArrayCache(), maxRequests: 5, windowSeconds: 60));
        $res = $app->handle($this->request('GET', 'http://localhost/test'));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('5', $res->getHeaderLine('RateLimit-Limit'));
    }

    public function testRateLimitBlocks429(): void
    {
        $cache = new ArrayCache();
        $app   = $this->app();
        $app->use(new RateLimitMiddleware($cache, maxRequests: 2, windowSeconds: 60));

        $app->handle($this->request('GET', 'http://localhost/test'));
        $app->handle($this->request('GET', 'http://localhost/test'));
        $res = $app->handle($this->request('GET', 'http://localhost/test'));

        self::assertSame(429, $res->getStatusCode());
        self::assertNotEmpty($res->getHeaderLine('Retry-After'));
    }

    public function testRateLimitDecrementsRemaining(): void
    {
        $cache = new ArrayCache();
        $app   = $this->app();
        $app->use(new RateLimitMiddleware($cache, maxRequests: 10, windowSeconds: 60));

        $res1 = $app->handle($this->request('GET', 'http://localhost/test'));
        $res2 = $app->handle($this->request('GET', 'http://localhost/test'));

        self::assertSame('9', $res1->getHeaderLine('RateLimit-Remaining'));
        self::assertSame('8', $res2->getHeaderLine('RateLimit-Remaining'));
    }

    // -----------------------------------------------------------------
    // CSRF
    // -----------------------------------------------------------------

    public function testCsrfAllowsGetWithoutToken(): void
    {
        $app = $this->app();
        $app->use(new CsrfMiddleware(secret: 'test-secret'));
        $res = $app->handle($this->request('GET', 'http://localhost/test'));
        self::assertSame(200, $res->getStatusCode());
    }

    public function testCsrfBlocksPostWithoutToken(): void
    {
        $app = $this->app();
        $app->use(new CsrfMiddleware(secret: 'test-secret', secure: false));
        $res = $app->handle($this->request('POST', 'http://localhost/test'));
        self::assertSame(403, $res->getStatusCode());
    }

    public function testCsrfAllowsPostWithValidToken(): void
    {
        $csrf = new CsrfMiddleware(secret: 'test-secret', secure: false);
        $app  = $this->app();
        $app->use($csrf);

        // First GET to obtain the CSRF cookie
        $getRes   = $app->handle($this->request('GET', 'http://localhost/test'));
        $setCookie = $getRes->getHeaderLine('Set-Cookie');
        preg_match('/csrf_token=([^;]+)/', $setCookie, $m);
        $token = rawurldecode($m[1] ?? '');

        // POST with the token in the header and cookie in the request
        $postReq = $this->request('POST', 'http://localhost/test', [
            'X-CSRF-Token' => $token,
            'Cookie'       => "csrf_token={$m[1]}",
        ]);
        // Simulate cookie params
        $postReq = $postReq->withCookieParams(['csrf_token' => $token]);
        $res     = $app->handle($postReq);

        self::assertSame(200, $res->getStatusCode());
    }

    public function testCsrfRejectsEmptySecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CsrfMiddleware(secret: '');
    }

    public function testCsrfRejectsInvalidSameSite(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CsrfMiddleware(secret: 'test-secret', sameSite: 'Invalid');
    }

    public function testCsrfRequiresSecureForSameSiteNone(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CsrfMiddleware(secret: 'test-secret', secure: false, sameSite: 'None');
    }
}
