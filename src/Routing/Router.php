<?php

declare(strict_types=1);

namespace Lift\Routing;

use Lift\Container\Container;
use Lift\Exception\MethodNotAllowedException;
use Lift\Exception\NotFoundException;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Pipeline\Pipeline;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use ReflectionFunction;
use ReflectionMethod;

/**
 * HTTP router — matches incoming requests to registered route handlers.
 *
 * Supports:
 * - Static and parameterised paths: `/users/{id:\d+}`
 * - Route groups with shared prefix and middleware
 * - Named routes for URL generation
 * - Any callable, `[Controller::class, 'method']`, or invokable class as handler
 * - Automatic 405 detection when the path exists but the method does not
 * - Handler return-value normalisation (array→JSON, string→HTML, null→204)
 */
final class Router
{
    /** @var Route[] All registered routes (dynamic only after registration). */
    private array $routes = [];

    /**
     * Static fast-path: method → path → Route.
     *
     * Routes with no `{param}` placeholders are indexed here for O(1) dispatch.
     * Dynamic routes remain in $routes for the linear regex scan.
     *
     * @var array<string, array<string, Route>>
     */
    private array $static = [];

    public function __construct(private readonly Container $container) {}

    // -----------------------------------------------------------------
    // Route registration
    // -----------------------------------------------------------------

    /**
     * @param string|string[] $methods
     */
    public function add(string|array $methods, string $path, mixed $handler): Route
    {
        $normalised = '/' . ltrim($path, '/');
        $route = new Route(
            methods: array_map('strtoupper', (array) $methods),
            path: $normalised,
            handler: $handler,
        );

        if (!str_contains($normalised, '{')) {
            foreach ($route->getMethods() as $m) {
                $this->static[$m][$normalised] = $route;
            }
        } else {
            $this->routes[] = $route;
        }

        return $route;
    }

    public function group(string $prefix, callable $callback): RouteGroup
    {
        return new RouteGroup($prefix, $this, $callback);
    }

    // -----------------------------------------------------------------
    // URL generation
    // -----------------------------------------------------------------

    public function url(string $name, array $params = []): string
    {
        $all = $this->allRoutes();
        foreach ($all as $route) {
            if ($route->getName() === $name) {
                $url = $route->getPath();
                foreach ($params as $key => $value) {
                    $url = (string) preg_replace('/\{' . $key . '(?::[^}]+)?\}/', (string) $value, $url);
                }
                return $url;
            }
        }
        throw new \RuntimeException("Named route [{$name}] not found");
    }

    /**
     * Return all registered routes (without duplicates).
     *
     * @return Route[]
     */
    public function getRoutes(): array
    {
        $routes = [];
        $seen   = [];
        foreach ($this->static as $paths) {
            foreach ($paths as $route) {
                $id = spl_object_id($route);
                if (!isset($seen[$id])) {
                    $seen[$id]  = true;
                    $routes[]   = $route;
                }
            }
        }
        foreach ($this->routes as $route) {
            $routes[] = $route;
        }
        return $routes;
    }

    /** Iterate all routes (static index + dynamic list) without duplicates. */
    private function allRoutes(): \Generator
    {
        $seen = [];
        foreach ($this->static as $paths) {
            foreach ($paths as $route) {
                $id = spl_object_id($route);
                if (!isset($seen[$id])) {
                    $seen[$id] = true;
                    yield $route;
                }
            }
        }
        foreach ($this->routes as $route) {
            yield $route;
        }
    }

    // -----------------------------------------------------------------
    // Dispatch
    // -----------------------------------------------------------------

    /**
     * @param array<MiddlewareInterface|string> $globalMiddleware
     */
    public function dispatch(Request $request, array $globalMiddleware = []): Response
    {
        $method = $request->getMethod();
        $path   = '/' . ltrim($request->getUri()->getPath(), '/');

        // O(1) static fast-path — no regex needed for exact-path routes.
        if (isset($this->static[$method][$path])) {
            $route   = $this->static[$method][$path];
            $request = $request->withRouteParams([]);
            return $this->runThroughPipeline($route, $request, $globalMiddleware);
        }

        $matched          = null;
        $methodNotAllowed = false;

        // Check static map for 405 (path exists for a different method).
        foreach ($this->static as $staticMethod => $paths) {
            if (isset($paths[$path]) && $staticMethod !== $method) {
                $methodNotAllowed = true;
                break;
            }
        }

        foreach ($this->routes as $route) {
            $params = $route->matches($method, $path);
            if ($params !== false) {
                $matched = [$route, $params];
                break;
            }
            if (!$methodNotAllowed && $route->pathMatches($path)) {
                $methodNotAllowed = true;
            }
        }

        if ($matched === null) {
            throw $methodNotAllowed
                ? new MethodNotAllowedException("Method {$method} not allowed for {$path}")
                : new NotFoundException("No route matched: {$path}");
        }

        [$route, $params] = $matched;
        $request = $request->withRouteParams($params);

        return $this->runThroughPipeline($route, $request, $globalMiddleware);
    }

    /** @param array<MiddlewareInterface|string> $globalMiddleware */
    private function runThroughPipeline(Route $route, Request $request, array $globalMiddleware): Response
    {
        $middleware = [...$globalMiddleware, ...$route->getMiddleware()];
        $pipeline   = new Pipeline($this->container);
        return $pipeline->run(
            $request,
            $middleware,
            fn(ServerRequestInterface $req): Response => $this->callHandler(
                $route->getHandler(),
                $req instanceof Request ? $req : $request,
            ),
        );
    }

    // -----------------------------------------------------------------
    // Handler invocation
    // -----------------------------------------------------------------

    /**
     * Invoke a route handler.
     *
     * @param mixed $handler
     * @param Request $request
     * @return Response
     * @throws \ReflectionException
     */
    private function callHandler(mixed $handler, Request $request): Response
    {
        $overrides = ['request' => $request, 'req' => $request];

        if (is_array($handler) && count($handler) === 2) {
            [$class, $method] = $handler;
            $instance = is_object($class) ? $class : $this->container->make($class);
            $ref      = new ReflectionMethod($instance, $method);
            $args     = $this->container->resolveParameters($ref->getParameters(), $overrides);
            $result   = $instance->{$method}(...$args);
        } elseif (is_callable($handler)) {
            $ref    = new ReflectionFunction(\Closure::fromCallable($handler));
            $args   = $this->container->resolveParameters($ref->getParameters(), $overrides);
            $result = $handler(...$args);
        } elseif (is_string($handler) && class_exists($handler)) {
            // Invokable class
            $instance = $this->container->make($handler);
            $ref      = new ReflectionMethod($instance, '__invoke');
            $args     = $this->container->resolveParameters($ref->getParameters(), $overrides);
            $result   = $instance(...$args);
        } else {
            throw new \InvalidArgumentException('Invalid route handler');
        }

        return $this->normalizeResponse($result);
    }

    /**
     * Normalize a handler result to a Response.
     *
     * @param mixed $result
     * @return Response
     */
    private function normalizeResponse(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response         => $result,
            is_array($result), is_object($result) => Response::json($result),
            is_string($result)                  => Response::html($result),
            $result === null                    => Response::noContent(),
            default                             => Response::text((string) $result),
        };
    }
}
