<?php

declare(strict_types=1);

namespace Lift\Routing;

use Lift\Attribute\Delete;
use Lift\Attribute\Get;
use Lift\Attribute\Group;
use Lift\Attribute\HttpAttributeInterface;
use Lift\Attribute\Middleware as MiddlewareAttr;
use Lift\Attribute\Patch;
use Lift\Attribute\Post;
use Lift\Attribute\Put;
use Lift\Attribute\Route;
use Lift\Container\Container;
use ReflectionClass;
use ReflectionMethod;

/**
 * Scans PHP 8.1 attributes on controller classes and registers routes automatically.
 *
 * Supported attributes:
 * - {@see Route}  — generic verb
 * - {@see Get}, {@see Post}, {@see Put}, {@see Patch}, {@see Delete} — verb shortcuts
 * - {@see Group}  — class-level URL prefix
 * - {@see MiddlewareAttr} — class or method-level middleware (repeatable)
 *
 * Usage:
 * ```php
 * $loader = new AttributeLoader($router, $container);
 * $loader->load(UserController::class);
 * ```
 *
 * Or via the App facade:
 * ```php
 * $app->loadControllers(UserController::class, PostController::class);
 * ```
 */
final class AttributeLoader
{
    /**
     * Attribute class names that define an HTTP route on a method.
     *
     * @var list<class-string<HttpAttributeInterface>>
     */
    private const array ROUTE_ATTRIBUTES = [
        Route::class,
        Get::class,
        Post::class,
        Put::class,
        Patch::class,
        Delete::class,
    ];

    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
    ) {}

    /**
     * Scan a single controller class and register all attributed routes.
     *
     * @param class-string $controller Fully-qualified class name.
     * @throws \ReflectionException If the class does not exist.
     */
    public function load(string $controller): void
    {
        $ref = new ReflectionClass($controller);

        $prefix          = $this->resolvePrefix($ref);
        $classMiddleware = $this->resolveMiddleware($ref);

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->isDestructor() || $method->isStatic()) {
                continue;
            }
            $this->loadMethod($controller, $method, $prefix, $classMiddleware);
        }
    }

    /**
     * Scan and register multiple controller classes at once.
     *
     * @param list<class-string> $controllers
     * @throws \ReflectionException
     */
    public function loadMany(array $controllers): void
    {
        foreach ($controllers as $controller) {
            $this->load($controller);
        }
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * @param class-string            $controller
     * @param list<class-string>      $classMiddleware
     */
    private function loadMethod(
        string $controller,
        ReflectionMethod $method,
        string $prefix,
        array $classMiddleware,
    ): void {
        $methodMiddleware = $this->resolveMiddleware($method);
        $allMiddleware    = [...$classMiddleware, ...$methodMiddleware];

        foreach (self::ROUTE_ATTRIBUTES as $attrClass) {
            foreach ($method->getAttributes($attrClass) as $attr) {
                /** @var HttpAttributeInterface $routeAttr */
                $routeAttr = $attr->newInstance();

                $route = $this->router->add(
                    methods: $routeAttr->getMethod(),
                    path: $prefix . $routeAttr->getPath(),
                    handler: [$controller, $method->getName()],
                );

                if ($routeAttr->getName() !== null) {
                    $route->name($routeAttr->getName());
                }

                if ($allMiddleware !== []) {
                    $route->middleware(...$allMiddleware);
                }
            }
        }
    }

    /**
     * Extract the URL prefix defined by a {@see Group} attribute on the class.
     */
    private function resolvePrefix(ReflectionClass $ref): string
    {
        $attrs = $ref->getAttributes(Group::class);
        if ($attrs === []) {
            return '';
        }
        return $attrs[0]->newInstance()->prefix;
    }

    /**
     * Collect all middleware class names from {@see MiddlewareAttr} attributes
     * on a class or method (the attribute is repeatable).
     *
     * @param ReflectionClass|ReflectionMethod $reflector
     * @return list<class-string>
     */
    private function resolveMiddleware(ReflectionClass|ReflectionMethod $reflector): array
    {
        $middleware = [];
        foreach ($reflector->getAttributes(MiddlewareAttr::class) as $attr) {
            foreach ($attr->newInstance()->toArray() as $class) {
                $middleware[] = $class;
            }
        }
        return $middleware;
    }
}
