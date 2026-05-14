<?php

declare(strict_types=1);

namespace Lift\Debug;

use Lift\Http\Request;
use Lift\Http\Response;
use Throwable;

/**
 * Request-scoped collector for debug toolbar diagnostics.
 *
 * The collector is reset at the beginning of each request and receives events
 * from middleware and error handling. It stores only simple serialisable arrays
 * so the toolbar can render diagnostics without leaking internal objects.
 *
 * Captured data includes request metadata, response metadata, elapsed time,
 * peak memory, exceptions, PHP errors, and masked sensitive values.
 */
final class DebugCollector
{
    private ?float $startedAt = null;
    private ?Request $request = null;
    private ?Response $response = null;
    /** @var list<array<string, mixed>> */
    private array $exceptions = [];
    /** @var list<array<string, mixed>> */
    private array $phpErrors = [];
    /** @var list<array{sql: string, bindings: array<mixed>, time_ms: float}> */
    private array $queries = [];
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    private array $logs = [];

    /** Create a collector using masking rules from debug configuration. */
    public function __construct(private readonly DebugConfig $config) {}

    /**
     * Reset the collector and start timing a request.
     */
    public function start(Request $request): void
    {
        $this->startedAt = microtime(true);
        $this->request   = $request;
        $this->response  = null;
        $this->exceptions = [];
        $this->phpErrors  = [];
        $this->queries    = [];
        $this->logs       = [];
    }

    /** Store the final response for the current request. */
    public function finish(Response $response): void
    {
        $this->response = $response;
    }

    /** Record a thrown exception, capturing the full stack trace for the debug panel. */
    public function recordException(Throwable $e): void
    {
        $trace = [];
        foreach ($e->getTrace() as $frame) {
            $trace[] = [
                'file'     => $frame['file']     ?? '[internal]',
                'line'     => $frame['line']     ?? 0,
                'class'    => $frame['class']    ?? '',
                'type'     => $frame['type']     ?? '',
                'function' => $frame['function'] ?? '',
            ];
        }

        $previous = null;
        $prev     = $e->getPrevious();
        if ($prev !== null) {
            $previous = ['class' => $prev::class, 'message' => $prev->getMessage()];
        }

        $this->exceptions[] = [
            'class'    => $e::class,
            'message'  => $e->getMessage(),
            'code'     => $e->getCode(),
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
            'trace'    => $trace,
            'previous' => $previous,
        ];
    }

    /**
     * Record an executed SQL query.
     *
     * Wire this to a {@see Connection::onQuery()} listener at bootstrap:
     * ```php
     * $db->onQuery([$collector, 'recordQuery']);
     * ```
     */
    public function recordQuery(string $sql, array $bindings, float $timeMs): void
    {
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings, 'time_ms' => $timeMs];
    }

    /**
     * Record a PSR-3 log entry.  Wire this via {@see DebugLogHandler}.
     */
    public function recordLog(string $level, string $message, array $context): void
    {
        $this->logs[] = ['level' => $level, 'message' => $message, 'context' => $context];
    }

    /** Record a PHP warning, notice, deprecation, or user-level error. */
    public function recordPhpError(int $severity, string $message, string $file, int $line): void
    {
        $this->phpErrors[] = [
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line,
        ];
    }

    /**
     * Return a serialisable snapshot for rendering or tests.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [
            'request'     => $this->requestData(),
            'response'    => $this->responseData(),
            'performance' => [
                'duration_ms'    => $this->startedAt === null ? 0.0 : round((microtime(true) - $this->startedAt) * 1000, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ],
            'queries'    => $this->queries,
            'logs'       => $this->logs,
            'exceptions' => $this->exceptions,
            'php_errors' => $this->phpErrors,
        ];
    }

    /** @return array<string, mixed> */
    private function requestData(): array
    {
        if ($this->request === null) {
            return [];
        }

        $body = $this->request->getParsedBody();
        $bodyData = is_array($body) ? $this->maskArray($body) : [];

        return [
            'method'  => $this->request->getMethod(),
            'uri'     => (string) $this->request->getUri(),
            'query'   => $this->maskArray($this->request->getQueryParams()),
            'body'    => $bodyData,
            'params'  => $this->maskArray($this->request->getRouteParams()),
            'headers' => $this->maskHeaders($this->request->getHeaders()),
            'cookies' => $this->maskArray($this->request->getCookieParams()),
        ];
    }

    /** @return array<string, mixed> */
    private function responseData(): array
    {
        if ($this->response === null) {
            return [];
        }

        return [
            'status' => $this->response->getStatusCode(),
            'headers' => $this->maskHeaders($this->response->getHeaders()),
        ];
    }

    /** @param array<string, mixed> $items */
    private function maskArray(array $items): array
    {
        $hidden = array_map('strtolower', $this->config->hiddenParams());
        foreach ($items as $key => $value) {
            if (in_array(strtolower((string) $key), $hidden, true)) {
                $items[$key] = '***';
            } elseif (is_array($value)) {
                $items[$key] = $this->maskArray($value);
            }
        }
        return $items;
    }

    /** @param array<string, list<string>|string> $headers */
    private function maskHeaders(array $headers): array
    {
        $hidden = array_map('strtolower', $this->config->hiddenHeaders());
        foreach ($headers as $name => $value) {
            if (in_array(strtolower((string) $name), $hidden, true)) {
                $headers[$name] = ['***'];
            }
        }
        return $headers;
    }
}
