<?php

declare(strict_types=1);

namespace Lift\Container;

use Lift\Exception\ContainerException;
use Lift\Exception\ContainerNotFoundException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * PSR-11 dependency injection container with autowiring.
 *
 * Supports three registration modes:
 * - {@see bind()} — factory (fresh instance every resolution)
 * - {@see singleton()} — cached after first resolution
 * - {@see instance()} — pre-built object, always returned as-is
 *
 * Autowiring resolves constructor parameters by type using reflection. Any
 * class that is instantiable (concrete, no required unresolvable primitives)
 * can be created without explicit registration.
 *
 * ```php
 * $container = new Container();
 * $container->bind(LoggerInterface::class, FileLogger::class);
 * $container->singleton(Database::class, fn() => new Database($_ENV['DSN']));
 *
 * $logger = $container->get(LoggerInterface::class); // autowired
 * ```
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable(self, array<string,mixed>): mixed> */
    private array $bindings = [];
    /** @var array<string, mixed> */
    private array $singletons = [];
    /** @var array<string, true> */
    private array $singletonKeys = [];
    /** @var array<string, true> Cycle detection */
    private array $resolving = [];

    /**
     * Process-level ReflectionClass cache shared across all Container instances.
     *
     * Reflection objects are expensive to construct; caching them at the static
     * level means each class is reflected at most once per PHP process/request.
     *
     * @var array<class-string, ReflectionClass<object>>
     */
    private static array $reflectionCache = [];

    // -----------------------------------------------------------------
    // Registration
    // -----------------------------------------------------------------

    /** Bind an abstract to a factory or a class name. Fresh instance per call. */
    public function bind(string $abstract, callable|string $concrete): void
    {
        if (is_string($concrete)) {
            $this->bindings[$abstract] = fn(self $c) => $c->make($concrete);
        } else {
            $this->bindings[$abstract] = $concrete;
        }
        unset($this->singletons[$abstract]);
    }

    /** Like bind(), but the resolved instance is cached and reused. */
    public function singleton(string $abstract, callable|string|null $concrete = null): void
    {
        $this->singletonKeys[$abstract] = true;
        if ($concrete !== null) {
            $this->bind($abstract, $concrete);
        }
    }

    /** Register an already-constructed instance as a singleton. */
    public function instance(string $abstract, mixed $instance): void
    {
        $this->singletons[$abstract]    = $instance;
        $this->singletonKeys[$abstract] = true;
    }

    // -----------------------------------------------------------------
    // PSR-11 ContainerInterface
    // -----------------------------------------------------------------

    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    public function has(string $id): bool
    {
        if (isset($this->bindings[$id]) || isset($this->singletons[$id])) {
            return true;
        }
        if (!class_exists($id)) {
            return false;
        }
        $ref = self::$reflectionCache[$id] ??= new ReflectionClass($id);
        return $ref->isInstantiable();
    }

    // -----------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------

    /**
     * Resolve an abstract to a concrete instance.
     *
     * @param array<string, mixed> $overrides Named constructor arguments to inject directly.
     */
    public function make(string $abstract, array $overrides = []): mixed
    {
        if (isset($this->singletons[$abstract])) {
            return $this->singletons[$abstract];
        }

        if (isset($this->resolving[$abstract])) {
            throw new ContainerException("Circular dependency detected while resolving [{$abstract}]");
        }

        $this->resolving[$abstract] = true;

        try {
            $instance = isset($this->bindings[$abstract])
                ? ($this->bindings[$abstract])($this, $overrides)
                : $this->build($abstract, $overrides);
        } finally {
            unset($this->resolving[$abstract]);
        }

        if (isset($this->singletonKeys[$abstract])) {
            $this->singletons[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * Instantiate a class, resolving constructor dependencies via reflection.
     *
     * @param array<string, mixed> $overrides
     */
    private function build(string $class, array $overrides): mixed
    {
        if (!class_exists($class)) {
            throw new ContainerNotFoundException("No binding found for [{$class}]");
        }

        $reflection = self::$reflectionCache[$class] ??= new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException("[{$class}] is not instantiable (abstract class or interface)");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        return $reflection->newInstanceArgs(
            $this->resolveParameters($constructor->getParameters(), $overrides)
        );
    }

    /**
     * Resolve an array of ReflectionParameters to concrete values.
     * Matches by type (including overrides), then by parameter name, then autowires from container.
     *
     * @param ReflectionParameter[]    $params
     * @param array<string, mixed>     $overrides
     * @return mixed[]
     */
    public function resolveParameters(array $params, array $overrides = []): array
    {
        $args = [];

        // Pre-index override objects by every class/interface they satisfy,
        // so the inner loop below becomes a single O(1) lookup instead of
        // an O(overrides) instanceof scan for each parameter.
        $overridesByType = [];
        foreach ($overrides as $override) {
            if (!is_object($override)) {
                continue;
            }
            $overridesByType[get_class($override)] = $override;
            foreach (class_parents($override) as $parent) {
                $overridesByType[$parent] ??= $override;
            }
            foreach (class_implements($override) as $iface) {
                $overridesByType[$iface] ??= $override;
            }
        }

        foreach ($params as $param) {
            $name = $param->getName();
            $type = $param->getType();

            // 1. Explicit override by parameter name
            if (array_key_exists($name, $overrides)) {
                $args[] = $overrides[$name];
                continue;
            }

            // 2. Named type → check pre-built type map, then autowire
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();

                if (isset($overridesByType[$typeName])) {
                    $args[] = $overridesByType[$typeName];
                    continue;
                }

                // Autowire from container
                if ($this->has($typeName)) {
                    $args[] = $this->make($typeName);
                    continue;
                }

                if ($type->allowsNull()) {
                    $args[] = null;
                    continue;
                }

                if ($param->isOptional()) {
                    $args[] = $param->getDefaultValue();
                    continue;
                }

                throw new ContainerException(
                    "Cannot resolve parameter \${$name} of type [{$typeName}] in [{$param->getDeclaringClass()?->getName()}]"
                );
            }

            // 3. Variadic without type — collect remaining overrides
            if ($param->isVariadic()) {
                break;
            }

            // 4. Optional / has default
            if ($param->isOptional()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new ContainerException(
                "Cannot resolve untyped required parameter \${$name}"
            );
        }

        return $args;
    }

    /**
     * Call any callable, injecting its parameters from the container.
     *
     * @param array<string, mixed> $overrides
     */
    public function call(callable $callable, array $overrides = []): mixed
    {
        if (is_array($callable)) {
            [$object, $method] = $callable;
            $instance  = is_string($object) ? $this->make($object) : $object;
            $ref       = new \ReflectionMethod($instance, $method);
            $args      = $this->resolveParameters($ref->getParameters(), $overrides);
            return $instance->{$method}(...$args);
        }

        $ref  = new ReflectionFunction(\Closure::fromCallable($callable));
        $args = $this->resolveParameters($ref->getParameters(), $overrides);
        return $callable(...$args);
    }
}
