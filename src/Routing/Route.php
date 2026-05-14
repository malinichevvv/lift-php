<?php

declare(strict_types=1);

namespace Lift\Routing;

use Psr\Http\Server\MiddlewareInterface;

final class Route
{
    private ?string $name = null;
    /** @var array<MiddlewareInterface|string> */
    private array $middleware = [];

    /** Compiled regex pattern (built once on first match attempt). */
    private ?string $compiledPattern = null;
    /** @var list<string> Capture-group names in declaration order. */
    private array $paramNames = [];

    /** @param string[] $methods Already uppercased HTTP verbs. */
    public function __construct(
        private readonly array $methods,
        private readonly string $path,
        private readonly mixed $handler,
    ) {}

    // -----------------------------------------------------------------
    // Fluent configuration
    // -----------------------------------------------------------------

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function middleware(MiddlewareInterface|string ...$middleware): self
    {
        array_push($this->middleware, ...$middleware);
        return $this;
    }

    // -----------------------------------------------------------------
    // Cache support
    // -----------------------------------------------------------------

    /**
     * Inject a pre-compiled pattern (loaded from route cache) so the first
     * request never needs to run preg_replace_callback.
     *
     * @param list<string> $paramNames
     */
    public function setCompiled(string $pattern, array $paramNames): void
    {
        $this->compiledPattern = $pattern;
        $this->paramNames      = $paramNames;
    }

    /**
     * Export this route as a plain array suitable for PHP file caching.
     *
     * Returns null for closure-based handlers — closures cannot be serialised.
     *
     * @return array<string, mixed>|null
     */
    public function toCacheable(): ?array
    {
        $handler = $this->handler;

        if ($handler instanceof \Closure) {
            return null;
        }
        if (is_array($handler) && isset($handler[0]) && $handler[0] instanceof \Closure) {
            return null;
        }

        $middleware = [];
        foreach ($this->middleware as $m) {
            if (is_string($m)) {
                $middleware[] = $m;
            }
        }

        return [
            'methods'    => $this->methods,
            'path'       => $this->path,
            'handler'    => $handler,
            'name'       => $this->name,
            'middleware' => $middleware,
            'pattern'    => $this->compiledPattern,
            'paramNames' => $this->paramNames,
        ];
    }

    /**
     * Reconstruct a Route from a cached array produced by {@see toCacheable()}.
     *
     * @param array<string, mixed> $data
     */
    public static function fromCacheable(array $data): self
    {
        $route = new self($data['methods'], $data['path'], $data['handler']);

        if ($data['name'] !== null) {
            $route->name($data['name']);
        }
        foreach ($data['middleware'] as $m) {
            $route->middleware($m);
        }
        if ($data['pattern'] !== null) {
            $route->setCompiled($data['pattern'], $data['paramNames']);
        }

        return $route;
    }

    // -----------------------------------------------------------------
    // Matching
    // -----------------------------------------------------------------

    /**
     * Returns an array of named route params on match, false otherwise.
     * Checks both method and path.
     *
     * @return array<string,string>|false
     */
    public function matches(string $method, string $path): array|false
    {
        if (!in_array(strtoupper($method), $this->methods, true)) {
            return false;
        }
        return $this->extractParams($path);
    }

    /**
     * Returns true if the path pattern matches, regardless of HTTP method.
     * Used internally for 405 detection.
     */
    public function pathMatches(string $path): bool
    {
        return $this->extractParams($path) !== false;
    }

    /**
     * @return array<string,string>|false
     */
    private function extractParams(string $path): array|false
    {
        if ($this->compiledPattern === null) {
            $names   = [];
            $pattern = preg_replace_callback(
                '/\{(\w+)(?::([^}]+))?\}/',
                static function (array $m) use (&$names): string {
                    $names[] = $m[1];
                    return '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
                },
                $this->path,
            );
            $this->compiledPattern = '@^' . $pattern . '$@u';
            $this->paramNames      = $names;
        }

        if (preg_match($this->compiledPattern, $path, $matches) !== 1) {
            return false;
        }

        if ($this->paramNames === []) {
            return [];
        }

        $params = [];
        foreach ($this->paramNames as $name) {
            $params[$name] = $matches[$name];
        }
        return $params;
    }

    // -----------------------------------------------------------------
    // Accessors
    // -----------------------------------------------------------------

    public function getMethods(): array  { return $this->methods; }
    public function getPath(): string    { return $this->path; }
    public function getHandler(): mixed  { return $this->handler; }
    public function getName(): ?string   { return $this->name; }

    /** @return array<MiddlewareInterface|string> */
    public function getMiddleware(): array { return $this->middleware; }
}
