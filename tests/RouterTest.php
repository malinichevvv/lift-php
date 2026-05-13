<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\App;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Http\Uri;
use Lift\Http\Stream;
use Lift\Exception\NotFoundException;
use Lift\Exception\MethodNotAllowedException;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = new App();
    }

    private function request(string $method, string $uri): Request
    {
        return new Request(
            method: $method,
            uri: new Uri($uri),
            body: Stream::empty(),
        );
    }

    public function testSimpleGetRoute(): void
    {
        $this->app->get('/hello', fn() => Response::text('world'));
        $res = $this->app->handle($this->request('GET', 'http://localhost/hello'));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame('world', (string) $res->getBody());
    }

    public function testRouteParameter(): void
    {
        $this->app->get('/users/{id}', fn(Request $req) => Response::json(['id' => $req->param('id')]));
        $res = $this->app->handle($this->request('GET', 'http://localhost/users/42'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('"id":"42"', (string) $res->getBody());
    }

    public function testRouteNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->app->container()->make(\Lift\Routing\Router::class)
            ->dispatch($this->request('GET', 'http://localhost/nowhere'));
    }

    public function testMethodNotAllowed(): void
    {
        $this->app->get('/only-get', fn() => Response::noContent());
        $this->expectException(MethodNotAllowedException::class);
        $this->app->container()->make(\Lift\Routing\Router::class)
            ->dispatch($this->request('POST', 'http://localhost/only-get'));
    }

    public function testRouteGroup(): void
    {
        $this->app->group('/api', function ($g) {
            $g->get('/ping', fn() => Response::json(['pong' => true]));
        });
        $res = $this->app->handle($this->request('GET', 'http://localhost/api/ping'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('pong', (string) $res->getBody());
    }

    public function testNamedRouteUrlGeneration(): void
    {
        $this->app->get('/users/{id}', fn() => Response::noContent())->name('users.show');
        $url = $this->app->url('users.show', ['id' => 99]);
        self::assertSame('/users/99', $url);
    }

    public function testArrayReturnedAsJson(): void
    {
        $this->app->get('/data', fn() => ['key' => 'value']);
        $res = $this->app->handle($this->request('GET', 'http://localhost/data'));
        self::assertStringContainsString('application/json', $res->getHeaderLine('Content-Type'));
    }

    public function testStringReturnedAsHtml(): void
    {
        $this->app->get('/page', fn() => '<h1>Hello</h1>');
        $res = $this->app->handle($this->request('GET', 'http://localhost/page'));
        self::assertStringContainsString('text/html', $res->getHeaderLine('Content-Type'));
    }

    public function testDiInjectionInHandler(): void
    {
        $this->app->singleton(FakeRepo::class, fn() => new FakeRepo());
        $this->app->get('/items', fn(FakeRepo $repo) => Response::json($repo->all()));
        $res = $this->app->handle($this->request('GET', 'http://localhost/items'));
        self::assertSame(200, $res->getStatusCode());
    }

    public function testCustomConstraint(): void
    {
        $this->app->get('/posts/{id:\d+}', fn(Request $req) => Response::json(['id' => $req->param('id')]));
        $res = $this->app->handle($this->request('GET', 'http://localhost/posts/123'));
        self::assertSame(200, $res->getStatusCode());
    }

    public function testConstraintRejectsNonMatch(): void
    {
        $this->app->get('/posts/{id:\d+}', fn() => Response::noContent());
        $this->expectException(NotFoundException::class);
        $this->app->container()->make(\Lift\Routing\Router::class)
            ->dispatch($this->request('GET', 'http://localhost/posts/abc'));
    }

    public function testErrorHandlerCatchesExceptions(): void
    {
        $this->app->get('/boom', fn() => throw new \RuntimeException('oops'));
        $this->app->onError(fn(\Throwable $e) => Response::json(['msg' => $e->getMessage()], 500));
        $res = $this->app->handle($this->request('GET', 'http://localhost/boom'));
        self::assertSame(500, $res->getStatusCode());
    }

    public function testInvokableController(): void
    {
        $this->app->get('/invoke', InvokableController::class);
        $res = $this->app->handle($this->request('GET', 'http://localhost/invoke'));
        self::assertSame('invoked', (string) $res->getBody());
    }
}

// ---- Fixtures --------------------------------------------------------

class FakeRepo
{
    public function all(): array { return [['id' => 1]]; }
}

class InvokableController
{
    public function __invoke(): Response
    {
        return Response::text('invoked');
    }
}
