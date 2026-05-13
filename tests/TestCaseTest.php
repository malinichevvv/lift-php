<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\App;
use Lift\Exception\BadRequestException;
use Lift\Exception\ConflictException;
use Lift\Exception\ForbiddenException;
use Lift\Exception\TooManyRequestsException;
use Lift\Exception\UnauthorizedException;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Testing\TestCase;
use Lift\Testing\TestResponse;

/**
 * Tests for Lift\Testing\TestCase and Lift\Testing\TestResponse.
 */
final class TestCaseTest extends TestCase
{
    protected function createApp(): App
    {
        $app = new App();

        $app->get('/hello', fn() => Response::json(['greeting' => 'hello']));

        $app->get('/echo', function (Request $req) {
            return Response::json(['q' => $req->query('q')]);
        });

        $app->post('/items', function (Request $req) {
            $data = $req->json();
            if (empty($data['name'])) {
                return Response::json(['error' => 'name required'], 422);
            }
            return Response::json(['name' => $data['name']], 201);
        });

        $app->put('/items/1', function (Request $req) {
            return Response::json(['updated' => true]);
        });

        $app->patch('/items/1', function (Request $req) {
            return Response::json(['patched' => true]);
        });

        $app->delete('/items/1', fn() => Response::noContent());

        $app->get('/html', fn() => Response::html('<h1>Hello World</h1>'));

        $app->get('/redirect', fn() => Response::redirect('/hello'));

        // HTTP exception routes
        $app->get('/bad-request', fn() => throw new BadRequestException('bad input'));
        $app->get('/unauthorized', fn() => throw new UnauthorizedException());
        $app->get('/forbidden',    fn() => throw new ForbiddenException());
        $app->get('/conflict',     fn() => throw new ConflictException('duplicate'));
        $app->get('/rate-limit',   fn() => throw new TooManyRequestsException('slow down', 60));

        return $app;
    }

    // -----------------------------------------------------------------
    // HTTP method helpers
    // -----------------------------------------------------------------

    public function testGet(): void
    {
        $this->get('/hello')->assertOk()->assertJson(['greeting' => 'hello']);
    }

    public function testGetWithQueryString(): void
    {
        $this->get('/echo?q=test')->assertOk()->assertJson(['q' => 'test']);
    }

    public function testPost(): void
    {
        $this->post('/items', ['name' => 'Widget'])
             ->assertCreated()
             ->assertJson(['name' => 'Widget']);
    }

    public function testPostValidationFails(): void
    {
        $this->post('/items', [])->assertUnprocessable();
    }

    public function testPut(): void
    {
        $this->put('/items/1', ['x' => 1])->assertOk()->assertJson(['updated' => true]);
    }

    public function testPatch(): void
    {
        $this->patch('/items/1', ['y' => 2])->assertOk()->assertJson(['patched' => true]);
    }

    public function testDelete(): void
    {
        $this->delete('/items/1')->assertNoContent();
    }

    // -----------------------------------------------------------------
    // TestResponse assertions
    // -----------------------------------------------------------------

    public function testAssertStatus(): void
    {
        $this->get('/hello')->assertStatus(200);
        $this->get('/missing-route')->assertStatus(404);
    }

    public function testAssertSee(): void
    {
        $this->get('/html')->assertSee('<h1>Hello World</h1>');
    }

    public function testAssertDontSee(): void
    {
        $this->get('/html')->assertDontSee('Goodbye');
    }

    public function testAssertRedirect(): void
    {
        $this->get('/redirect')->assertRedirect('/hello');
    }

    public function testAssertHeader(): void
    {
        $this->get('/hello')
             ->assertHeader('Content-Type')
             ->assertContentType('application/json');
    }

    public function testAssertJsonPath(): void
    {
        $this->get('/hello')->assertJsonPath('greeting', 'hello');
    }

    public function testAssertJsonHas(): void
    {
        $this->get('/hello')->assertJsonHas('greeting');
    }

    public function testAssertJsonCount(): void
    {
        $this->get('/hello')->assertJsonCount(1);
    }

    public function testBodyAndJsonAccessors(): void
    {
        $r = $this->get('/hello');
        $this->assertStringContainsString('hello', $r->body());
        $this->assertSame(['greeting' => 'hello'], $r->json());
    }

    // -----------------------------------------------------------------
    // Named HTTP exceptions
    // -----------------------------------------------------------------

    public function testBadRequestException(): void
    {
        $this->get('/bad-request')->assertStatus(400)->assertJson(['error' => 'bad input']);
    }

    public function testUnauthorizedException(): void
    {
        $this->get('/unauthorized')->assertUnauthorized();
    }

    public function testForbiddenException(): void
    {
        $this->get('/forbidden')->assertForbidden();
    }

    public function testConflictException(): void
    {
        $this->get('/conflict')->assertStatus(409)->assertJson(['error' => 'duplicate']);
    }

    public function testTooManyRequestsException(): void
    {
        $r = $this->get('/rate-limit');
        $r->assertStatus(429)->assertHeader('Retry-After', '60');
    }

    // -----------------------------------------------------------------
    // Response cookie helpers
    // -----------------------------------------------------------------

    public function testWithCookie(): void
    {
        $response = Response::html('ok')->withCookie('token', 'abc123');
        $setCookie = $response->getHeader('Set-Cookie')[0] ?? '';
        $this->assertStringContainsString('token=abc123', $setCookie);
        $this->assertStringContainsString('HttpOnly', $setCookie);
        $this->assertStringContainsString('SameSite=Lax', $setCookie);
    }

    public function testWithCookieOptions(): void
    {
        $response = Response::html('ok')->withCookie('prefs', 'dark', [
            'max_age'   => 86400,
            'path'      => '/app',
            'secure'    => true,
            'http_only' => false,
            'same_site' => 'Strict',
        ]);
        $header = $response->getHeader('Set-Cookie')[0] ?? '';
        $this->assertStringContainsString('prefs=dark', $header);
        $this->assertStringContainsString('Max-Age=86400', $header);
        $this->assertStringContainsString('Path=/app', $header);
        $this->assertStringContainsString('Secure', $header);
        $this->assertStringContainsString('SameSite=Strict', $header);
        $this->assertStringNotContainsString('HttpOnly', $header);
    }

    public function testWithoutCookie(): void
    {
        $response = Response::html('ok')->withoutCookie('token');
        $header = $response->getHeader('Set-Cookie')[0] ?? '';
        $this->assertStringContainsString('token=', $header);
        $this->assertStringContainsString('Max-Age=0', $header);
    }

    public function testMultipleCookies(): void
    {
        $response = Response::html('ok')
            ->withCookie('a', '1')
            ->withCookie('b', '2');
        $this->assertCount(2, $response->getHeader('Set-Cookie'));
    }
}
