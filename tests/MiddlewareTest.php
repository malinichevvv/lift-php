<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\App;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Http\Uri;
use Lift\Http\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MiddlewareTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = new App();
    }

    private function request(string $method, string $uri): Request
    {
        return new Request($method, new Uri($uri), body: Stream::empty());
    }

    public function testGlobalMiddlewareRuns(): void
    {
        $this->app->use(new AddHeaderMiddleware('X-Global', 'yes'));
        $this->app->get('/test', fn() => Response::noContent());

        $res = $this->app->handle($this->request('GET', 'http://localhost/test'));
        self::assertSame('yes', $res->getHeaderLine('X-Global'));
    }

    public function testRouteMiddlewareRuns(): void
    {
        $this->app->get('/guarded', fn() => Response::text('secret'))
            ->middleware(new AddHeaderMiddleware('X-Route', 'hit'));

        $res = $this->app->handle($this->request('GET', 'http://localhost/guarded'));
        self::assertSame('hit', $res->getHeaderLine('X-Route'));
    }

    public function testGroupMiddlewareRuns(): void
    {
        $this->app->group('/admin', function ($g) {
            $g->get('/dashboard', fn() => Response::text('admin'));
        })->middleware(new AddHeaderMiddleware('X-Group', 'admin'));

        $res = $this->app->handle($this->request('GET', 'http://localhost/admin/dashboard'));
        self::assertSame('admin', $res->getHeaderLine('X-Group'));
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $this->app->use(new BlockingMiddleware());
        $this->app->get('/blocked', fn() => Response::text('never reached'));

        $res = $this->app->handle($this->request('GET', 'http://localhost/blocked'));
        self::assertSame(403, $res->getStatusCode());
    }

    public function testMiddlewareCanModifyRequest(): void
    {
        $this->app->use(new RequestModifyingMiddleware());
        $this->app->get('/attr', fn(Request $req) => Response::text($req->getAttribute('injected', '')));

        $res = $this->app->handle($this->request('GET', 'http://localhost/attr'));
        self::assertSame('from-middleware', (string) $res->getBody());
    }
}

// ---- Fixtures --------------------------------------------------------

class AddHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $value,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader($this->name, $this->value);
    }
}

class BlockingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return Response::json(['error' => 'Forbidden'], 403);
    }
}

class RequestModifyingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request->withAttribute('injected', 'from-middleware'));
    }
}
