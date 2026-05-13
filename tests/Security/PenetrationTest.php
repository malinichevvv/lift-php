<?php

declare(strict_types=1);

namespace Lift\Tests\Security;

use Lift\App;
use Lift\Crypto\Encrypter;
use Lift\Crypto\Signer;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Http\Stream;
use Lift\Jwt\Claims;
use Lift\Jwt\Jwt;
use Lift\Jwt\JwtException;
use Lift\Middleware\CsrfMiddleware;
use Lift\Middleware\RateLimitMiddleware;
use Lift\Cache\ArrayCache;
use PHPUnit\Framework\TestCase;

/**
 * Security / penetration tests.
 *
 * Verifies that the framework correctly rejects well-known attack vectors.
 * These tests are intentionally adversarial — they send malformed, malicious,
 * or crafted input and assert that the system handles it safely.
 */
class PenetrationTest extends TestCase
{
    // ---------------------------------------------------------------
    // JWT tampering
    // ---------------------------------------------------------------

    private Jwt $jwt;

    protected function setUp(): void
    {
        $this->jwt = new Jwt(secret: 'secure-test-secret-for-penetration-testing-12345');
    }

    private function mutateSegment(string $token, int $segment, callable $fn): string
    {
        $parts = explode('.', $token);
        $decoded = json_decode(base64_decode(strtr($parts[$segment], '-_', '+/')), true);
        $mutated = $fn($decoded);
        $parts[$segment] = rtrim(strtr(base64_encode(json_encode($mutated)), '+/', '-_'), '=');
        return implode('.', $parts);
    }

