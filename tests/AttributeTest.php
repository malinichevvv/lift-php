<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\App;
use Lift\Attribute\Delete;
use Lift\Attribute\Get;
use Lift\Attribute\Group;
use Lift\Attribute\Middleware as MiddlewareAttr;
use Lift\Attribute\Post;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Http\Stream;
use Lift\Http\Uri;
use Lift\Routing\AttributeLoader;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class AttributeTest extends TestCase
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

    public function testGetAttributeRegistersRoute(): void
    {
        $this->app->loadControllers(AttrUserController::class);
        $res = $this->app->handle($this->request('GET', 'http://localhost/attr/users'));
        self::assertSame(200, $res->getStatusCode());
        self::assertStringContainsString('users', (string) $res->getBody());
    }

    public function testGroupPrefixApplied(): void
    {
        $this->app->loadControllers(AttrUserController::class);
        $res = $this->app->handle($this->request('POST', 'http://localhost/attr/users'));
        self::assertSame(201, $res->getStatusCode());
    }

    public function testNamedRouteFromAttribute(): void
    {
        $this->app->loadControllers(AttrUserController::class);
        $url = $this->app->url('attr.users.index');
        self::assertSame('/attr/users', $url);
    }

    public function testDeleteAttributeRegistersRoute(): void
    {
        $this->app->loadControllers(AttrUserController::class);
        $res = $this->app->handle($this->request('DELETE', 'http://localhost/attr/users/7'));
        self::assertSame(204, $res->getStatusCode());
    }

    public function testClassMiddlewareAppliedToAllMethods(): void
    {
        $this->app->loadControllers(AttrGuardedController::class);
        $res = $this->app->handle($this->request('GET', 'http://localhost/guarded/items'));
        self::assertSame('injected', $res->getHeaderLine('X-Attr-Guard'));
    }

    public function testMethodMiddlewareApplied(): void
    {
        $this->app->loadControllers(AttrGuardedController::class);
        $res = $this->app->handle($this->request('POST', 'http://localhost/guarded/items'));
        self::assertSame('method-mw', $res->getHeaderLine('X-Method-Guard'));
    }
}

// ---- Fixtures --------------------------------------------------------

#[Group('/attr')]
class AttrUserController
{
    #[Get('/users', name: 'attr.users.index')]
    public function index(): array
    {
        return ['users' => []];
    }

    #[Post('/users')]
    public function store(): Response
    {
        return Response::json(['created' => true], 201);
    }

    #[Delete('/users/{id}')]
    public function destroy(): Response
    {
        return Response::noContent();
    }
}

#[Group('/guarded')]
#[MiddlewareAttr(AttrHeaderMiddleware::class)]
class AttrGuardedController
{
    #[Get('/items')]
    public function index(): array
    {
        return [];
    }

    #[Post('/items')]
    #[MiddlewareAttr(AttrMethodMiddleware::class)]
    public function store(): Response
    {
        return Response::noContent();
    }
}

class AttrHeaderMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Attr-Guard', 'injected');
    }
}

class AttrMethodMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request)->withHeader('X-Method-Guard', 'method-mw');
    }
}
