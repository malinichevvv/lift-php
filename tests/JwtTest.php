<?php

declare(strict_types=1);

use Lift\Jwt\Claims;
use Lift\Jwt\Jwt;
use Lift\Jwt\JwtAlgorithm;
use Lift\Jwt\JwtException;
use Lift\Jwt\JwtMiddleware;
use Lift\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use PHPUnit\Framework\TestCase;

class JwtTest extends TestCase
{
    private Jwt $jwt;

    protected function setUp(): void
    {
        $this->jwt = new Jwt(secret: 'super-secret-key-for-testing-only-32bytes');
    }

    // ---------------------------------------------------------------
    // JwtAlgorithm enum
    // ---------------------------------------------------------------

    public function testAlgorithmHmacDetection(): void
    {
        $this->assertTrue(JwtAlgorithm::HS256->isHmac());
        $this->assertTrue(JwtAlgorithm::HS384->isHmac());
        $this->assertTrue(JwtAlgorithm::HS512->isHmac());
        $this->assertFalse(JwtAlgorithm::RS256->isHmac());
        $this->assertFalse(JwtAlgorithm::RS256->isHmac());
    }

    public function testAlgorithmRsaDetection(): void
    {
        $this->assertTrue(JwtAlgorithm::RS256->isRsa());
        $this->assertFalse(JwtAlgorithm::HS256->isRsa());
    }

    // ---------------------------------------------------------------
    // Constructor validation
    // ---------------------------------------------------------------

    public function testHmacRequiresSecret(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Jwt(secret: '');
    }

