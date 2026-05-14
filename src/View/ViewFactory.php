<?php

declare(strict_types=1);

namespace Lift\View;

use Lift\Cache\CacheInterface;
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
    private ?CacheInterface $cache = null;

    /** Resolved canonical views root — computed once, reused in every resolve() call. */
    private readonly string $realBase;

    /**
     * @param string $path      Absolute path to the views root directory.
     * @param string $extension File extension used when resolving view names (without leading dot).
     * @param string $assetBase URL prefix prepended by {@see asset()} to relative paths.
     */
    public function __construct(
        private string $path,
        private string $extension = 'php',
        private string $assetBase = '/assets',
    ) {
        $real = realpath($this->path);
        $this->realBase = $real !== false ? $real : $this->path;
    }

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
     * Attach a cache driver used by {@see renderCached()} and {@see responseCached()}.
     *
     * ```php
     * $views->setCacheDriver($app->container()->make(CacheInterface::class));
     * ```
     */
    public function setCacheDriver(CacheInterface $cache): self
    {
        $this->cache = $cache;
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
     * Render a view, caching the result for subsequent requests.
     *
     * The cache key defaults to `"view:{$view}"` when omitted, which is suitable
     * for views that produce the same output regardless of `$data` (e.g. static
     * layouts). Pass an explicit key whenever the output depends on request
     * context.
     *
     * A configured cache driver is required; see {@see setCacheDriver()}.
     * When no driver is configured the method falls back to a plain render().
     *
     * ```php
     * // Cache a sidebar for 5 minutes
     * $html = $views->renderCached('partials.sidebar', [], 'sidebar-nav', 300);
     *
     * // Cache a page that differs per user
     * $html = $views->renderCached('users.profile', $data, "profile-{$userId}", 60);
     * ```
     *
     * @param string               $view      Dot-notation view name.
     * @param array<string, mixed> $data      Variables available in the template.
     * @param string               $cacheKey  Explicit cache key.  Defaults to `view:{name}`.
     * @param int                  $ttl       Time-to-live in seconds (0 = no expiry).
     * @param string|null          $layout    Optional layout name.
     */
    public function renderCached(
        string $view,
        array $data = [],
        string $cacheKey = '',
        int $ttl = 0,
        ?string $layout = null,
    ): string {
        if ($this->cache === null) {
            return $this->render($view, $data, $layout);
        }

        $key = $cacheKey !== '' ? $cacheKey : 'view:' . $view;

        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            return $cached;
        }

        $html = $this->render($view, $data, $layout);
        $this->cache->set($key, $html, $ttl);
        return $html;
    }

    /**
     * Render a view with caching and wrap the result in an HTTP response.
     *
     * @param string               $view
     * @param array<string, mixed> $data
     * @param string               $cacheKey  Explicit cache key.  Defaults to `view:{name}`.
     * @param int                  $ttl       Time-to-live in seconds.
     * @param string|null          $layout
     * @param int                  $status    HTTP status code.
     */
    public function responseCached(
        string $view,
        array $data = [],
        string $cacheKey = '',
        int $ttl = 0,
        ?string $layout = null,
        int $status = 200,
    ): Response {
        return Response::html($this->renderCached($view, $data, $cacheKey, $ttl, $layout), $status);
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
     * @throws \InvalidArgumentException When the resolved file does not exist or resolves
     *                                   outside the configured views directory (path traversal).
     */
    public function resolve(string $view): string
    {
        // Reject null bytes and explicit path separators that could escape the root
        if (str_contains($view, "\0") || str_contains($view, '/') || str_contains($view, '\\')) {
            throw new \InvalidArgumentException("Invalid view name: [{$view}]");
        }

        $file = str_replace('.', DIRECTORY_SEPARATOR, trim($view, '.'));
        $path = rtrim($this->path, '/\\') . DIRECTORY_SEPARATOR . $file . '.' . ltrim($this->extension, '.');

        if (!is_file($path)) {
            throw new \InvalidArgumentException("View [{$view}] was not found at [{$path}]");
        }

        // Verify the resolved canonical path is within the views directory
        $real = realpath($path);

        if ($real === false) {
            throw new \InvalidArgumentException("View [{$view}] could not be resolved to a real path.");
        }

        if ($real !== $this->realBase && !str_starts_with($real, $this->realBase . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException(
                "View [{$view}] resolves outside the views directory (path traversal detected)."
            );
        }

        return $real;
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
