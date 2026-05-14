<?php

declare(strict_types=1);

namespace Lift\Debug;

use Closure;
use Lift\Exception\HttpException;
use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Validation\ValidationException;

/**
 * Content-negotiating error renderer.
 *
 * Returns JSON when the client requests it, an HTML error page otherwise.
 * Use it as the `$app->onError(...)` handler:
 *
 * ```php
 * use Lift\Debug\ErrorRenderer;
 *
 * // Auto-detect: JSON for API clients, HTML for browsers
 * $app->onError(ErrorRenderer::auto());
 *
 * // Always show details (e.g. in local dev without the debug toolbar)
 * $app->onError(ErrorRenderer::auto(showDetails: true));
 * ```
 */
final class ErrorRenderer
{
    /**
     * Return a handler that picks JSON or HTML based on the Accept header.
     *
     * @param bool $showDetails Include exception class, file, line, and trace in the response.
     */
    public static function auto(bool $showDetails = false): Closure
    {
        return static function (\Throwable $e, Request $req) use ($showDetails): Response {
            $status = self::statusCode($e);

            if ($req->wantsJson() || $req->isJson()) {
                return self::jsonResponse($e, $status, $showDetails);
            }

            return self::htmlResponse($e, $status, $showDetails);
        };
    }

    /**
     * Return a handler that always responds with JSON.
     *
     * @param bool $showDetails Include exception class, file, line, and trace.
     */
    public static function json(bool $showDetails = false): Closure
    {
        return static function (\Throwable $e) use ($showDetails): Response {
            return self::jsonResponse($e, self::statusCode($e), $showDetails);
        };
    }

    /**
     * Return a handler that always responds with HTML.
     *
     * @param bool $showDetails Render exception class, file, line, and trace.
     */
    public static function html(bool $showDetails = false): Closure
    {
        return static function (\Throwable $e) use ($showDetails): Response {
            return self::htmlResponse($e, self::statusCode($e), $showDetails);
        };
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private static function statusCode(\Throwable $e): int
    {
        if ($e instanceof ValidationException) {
            return 422;
        }
        if ($e instanceof HttpException) {
            return $e->getStatusCode();
        }
        return 500;
    }

    private static function jsonResponse(\Throwable $e, int $status, bool $showDetails): Response
    {
        $body = ['error' => $e->getMessage()];

        if ($showDetails) {
            $body['exception'] = $e::class;
            $body['file']      = $e->getFile();
            $body['line']      = $e->getLine();
            $body['trace']     = array_map(
                static fn(array $f): string =>
                    ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '')
                    . ' (' . ($f['file'] ?? '?') . ':' . ($f['line'] ?? '?') . ')',
                $e->getTrace(),
            );

            if ($e instanceof ValidationException) {
                $body['errors'] = $e->errors();
            }
        }

        return Response::json($body, $status);
    }

    private static function htmlResponse(\Throwable $e, int $status, bool $showDetails): Response
    {
        $title   = htmlspecialchars(self::titleFor($status, $e));
        $message = htmlspecialchars($e->getMessage());
        $details = '';

        if ($showDetails) {
            $class   = htmlspecialchars($e::class);
            $file    = htmlspecialchars($e->getFile());
            $line    = $e->getLine();
            $trace   = htmlspecialchars($e->getTraceAsString());
            $details = <<<HTML
<div class="details">
  <p class="meta">{$class} in <code>{$file}:{$line}</code></p>
  <pre class="trace">{$trace}</pre>
</div>
HTML;
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
       background:#f5f5f7;color:#1d1d1f;min-height:100vh;display:flex;
       align-items:center;justify-content:center;padding:2rem}
  .card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);
        padding:2.5rem;max-width:680px;width:100%}
  .status{font-size:5rem;font-weight:700;color:#e5e5ea;line-height:1}
  h1{font-size:1.5rem;font-weight:600;margin:.5rem 0 1rem}
  p.message{color:#555;line-height:1.6;font-size:1rem}
  .details{margin-top:1.5rem;border-top:1px solid #e5e5ea;padding-top:1.5rem}
  .meta{font-size:.85rem;color:#888;margin-bottom:.75rem}
  code{font-family:'SF Mono',Menlo,Consolas,monospace;font-size:.85em}
  pre.trace{font-family:'SF Mono',Menlo,Consolas,monospace;font-size:.78rem;
            background:#f5f5f7;padding:1rem;border-radius:6px;overflow-x:auto;
            color:#444;line-height:1.5;white-space:pre-wrap;word-break:break-all}
</style>
</head>
<body>
<div class="card">
  <div class="status">{$status}</div>
  <h1>{$title}</h1>
  <p class="message">{$message}</p>
  {$details}
</div>
</body>
</html>
HTML;

        return Response::html($html, $status);
    }

    private static function titleFor(int $status, \Throwable $e): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => $e->getMessage() !== '' ? $e->getMessage() : 'Error',
        };
    }
}