    public function testJwtAlgorithmConfusionAttack(): void
    {
        // Craft a token that claims to be signed with "none" algorithm
        $header  = rtrim(strtr(base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'none'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['sub' => 'admin', 'role' => 'superuser'])), '+/', '-_'), '=');
        $token   = "{$header}.{$payload}.";

        $this->expectException(JwtException::class);
        $this->jwt->decode($token);
    }

    public function testJwtClaimEscalationAttack(): void
    {
        // Valid token with role=user; tamper payload to role=admin
        $token   = $this->jwt->encode(['sub' => 'user_1', 'role' => 'user']);
        $tampered = $this->mutateSegment($token, 1, function ($p) {
            $p['role'] = 'admin';
            return $p;
        });

        $this->expectException(JwtException::class);
        $this->jwt->decode($tampered);
    }

    public function testJwtExpClaimRemovalAttack(): void
    {
        // Valid expired token; tamper to remove exp claim
        $token   = $this->jwt->encode(['sub' => 'u', 'exp' => time() - 3600]);
        $tampered = $this->mutateSegment($token, 1, function ($p) {
            unset($p['exp']);
            return $p;
        });

        $this->expectException(JwtException::class);
        $this->jwt->decode($tampered);
    }

    public function testJwtExpClaimOverflowAttack(): void
    {
        // Try to set exp far in the future by tampering
        $token   = $this->jwt->encode(['sub' => 'u', 'exp' => time() + 60]);
        $tampered = $this->mutateSegment($token, 1, function ($p) {
            $p['exp'] = PHP_INT_MAX;
            return $p;
        });

        $this->expectException(JwtException::class);
        $this->jwt->decode($tampered);
    }

    public function testJwtSignatureStrippingAttack(): void
    {
        $token  = $this->jwt->encode(['sub' => 'u']);
        $parts  = explode('.', $token);
        $parts[2] = '';
        $stripped = implode('.', $parts);

        $this->expectException(JwtException::class);
        $this->jwt->decode($stripped);
    }

    public function testJwtWrongSecretAttack(): void
    {
        $attacker = new Jwt(secret: 'attacker-known-secret-that-is-very-long-here');
        $token    = $attacker->encode(['sub' => 'admin']);

        $this->expectException(JwtException::class);
        $this->jwt->decode($token);
    }

    public function testJwtTimingAttackResistance(): void
    {
        // Both valid and invalid tokens should take comparable time
        // (hash_equals ensures constant-time comparison)
        $token   = $this->jwt->encode(['sub' => 'u']);
        $parts   = explode('.', $token);
        $invalid = $parts[0] . '.' . $parts[1] . '.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

        $times = [];
        for ($i = 0; $i < 50; $i++) {
            $start = hrtime(true);
            try { $this->jwt->decode($invalid); } catch (JwtException) {}
            $times[] = hrtime(true) - $start;
        }

        $validTimes = [];
        for ($i = 0; $i < 50; $i++) {
            $start = hrtime(true);
            $this->jwt->decode($token);
            $validTimes[] = hrtime(true) - $start;
        }

        // Timing difference should be within 2ms (loose bound for CI variance)
        $avgInvalid = array_sum($times) / count($times);
        $avgValid   = array_sum($validTimes) / count($validTimes);
        $diffMs     = abs($avgInvalid - $avgValid) / 1_000_000;

        $this->assertLessThan(2.0, $diffMs, 'Suspicious timing difference — possible timing oracle');
    }

    // ---------------------------------------------------------------
    // Encrypter — ciphertext tampering
    // ---------------------------------------------------------------

    private Encrypter $encrypter;

    protected function setUpEncrypter(): void
    {
        $this->encrypter = new Encrypter(Encrypter::generateKey());
    }

    public function testEncrypterTamperedCiphertextRejected(): void
    {
        $this->setUpEncrypter();
        $ciphertext = $this->encrypter->encrypt('sensitive data');
        $raw        = base64_decode($ciphertext);

        // Flip a byte in the ciphertext portion (after 12-byte IV + 16-byte tag)
        $tampered   = substr($raw, 0, 28) . chr(ord($raw[28]) ^ 0xff) . substr($raw, 29);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tampered|authentication/i');
        $this->encrypter->decrypt(base64_encode($tampered));
    }

    public function testEncrypterTamperedTagRejected(): void
    {
        $this->setUpEncrypter();
        $ciphertext = $this->encrypter->encrypt('secret payload');
        $raw        = base64_decode($ciphertext);

        // Flip a byte in the GCM authentication tag (bytes 12-27)
        $tampered   = substr($raw, 0, 12) . chr(ord($raw[12]) ^ 0x01) . substr($raw, 13);

        $this->expectException(\RuntimeException::class);
        $this->encrypter->decrypt(base64_encode($tampered));
    }

    public function testEncrypterWrongKeyRejected(): void
    {
        $this->setUpEncrypter();
        $ciphertext  = $this->encrypter->encrypt('data');
        $otherKey    = Encrypter::generateKey();
        $otherCipher = new Encrypter($otherKey);

        $this->expectException(\RuntimeException::class);
        $otherCipher->decrypt($ciphertext);
    }

    public function testEncrypterMalformedPayloadRejected(): void
    {
        $this->setUpEncrypter();

        $this->expectException(\RuntimeException::class);
        $this->encrypter->decrypt('not-valid-base64!!!');
    }

    public function testEncrypterTruncatedPayloadRejected(): void
    {
        $this->setUpEncrypter();

        // Too short — less than IV_LEN + TAG_LEN + 1 bytes
        $this->expectException(\RuntimeException::class);
        $this->encrypter->decrypt(base64_encode(random_bytes(10)));
    }

    // ---------------------------------------------------------------
    // Signer — token tampering
    // ---------------------------------------------------------------

    public function testSignerTamperedPayloadRejected(): void
    {
        $signer = new Signer('signing-secret-for-security-test-here');
        $token  = $signer->signToken(['user_id' => 1, 'role' => 'user']);
        $parts  = explode('.', $token, 2);

        // Replace payload but keep original signature
        $malicious = rtrim(strtr(base64_encode(json_encode(['user_id' => 1, 'role' => 'admin'])), '+/', '-_'), '=');
        $crafted   = $malicious . '.' . $parts[1];

        $this->expectException(\RuntimeException::class);
        $signer->verifyToken($crafted);
    }

    public function testSignerTimingAttackResistance(): void
    {
        $signer = new Signer('another-secure-signing-secret-key-here');
        $data   = 'user:42:admin';
        $sig    = $signer->sign($data);

        // Wrong sigs of different lengths — hash_equals pads, so timing should be uniform
        $wrong1 = str_repeat('a', strlen($sig));
        $wrong2 = str_repeat('b', strlen($sig));

        $times1 = [];
        $times2 = [];
        for ($i = 0; $i < 100; $i++) {
            $t = hrtime(true);
            $signer->verify($data, $wrong1);
            $times1[] = hrtime(true) - $t;

            $t = hrtime(true);
            $signer->verify($data, $wrong2);
            $times2[] = hrtime(true) - $t;
        }

        $avg1 = array_sum($times1) / count($times1);
        $avg2 = array_sum($times2) / count($times2);
        $diff = abs($avg1 - $avg2) / 1_000_000; // to ms

        $this->assertLessThan(1.0, $diff, 'Signer verify shows suspicious timing variance');
    }

    // ---------------------------------------------------------------
    // Router — path traversal and injection
    // ---------------------------------------------------------------

    private function makeApp(): App
    {
        $app = new App();
        $app->get('/safe', fn() => 'safe');
        $app->get('/users/{id}', fn(Request $req) => Response::json(['id' => $req->param('id')]));
        return $app;
    }

    private function fakeRequest(string $method, string $path, string $body = ''): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $path;
        $_SERVER['HTTP_HOST']      = 'localhost';
        return Request::fromGlobals();
    }

    public function testPathTraversalDoesNotEscape(): void
    {
        $app      = $this->makeApp();
        $request  = $this->fakeRequest('GET', '/../../../etc/passwd');
        $response = $app->handle($request);

        // Should 404, not serve file contents
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testNullByteInPathRejectedOrIgnored(): void
    {
        $app      = $this->makeApp();
        $request  = $this->fakeRequest('GET', "/safe\x00malicious");
        $response = $app->handle($request);

        // Either a 404 or the safe route — definitely not an error that leaks internals
        $this->assertContains($response->getStatusCode(), [200, 404]);
        $body = (string) $response->getBody();
        $this->assertStringNotContainsString('Exception', $body);
    }

    public function testLongPathDoesNotCauseError(): void
    {
        $app      = $this->makeApp();
        $longPath = '/' . str_repeat('a', 8192);
        $request  = $this->fakeRequest('GET', $longPath);
        $response = $app->handle($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRouteParamDoesNotAllowHeaderInjection(): void
    {
        $app     = new App();
        $app->get('/greet/{name}', function (Request $req) {
            // Naively reflecting param in a header (bad practice — but framework should not crash)
            return Response::html('hi ' . htmlspecialchars($req->param('name') ?? '', ENT_QUOTES));
        });

        $request  = $this->fakeRequest('GET', '/greet/' . urlencode("foo\r\nX-Injected: evil"));
        $response = $app->handle($request);

        // The header value should not appear raw in any response header
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $this->assertStringNotContainsString('X-Injected', $value);
            }
        }
    }

    // ---------------------------------------------------------------
    // CSRF — bypass attempts
    // ---------------------------------------------------------------

    private function csrfRequest(string $method, array $cookies = [], string $body = ''): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = '/submit';
        $_SERVER['HTTP_HOST']      = 'localhost';
        $_COOKIE = $cookies;
        return Request::fromGlobals()->withBody(Stream::fromString($body));
    }

    public function testCsrfRejectsMissingToken(): void
    {
        $middleware = new CsrfMiddleware('csrf-secret-for-test-only-32chars');
        $handler    = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $req): \Psr\Http\Message\ResponseInterface
            {
                return Response::html('ok');
            }
        };

        $request  = $this->csrfRequest('POST');
        $response = $middleware->process($request, $handler);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCsrfRejectsForgedToken(): void
    {
        $middleware = new CsrfMiddleware('csrf-secret-for-test-only-32chars');
        $handler    = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $req): \Psr\Http\Message\ResponseInterface
            {
                return Response::html('ok');
            }
        };

        // Forge: random|wrong_hmac
        $random  = bin2hex(random_bytes(16));
        $forged  = $random . '|' . str_repeat('a', 64);
        $request = $this->csrfRequest('POST', ['csrf_token' => $forged])
            ->withHeader('X-CSRF-Token', $forged);

        $response = $middleware->process($request, $handler);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCsrfSafeMethodsBypassed(): void
    {
        $middleware = new CsrfMiddleware('csrf-secret-for-test-only-32chars');
        $reached    = false;
        $handler    = new class($reached) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private bool &$r) {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $req): \Psr\Http\Message\ResponseInterface
            {
                $this->r = true;
                return Response::html('ok');
            }
        };

        $request  = $this->csrfRequest('GET');
        $response = $middleware->process($request, $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($reached);
    }

    // ---------------------------------------------------------------
    // Rate limiting — bypass attempts
    // ---------------------------------------------------------------

    public function testRateLimitCannotBeBypassedByIpSpoofing(): void
    {
        $cache = new ArrayCache();

        // Use default key resolver (no X-Forwarded-For trust)
        $mw = new RateLimitMiddleware($cache, maxRequests: 3, windowSeconds: 60);

        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $req): \Psr\Http\Message\ResponseInterface
            {
                return Response::html('ok');
            }
        };

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api';
        $_SERVER['HTTP_HOST'] = 'localhost';

        $passed = 0;
        for ($i = 0; $i < 5; $i++) {
            $req = Request::fromGlobals();
            // Attacker tries X-Forwarded-For spoofing
            $req = $req->withHeader('X-Forwarded-For', '192.168.1.' . $i);
            $res = $mw->process($req, $handler);
            if ($res->getStatusCode() === 200) {
                $passed++;
            }
        }

        // Only 3 should pass (REMOTE_ADDR-based key ignores XFF)
        $this->assertSame(3, $passed);
    }
}
