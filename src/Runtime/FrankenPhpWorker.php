<?php

declare(strict_types=1);

namespace Lift\Runtime;

use Lift\App;
use Lift\Http\Request;

/**
 * FrankenPHP worker-mode adapter for Lift.
 *
 * FrankenPHP is a modern PHP app server built into Caddy. In worker mode it
 * keeps PHP alive between requests — no bootstrap overhead per-request — while
 * filling superglobals ($_SERVER, $_GET, $_POST, …) for each request just like
 * a traditional PHP-FPM process. This means `Request::fromGlobals()` works as-is.
 *
 * ## Installation
 *
 * Download the FrankenPHP binary (it bundles PHP + Caddy):
 *
 * ```bash
 * curl -L https://github.com/dunglas/frankenphp/releases/latest/download/frankenphp-linux-x86_64 \
 *      -o frankenphp && chmod +x frankenphp
 * ```
 *
 * ## Setup
 *
 * Create `worker.php` in your project root:
 *
 * ```php
 * <?php
 * require 'vendor/autoload.php';
 *
 * $app = require 'bootstrap/app.php';
 *
 * (new \Lift\Runtime\FrankenPhpWorker($app))->serve();
 * ```
 *
 * Create a `Caddyfile`:
 *
 * ```caddyfile
 * {
 *     frankenphp
 *     admin off
 *     auto_https off
 * }
 *
 * :8080 {
 *     root * public
 *
 *     # Route every request through the worker — required for SPA-style routing.
 *     rewrite * /worker.php
 *
 *     php_server {
 *         worker worker.php
 *     }
 * }
 * ```
 *
 * Then run: `./frankenphp run --config Caddyfile`
 *
 * ## Persistent state
 *
 * The `$app` instance lives for the entire worker lifetime. Singletons
 * (DB connections, loggers) persist and are reused — desired behaviour.
 *
 * Request-scoped data must be stored in **request attributes**
 * (`$request->withAttribute('user', $user)`) — never in a singleton.
 *
 * Because FrankenPHP re-fills superglobals for every request, `Request::fromGlobals()`
 * returns a fresh request each iteration and is the simplest integration path.
 */
final class FrankenPhpWorker
{
    public function __construct(private readonly App $app) {}

    /**
     * Start the FrankenPHP worker loop.
     *
     * Blocks until FrankenPHP signals shutdown. Each iteration:
     * 1. `frankenphp_handle_request()` fills superglobals and pauses here.
     * 2. The callback builds a Lift Request from globals, dispatches it, and
     *    emits the response via the Lift output buffer before returning.
     * 3. FrankenPHP finishes the HTTP cycle and signals the next request.
     *
     * @throws \RuntimeException When not running inside FrankenPHP.
     */
    public function serve(): void
    {
        if (!function_exists('frankenphp_handle_request')) {
            throw new \RuntimeException(
                "FrankenPHP worker mode requires the frankenphp binary.\n" .
                "See https://frankenphp.dev for installation instructions."
            );
        }

        $app = $this->app;

        do {
            $ok = frankenphp_handle_request(static function () use ($app): void {
                $request  = Request::fromGlobals();
                $response = $app->handle($request);

                // Emit status line.
                http_response_code($response->getStatusCode());

                // Emit headers (skip any previously sent by the app).
                foreach ($response->getHeaders() as $name => $values) {
                    $first = true;
                    foreach ($values as $value) {
                        header($name . ': ' . $value, $first);
                        $first = false;
                    }
                }

                // Emit body.
                echo (string) $response->getBody();
            });
        } while ($ok);
    }
}
