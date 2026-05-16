<?php

declare(strict_types=1);

namespace Lift\Runtime;

use Lift\App;
use Lift\Http\Request;

/**
 * RoadRunner HTTP worker for Lift.
 *
 * Keeps PHP alive between requests — no bootstrap overhead per-request.
 * Requires `spiral/roadrunner-http` and a PSR-17 factory (e.g. `nyholm/psr7`):
 *
 * ```bash
 * composer require spiral/roadrunner-http nyholm/psr7
 * ```
 *
 * Create `worker.php` in your project root:
 *
 * ```php
 * <?php
 * require 'vendor/autoload.php';
 *
 * $app = require 'bootstrap/app.php';
 *
 * (new \Lift\Runtime\RoadRunnerWorker($app))->serve();
 * ```
 *
 * Then configure `.rr.yaml`:
 *
 * ```yaml
 * server:
 *   command: "php worker.php"
 *
 * http:
 *   address: "0.0.0.0:8080"
 *   pool:
 *     num_workers: 4
 * ```
 *
 * And run: `./rr serve`
 *
 * ## Persistent state
 *
 * The `$app` instance (and its container) lives for the entire worker lifetime.
 * Singletons registered with `$app->singleton()` — DB connections, loggers,
 * HTTP clients — persist and are reused across requests, which is the desired
 * behaviour (connection pooling, warm reflection cache).
 *
 * Request-scoped data (e.g. authenticated user) must be stored in the **request
 * attributes** (`$request->withAttribute('user', $user)`) — never in a singleton.
 */
final class RoadRunnerWorker
{
    public function __construct(private readonly App $app) {}

    /**
     * Start the worker loop.
     *
     * Blocks until RoadRunner signals shutdown. Each iteration:
     * 1. Receives an incoming PSR-7 server request from RoadRunner.
     * 2. Converts it to a Lift `Request` via {@see Request::fromPsr7()}.
     * 3. Dispatches it through Lift's middleware + router.
     * 4. Sends the `Response` back to RoadRunner.
     *
     * @param object|null $psr17Factory Any object implementing all PSR-17 factory
     *                                  interfaces (e.g. `new Nyholm\Psr7\Factory\Psr17Factory()`).
     *                                  Auto-detected when `null`.
     * @throws \RuntimeException When `spiral/roadrunner-http` is not installed.
     */
    public function serve(?object $psr17Factory = null): void
    {
        if (!class_exists(\Spiral\RoadRunner\Worker::class)) {
            throw new \RuntimeException(
                "RoadRunner support requires: composer require spiral/roadrunner-http nyholm/psr7\n" .
                "Also download the RoadRunner binary: vendor/bin/rr get-binary"
            );
        }

        $factory = $psr17Factory ?? $this->detectPsr17Factory();
        $worker  = \Spiral\RoadRunner\Worker::create();

        /** @var \Spiral\RoadRunner\Http\PSR7Worker $psr7 */
        $psr7 = new \Spiral\RoadRunner\Http\PSR7Worker($worker, $factory, $factory, $factory);

        while (true) {
            try {
                $serverRequest = $psr7->waitRequest();
            } catch (\Throwable $e) {
                $psr7->getWorker()->error((string) $e);
                continue;
            }

            if ($serverRequest === null) {
                break; // graceful shutdown
            }

            try {
                $request  = Request::fromPsr7($serverRequest);
                $response = $this->app->handle($request);
                $psr7->respond($response);
            } catch (\Throwable $e) {
                $psr7->getWorker()->error((string) $e);
            }
        }
    }

    private function detectPsr17Factory(): object
    {
        $candidates = [
            'Nyholm\Psr7\Factory\Psr17Factory',
            'GuzzleHttp\Psr7\HttpFactory',
            'Laminas\Diactoros\ResponseFactory', // implements all PSR-17 in one class
            'Slim\Psr7\Factory\ServerRequestFactory',
        ];

        foreach ($candidates as $class) {
            if (class_exists($class)) {
                return new $class();
            }
        }

        throw new \RuntimeException(
            "No PSR-17 factory found. Install one: composer require nyholm/psr7"
        );
    }
}
