<?php

declare(strict_types=1);

namespace Lift\View;

use Lift\Http\Response;
use Lift\Translation\Translator;

/**
 * View factory — resolves, compiles, and renders PHP template files.
 *
 * ViewFactory is the entry point for the view layer. It resolves dot-notation
 * view names to file paths, merges shared variables, delegates rendering to
 * {@see ViewRenderer}, and can return either a string or an HTTP {@see Response}.
 *
 * Register with the application:
 * ```php
 * $app->views(__DIR__ . '/views');
 * ```
 *
 * Render from a route handler:
 * ```php
 * $app->get('/about', fn() => $app->view('pages.about', ['title' => 'About']));
 * ```
 *
 * Or inject directly:
 * ```php
 * function show(ViewFactory $views): Response {
 *     return $views->response('users.show', ['user' => $user]);
 * }
 * ```
 */
final class ViewFactory
{
    /** @var array<string, mixed> Variables shared across every rendered view. */
    private array $shared = [];

    private ?Translator $translator = null;

    /**
     * @param string $path      Absolute path to the views root directory.
     * @param string $extension File extension used when resolving view names (without leading dot).
     * @param string $assetBase URL prefix prepended by {@see asset()} to relative paths.
     */
    public function __construct(
        private string $path,
        private string $extension = 'php',
        private string $assetBase = '/assets',
    ) {}

    /** Return the configured views root directory. */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Set a translator that will be used for `$view->t()` and `$view->tc()`
     * inside every template rendered by this factory.
     */
    public function setTranslator(Translator $translator): self
    {
        $this->translator = $translator;
        return $this;
    }

    /**
     * Share one or more variables with every view rendered by this factory.
     *
     * @param string|array<string, mixed> $key   Variable name or an associative array.
     * @param mixed                       $value Value when $key is a string.
     */
    public function share(string|array $key, mixed $value = null): self
    {
        if (is_array($key)) {
            $this->shared = array_replace($this->shared, $key);
            return $this;
        }

        $this->shared[$key] = $value;
        return $this;
    }

    /**
     * Render a view to an HTML string.
     *
     * Dot-notation view names are mapped to file paths:
     * `'pages.about'` → `{views_root}/pages/about.php`.
     *
     * @param string               $view   Dot-notation view name.
     * @param array<string, mixed> $data   Variables available inside the template.
     * @param string|null          $layout Optional layout to wrap the view.
     */
    public function render(string $view, array $data = [], ?string $layout = null): string
    {
        return (new ViewRenderer($this, array_replace($this->shared, $data), $layout, $this->translator))->render($view);
    }

    /**
     * Render a view and wrap it in an HTTP response.
     *
     * @param string $view
     * @param array<string, mixed> $data
     * @param string|null $layout
     * @param int $status HTTP status code.
     * @return Response
     */
    public function response(string $view, array $data = [], ?string $layout = null, int $status = 200): Response
    {
        return Response::html($this->render($view, $data, $layout), $status);
    }

    /**
     * Return true when a view file exists on disk.
     *
     * Does not throw; safe to use for conditional rendering.
     */
    public function exists(string $view): bool
    {
        return is_file($this->resolve($view));
    }

    /**
     * Resolve a dot-notation view name to its absolute file path.
     *
     * @throws \InvalidArgumentException When the resolved file does not exist.
     */
    public function resolve(string $view): string
    {
        $file = str_replace('.', '/', trim($view, '/'));
        $path = rtrim($this->path, '/') . '/' . $file . '.' . ltrim($this->extension, '.');
        if (!is_file($path)) {
            throw new \InvalidArgumentException("View [{$view}] was not found at [{$path}]");
        }
        return $path;
    }

    /**
     * Build a URL for a static asset.
     *
     * Absolute URLs (`http://`, `https://`, `//`) and data URIs are returned
     * unchanged. All other paths are prefixed with the configured asset base.
     *
     * ```php
     * $view->asset('css/app.css') // → '/assets/css/app.css'
     * $view->asset('https://cdn.example.com/lib.js') // → unchanged
     * ```
     */
    public function asset(string $path): string
    {
        if (preg_match('#^(https?:)?//#', $path) || str_starts_with($path, 'data:')) {
            return $path;
        }

        return rtrim($this->assetBase, '/') . '/' . ltrim($path, '/');
    }
}
