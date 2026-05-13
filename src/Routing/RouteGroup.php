<?php

declare(strict_types=1);

namespace Lift\Routing;

use Psr\Http\Server\MiddlewareInterface;

final class RouteGroup
{
    /** @var array<MiddlewareInterface|string> */
    private array $middleware = [];
    /** @var Route[] Routes registered within this group */
    private array $routes = [];

    public function __construct(
        private readonly string $prefix,
        private readonly Router $router,
        callable $callback,
    ) {
        $callback($this);
    }

    public function get(string $path, mixed $handler): Route
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): Route
    {
        return $this->add('POST', $path, $handler);
    }

    public function put(string $path, mixed $handler): Route
    {
        return $this->add('PUT', $path, $handler);
    }

    public function patch(string $path, mixed $handler): Route
    {
        return $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, mixed $handler): Route
    {
        return $this->add('DELETE', $path, $handler);
    }

    /** Attach middleware to every route in this group. */
    public function middleware(MiddlewareInterface|string ...$middleware): self
    {
        array_push($this->middleware, ...$middleware);
        foreach ($this->routes as $route) {
            $route->middleware(...$middleware);
        }
        return $this;
    }

    /** Nest another group under this group's prefix. */
    public function group(string $prefix, callable $callback): self
    {
        $sub = new self($this->prefix . $prefix, $this->router, $callback);
        $this->routes = array_merge($this->routes, $sub->routes);
        return $sub;
    }

    public function getPrefix(): string { return $this->prefix; }

    private function add(string $method, string $path, mixed $handler): Route
    {
        $route = $this->router->add($method, $this->prefix . $path, $handler);
        $route->middleware(...$this->middleware);
        $this->routes[] = $route;
        return $route;
    }
}
