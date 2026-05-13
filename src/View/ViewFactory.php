<?php

declare(strict_types=1);

namespace Lift\View;

use Lift\Http\Response;

final class ViewFactory
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(
        private string $path,
        private string $extension = 'php',
        private string $assetBase = '/assets',
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function share(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->shared = array_replace($this->shared, $key);
            return $this;
        }

        $this->shared[$key] = $value;
        return $this;
    }

    public function render(string $view, array $data = [], ?string $layout = null): string
    {
        return (new ViewRenderer($this, array_replace($this->shared, $data), $layout))->render($view);
    }

    public function response(string $view, array $data = [], ?string $layout = null, int $status = 200): Response
    {
        return Response::html($this->render($view, $data, $layout), $status);
    }

    public function exists(string $view): bool
    {
        return is_file($this->resolve($view));
    }

    public function resolve(string $view): string
    {
        $file = str_replace('.', '/', trim($view, '/'));
        $path = rtrim($this->path, '/') . '/' . $file . '.' . ltrim($this->extension, '.');
        if (!is_file($path)) {
            throw new \InvalidArgumentException("View [{$view}] was not found at [{$path}]");
        }
        return $path;
    }

    public function asset(string $path): string
    {
        if (preg_match('#^(https?:)?//#', $path) || str_starts_with($path, 'data:')) {
            return $path;
        }

        return rtrim($this->assetBase, '/') . '/' . ltrim($path, '/');
    }
}
