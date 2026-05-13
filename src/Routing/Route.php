<?php

declare(strict_types=1);

namespace Lift\Routing;

use Psr\Http\Server\MiddlewareInterface;

final class Route
{
    private ?string $name = null;
    /** @var array<MiddlewareInterface|string> */
    private array $middleware = [];

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
        $pattern = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?\}/',
            static fn(array $m) => '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')',
            $this->path,
        );

        if (@preg_match('@^' . $pattern . '$@u', $path, $matches) !== 1) {
            return false;
        }

        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
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
