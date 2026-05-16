<?php

declare(strict_types=1);

namespace Lift\Runtime;

use Lift\App;
use Lift\Http\Request;
use Lift\Http\StringStream;
use Lift\Http\UploadedFile;
use Lift\Http\Uri;

/**
 * Swoole / OpenSwoole HTTP server adapter for Lift.
 *
 * Keeps PHP alive between requests with true async I/O and coroutines.
 * Requires the `swoole` or `open-swoole` PHP extension:
 *
 * ```bash
 * pecl install swoole
 * # or for OpenSwoole:
 * pecl install openswoole
 * ```
 *
 * Create `server.php` in your project root:
 *
 * ```php
 * <?php
 * require 'vendor/autoload.php';
 *
 * $app = require 'bootstrap/app.php';
 *
 * (new \Lift\Runtime\SwooleServer($app))->start();
 * ```
 *
 * Then run: `php server.php`
 *
 * ## Configuration
 *
 * Pass an array of Swoole server settings as the second constructor argument:
 *
 * ```php
 * new \Lift\Runtime\SwooleServer($app, [
 *     'host'            => '0.0.0.0',
 *     'port'            => 9501,
 *     'worker_num'      => swoole_cpu_num() * 2,
 *     'task_worker_num' => 4,
 *     'daemonize'       => false,
 *     'log_file'        => '/var/log/swoole.log',
 * ]);
 * ```
 *
 * ## Persistent state
 *
 * Each Swoole worker is a long-running PHP process. Singletons registered with
 * `$app->singleton()` — DB connections, loggers, caches — persist across requests
 * within the same worker (connection pooling, warm reflection cache).
 *
 * Request-scoped data (e.g. authenticated user) must be stored in **request
 * attributes** (`$request->withAttribute('user', $user)`) — never in a singleton.
 *
 * ## Coroutines
 *
 * If you enable Swoole coroutines (`Co\run()` or `SWOOLE_HOOK_ALL`), each request
 * handler runs in its own coroutine. Standard PDO and blocking I/O are **not**
 * coroutine-aware; wrap them with Swoole's coroutine-compatible clients or use
 * connection pools (`Swoole\Database\PDOPool`) to avoid cross-coroutine sharing.
 */
final class SwooleServer
{
    private const DEFAULTS = [
        'host'       => '0.0.0.0',
        'port'       => 9501,
        'worker_num' => 4,
        'daemonize'  => false,
    ];

    private array $settings;

    public function __construct(
        private readonly App $app,
        array $settings = [],
    ) {
        $this->settings = array_merge(self::DEFAULTS, $settings);
    }

    /**
     * Start the Swoole HTTP server.
     *
     * Blocks until the server is stopped (SIGTERM / SIGINT).
     *
     * @throws \RuntimeException When the swoole or openswoole extension is not loaded.
     */
    public function start(): void
    {
        if (!extension_loaded('swoole') && !extension_loaded('openswoole')) {
            throw new \RuntimeException(
                "Swoole support requires the swoole or openswoole PHP extension.\n" .
                "Install it with: pecl install swoole"
            );
        }

        $host = (string) ($this->settings['host'] ?? '0.0.0.0');
        $port = (int)   ($this->settings['port'] ?? 9501);

        /** @var \Swoole\HTTP\Server $server */
        $server = new \Swoole\HTTP\Server($host, $port);

        $configurable = array_diff_key($this->settings, ['host' => 1, 'port' => 1]);
        if ($configurable) {
            $server->set($configurable);
        }

        $app = $this->app;

        $server->on('request', static function (
            object $swooleRequest,
            object $swooleResponse,
        ) use ($app): void {
            try {
                $request  = self::fromSwoole($swooleRequest);
                $response = $app->handle($request);

                $swooleResponse->setStatusCode($response->getStatusCode());

                foreach ($response->getHeaders() as $name => $values) {
                    foreach ($values as $value) {
                        $swooleResponse->setHeader($name, $value);
                    }
                }

                $swooleResponse->end((string) $response->getBody());
            } catch (\Throwable) {
                $swooleResponse->setStatusCode(500);
                $swooleResponse->end('Internal Server Error');
            }
        });

        $server->start();
    }

    /**
     * Convert a Swoole request into a Lift Request.
     *
     * Accepts `object` rather than `\Swoole\Http\Request` so the class can be
     * loaded on systems where the Swoole extension is not installed (extension is
     * only required at runtime, inside `start()`). Swoole exposes headers as
     * lowercase key→value arrays (not PSR-7); this method normalises them.
     */
    private static function fromSwoole(object $r): Request
    {
        $server  = $r->server  ?? [];
        $headers = $r->header  ?? [];
        $cookies = $r->cookie  ?? [];
        $query   = $r->get     ?? [];
        $files   = $r->files   ?? [];

        $method = strtoupper($server['request_method'] ?? 'GET');

        // Build URI from Swoole's server array (lowercase keys).
        $scheme = isset($server['https']) && $server['https'] !== 'off' ? 'https' : 'http';
        $host   = $headers['host'] ?? ($server['server_name'] ?? 'localhost');
        $port   = (int) ($server['server_port'] ?? ($scheme === 'https' ? 443 : 80));
        $path   = $server['request_uri']     ?? '/';
        $qs     = $server['query_string']    ?? '';
        if (str_contains($path, '?')) {
            [$path, $qs] = explode('?', $path, 2);
        }

        // Remove default ports from URI to keep it clean.
        $portSuffix = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)
            ? ''
            : ':' . $port;

        $uriString = $scheme . '://' . $host . $portSuffix . $path . ($qs !== '' ? '?' . $qs : '');
        $uri       = new Uri($uriString);

        // Swoole headers are already lowercase. Lift's Message::setHeaders() accepts any case.
        $liftHeaders = [];
        foreach ($headers as $name => $value) {
            $liftHeaders[ucwords($name, '-')] = $value;
        }

        // Parse body.
        $parsedBody = [];
        $rawBody    = $r->rawContent();

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $ct = $headers['content-type'] ?? '';
            if (str_contains($ct, 'application/json')) {
                $parsedBody = json_decode($rawBody ?: '', true) ?? [];
            } elseif ($r->post) {
                $parsedBody = $r->post;
            }
        }

        $uploadedFiles = [];
        foreach ($files as $field => $file) {
            $uploadedFiles[$field] = UploadedFile::fromArray([
                'tmp_name' => $file['tmp_name'] ?? '',
                'size'     => $file['size']     ?? 0,
                'error'    => $file['error']    ?? UPLOAD_ERR_NO_FILE,
                'name'     => $file['name']     ?? '',
                'type'     => $file['type']     ?? '',
            ]);
        }

        $serverParams = array_change_key_case($server, CASE_UPPER);

        return new Request(
            method:        $method,
            uri:           $uri,
            headers:       $liftHeaders,
            body:          new StringStream($rawBody ?: ''),
            queryParams:   $query,
            parsedBody:    $parsedBody,
            uploadedFiles: $uploadedFiles,
            serverParams:  $serverParams,
            cookieParams:  $cookies,
        );
    }
}
