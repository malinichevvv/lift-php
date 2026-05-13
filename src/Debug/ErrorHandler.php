<?php

declare(strict_types=1);

namespace Lift\Debug;

use Lift\Exception\HttpException;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Validation\ValidationException;
use Throwable;

/**
 * Debug-aware exception and PHP error handler.
 *
 * ErrorHandler is used by {@see \Lift\App} when debug mode or exception-specific
 * renderers are configured. It keeps Lift's default behaviour for validation and
 * HTTP exceptions, while allowing applications to register custom renderers by
 * exception class and a generic fallback renderer.
 *
 * ```php
 * $app->debug(['enabled' => true]);
 *
 * $app->onException(NotFoundException::class, function (NotFoundException $e) {
 *     return Response::html('<h1>Not found</h1>', 404);
 * });
 * ```
 */
final class ErrorHandler
{
    /** @var array<class-string, callable> */
    private array $renderers = [];
    private mixed $fallback = null;
    private mixed $previousErrorHandler = null;

    public function __construct(
        private readonly DebugConfig $config,
        private readonly DebugCollector $collector,
    ) {}

    /**
     * Register a renderer for an exception class or interface.
     *
     * Renderers are checked with `instanceof`, so handlers registered for parent
     * classes or interfaces also match child exceptions.
     *
     * @param class-string $exceptionClass
     * @param callable $handler Callable(Throwable $e, Request $request): Response
     */
    public function render(string $exceptionClass, callable $handler): self
    {
        $this->renderers[$exceptionClass] = $handler;
        return $this;
    }

    /**
     * Register a fallback renderer used after class-specific renderers.
     *
     * This is how {@see \Lift\App::onError()} is integrated with the debug error
     * pipeline while preserving backwards compatibility.
     */
    public function fallback(callable $handler): self
    {
        $this->fallback = $handler;
        return $this;
    }

    /**
     * Convert an exception into an HTTP response.
     *
     * Resolution order:
     * 1. exception-specific renderers;
     * 2. fallback renderer;
     * 3. Lift defaults for validation and HTTP exceptions;
     * 4. debug HTML page for HTML requests;
     * 5. generic JSON 500 response.
     */
    public function handle(Throwable $e, Request $request): Response
    {
        $this->collector->recordException($e);

        foreach ($this->renderers as $class => $renderer) {
            if ($e instanceof $class) {
                return $renderer($e, $request);
            }
        }

        if ($this->fallback !== null) {
            return ($this->fallback)($e, $request);
        }

        if ($e instanceof ValidationException) {
            return Response::json(['errors' => $e->errors()], 422);
        }

        if ($e instanceof HttpException) {
            return Response::json(['error' => $e->getMessage()], $e->getStatusCode());
        }

        if ($this->config->renderExceptionPages() && !$request->wantsJson()) {
            return Response::html($this->renderExceptionPage($e), 500);
        }

        return Response::json(['error' => 'Internal Server Error'], 500);
    }

    /**
     * Install a PHP error handler that records warnings/notices in the collector.
     *
     * The previous PHP error handler is preserved and called after Lift records
     * the error, so existing application-level handlers continue to work.
     */
    public function trackPhpErrors(): self
    {
        if (!$this->config->trackPhpErrors()) {
            return $this;
        }

        $this->previousErrorHandler = set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            $this->collector->recordPhpError($severity, $message, $file, $line);

            if (is_callable($this->previousErrorHandler)) {
                return (bool) ($this->previousErrorHandler)($severity, $message, $file, $line);
            }

            return false;
        });

        return $this;
    }

    /**
     * Restore the PHP error handler that was active before tracking started.
     */
    public function restorePhpHandlers(): void
    {
        if ($this->previousErrorHandler === null) {
            return;
        }

        restore_error_handler();
        $this->previousErrorHandler = null;
    }

    private function renderExceptionPage(Throwable $e): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"><title>Lift Debug Exception</title></head>'
            . '<body style="font:14px/1.5 system-ui,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;background:#111827;color:#e5e7eb;padding:32px">'
            . '<h1 style="color:#fca5a5">' . $this->e($e::class) . '</h1>'
            . '<p style="font-size:18px">' . $this->e($e->getMessage()) . '</p>'
            . '<p>' . $this->e($e->getFile()) . ':' . $e->getLine() . '</p>'
            . '<pre style="white-space:pre-wrap;background:#030712;border:1px solid #374151;padding:16px;border-radius:8px">' . $this->e($e->getTraceAsString()) . '</pre>'
            . '</body></html>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