    public function testRsaRequiresKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Jwt(algo: JwtAlgorithm::RS256);
    }

    // ---------------------------------------------------------------
    // Encode / decode round-trip (HS256)
    // ---------------------------------------------------------------

    public function testEncodeAndDecodeRoundTrip(): void
    {
        $payload = ['sub' => 'user_1', 'role' => 'admin'];
        $token   = $this->jwt->encode($payload);

        $this->assertIsString($token);
        $this->assertStringContainsString('.', $token);

        $decoded = $this->jwt->decode($token);
        $this->assertSame('user_1', $decoded['sub']);
        $this->assertSame('admin', $decoded['role']);
    }

    public function testTokenHasThreeSegments(): void
    {
        $token = $this->jwt->encode(['x' => 1]);
        $this->assertCount(3, explode('.', $token));
    }

    public function testHeaderContainsAlgorithmAndType(): void
    {
        $token   = $this->jwt->encode(['x' => 1]);
        $parts   = explode('.', $token);
        $header  = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);

        $this->assertSame('JWT', $header['typ']);
        $this->assertSame('HS256', $header['alg']);
    }

    // ---------------------------------------------------------------
    // Signature validation
    // ---------------------------------------------------------------

    public function testTamperedPayloadFails(): void
    {
        $token  = $this->jwt->encode(['sub' => 'user_1']);
        $parts  = explode('.', $token);
        $parts[1] = rtrim(strtr(base64_encode(json_encode(['sub' => 'admin'])), '+/', '-_'), '=');
        $tampered = implode('.', $parts);

        $this->expectException(JwtException::class);
        $this->jwt->decode($tampered);
    }

    public function testTamperedSignatureFails(): void
    {
        $token  = $this->jwt->encode(['sub' => 'user_1']);
        $parts  = explode('.', $token);
        $parts[2] = 'invalidsignature';
        $tampered = implode('.', $parts);

        $this->expectException(JwtException::class);
        $this->jwt->decode($tampered);
    }

    public function testWrongSecretFails(): void
    {
        $other = new Jwt(secret: 'different-secret-key-for-testing-only-32bytes');
        $token = $other->encode(['sub' => 'x']);

        $this->expectException(JwtException::class);
        $this->jwt->decode($token);
    }

    public function testMalformedTokenFails(): void
    {
        $this->expectException(JwtException::class);
        $this->jwt->decode('not.a.valid.token.with.too.many.parts');
    }

    public function testEmptyTokenFails(): void
    {
        $this->expectException(JwtException::class);
        $this->jwt->decode('');
    }

    // ---------------------------------------------------------------
    // Standard claims
    // ---------------------------------------------------------------

    public function testExpiredTokenFails(): void
    {
        $token = $this->jwt->encode(['exp' => time() - 1]);

        $this->expectException(JwtException::class);
        $this->expectExceptionMessageMatches('/expired/i');
        $this->jwt->decode($token);
    }

    public function testFutureTokenPassesWithinLeeway(): void
    {
        $jwt   = new Jwt(secret: 'super-secret-key-for-testing-only-32bytes', leeway: 10);
        $token = $jwt->encode(['exp' => time() - 5]);

        $decoded = $jwt->decode($token);
        $this->assertArrayHasKey('exp', $decoded);
    }

    public function testNotBeforeTokenFails(): void
    {
        $token = $this->jwt->encode(['nbf' => time() + 9999]);

        $this->expectException(JwtException::class);
        $this->expectExceptionMessageMatches('/not yet valid/i');
        $this->jwt->decode($token);
    }

    public function testIssuerMismatchFails(): void
    {
        $jwt   = new Jwt(secret: 'super-secret-key-for-testing-only-32bytes', issuer: 'https://expected.com');
        $token = $jwt->encode(['iss' => 'https://wrong.com']);

        $this->expectException(JwtException::class);
        $this->expectExceptionMessageMatches('/issuer/i');
        $jwt->decode($token);
    }

    public function testIssuerMatchPasses(): void
    {
        $jwt   = new Jwt(secret: 'super-secret-key-for-testing-only-32bytes', issuer: 'https://api.example.com');
        $token = $jwt->encode(['iss' => 'https://api.example.com']);

        $decoded = $jwt->decode($token);
        $this->assertSame('https://api.example.com', $decoded['iss']);
    }

    public function testAudienceMismatchFails(): void
    {
        $jwt   = new Jwt(secret: 'super-secret-key-for-testing-only-32bytes', audience: 'app-a');
        $token = $jwt->encode(['aud' => 'app-b']);

        $this->expectException(JwtException::class);
        $this->expectExceptionMessageMatches('/audience/i');
        $jwt->decode($token);
    }

    public function testAudienceArrayPasses(): void
    {
        $jwt   = new Jwt(secret: 'super-secret-key-for-testing-only-32bytes', audience: 'app-a');
        $token = $jwt->encode(['aud' => ['app-a', 'app-b']]);

        $decoded = $jwt->decode($token);
        $this->assertIsArray($decoded['aud']);
    }

    // ---------------------------------------------------------------
    // Different HMAC algorithms
    // ---------------------------------------------------------------

    public function testHs384RoundTrip(): void
    {
        $jwt   = new Jwt(secret: 'super-secret-key-for-testing-only-32bytes', algo: JwtAlgorithm::HS384);
        $token = $jwt->encode(['sub' => 'hs384']);
        $this->assertSame('hs384', $jwt->decode($token)['sub']);
    }

    public function testHs512RoundTrip(): void
    {
        $jwt   = new Jwt(secret: 'super-secret-key-for-testing-only-32bytes', algo: JwtAlgorithm::HS512);
        $token = $jwt->encode(['sub' => 'hs512']);
        $this->assertSame('hs512', $jwt->decode($token)['sub']);
    }

    // ---------------------------------------------------------------
    // Claims builder
    // ---------------------------------------------------------------

    public function testClaimsBuilderProducesCorrectPayload(): void
    {
        $payload = Claims::make()
            ->subject('user_42')
            ->issuer('https://api.example.com')
            ->audience('https://app.example.com')
            ->expiresIn(3600)
            ->extra(['role' => 'admin'])
            ->toArray();

        $this->assertSame('user_42', $payload['sub']);
        $this->assertSame('https://api.example.com', $payload['iss']);
        $this->assertSame('https://app.example.com', $payload['aud']);
        $this->assertSame('admin', $payload['role']);
        $this->assertGreaterThan(time(), $payload['exp']);
    }

    public function testClaimsBuilderWithJwt(): void
    {
        $payload = Claims::make()
            ->subject('u1')
            ->expiresIn(3600)
            ->toArray();

        $token   = $this->jwt->encode($payload);
        $decoded = $this->jwt->decode($token);
        $this->assertSame('u1', $decoded['sub']);
    }

    public function testClaimsExpiresAt(): void
    {
        $exp     = time() + 7200;
        $payload = Claims::make()->expiresAt($exp)->toArray();
        $this->assertSame($exp, $payload['exp']);
    }

    public function testClaimsNotBefore(): void
    {
        $nbf     = time() - 60;
        $payload = Claims::make()->notBefore($nbf)->toArray();
        $this->assertSame($nbf, $payload['nbf']);
    }

    public function testClaimsIssuedAt(): void
    {
        $before  = time();
        $payload = Claims::make()->issuedAt()->toArray();
        $this->assertGreaterThanOrEqual($before, $payload['iat']);
    }

    public function testClaimsId(): void
    {
        $payload = Claims::make()->id('unique-jti-123')->toArray();
        $this->assertSame('unique-jti-123', $payload['jti']);
    }

    // ---------------------------------------------------------------
    // JwtMiddleware
    // ---------------------------------------------------------------

    private function makeHandler(array &$captured): RequestHandlerInterface
    {
        return new class($captured) implements RequestHandlerInterface {
            public function __construct(private array &$captured) {}
            public function handle(ServerRequestInterface $req): ResponseInterface
            {
                $this->captured = $req->getAttribute('jwt') ?? [];
                return \Lift\Http\Response::html('ok');
            }
        };
    }

    private function makeRequest(string $token = ''): Request
    {
        $req = Request::fromGlobals();
        if ($token !== '') {
            $req = $req->withHeader('Authorization', "Bearer {$token}");
        }
        return $req;
    }

    public function testMiddlewarePassesWithValidToken(): void
    {
        $token  = $this->jwt->encode(['sub' => 'user_99']);
        $mw     = new JwtMiddleware($this->jwt);
        $captured = [];
        $handler  = $this->makeHandler($captured);

        $response = $mw->process($this->makeRequest($token), $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('user_99', $captured['sub']);
    }

    public function testMiddlewareReturns401WithoutHeader(): void
    {
        $mw      = new JwtMiddleware($this->jwt);
        $captured = [];
        $response = $mw->process($this->makeRequest(), $this->makeHandler($captured));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('WWW-Authenticate'));
    }

    public function testMiddlewareReturns401WithExpiredToken(): void
    {
        $token = $this->jwt->encode(['exp' => time() - 1]);
        $mw    = new JwtMiddleware($this->jwt);
        $captured = [];

        $response = $mw->process($this->makeRequest($token), $this->makeHandler($captured));
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testMiddlewareBypassesExceptedPaths(): void
    {
        $mw = new JwtMiddleware($this->jwt, except: ['/health']);
        $captured = [];
        $handler  = $this->makeHandler($captured);

        // Request with no token but on excluded path
        $req = $this->makeRequest()->withUri(
            new \Lift\Http\Uri('http://localhost/health')
        );
        $response = $mw->process($req, $handler);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMiddlewareUsesCustomAttribute(): void
    {
        $token = $this->jwt->encode(['sub' => 'x']);
        $mw    = new JwtMiddleware($this->jwt, attribute: 'token_data');

        $capturedRaw = null;
        $handler = new class($capturedRaw) implements RequestHandlerInterface {
            public function __construct(public mixed &$out) {}
            public function handle(ServerRequestInterface $req): ResponseInterface
            {
                $this->out = $req->getAttribute('token_data');
                return \Lift\Http\Response::html('');
            }
        };

        $mw->process($this->makeRequest($token), $handler);
        $this->assertSame('x', $capturedRaw['sub']);
    }
}
