<?php

declare(strict_types=1);

namespace Lift\Debug;

use Lift\Http\Request;
use Lift\Http\Response;
use Lift\Http\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * PSR-15 middleware that injects the debug toolbar into HTML responses.
 *
 * When the debug mode is enabled and the response qualifies (HTML content-type,
 * contains a `</body>` tag, and passes the {@see DebugConfig::shouldInject()} check)
 * the rendered toolbar HTML is inserted immediately before `</body>`.
 *
 * When the response does not contain `</body>` (e.g., a partial fragment), the
 * toolbar is appended to the end of the body.
 *
 * The middleware collects request/response/performance/error data via
 * {@see DebugCollector} and renders it using {@see DebugToolbarRenderer}.
 * Any exception thrown by the inner handler is recorded before being re-thrown.
 */
final class DebugToolbarMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly DebugConfig $config,
        private readonly DebugCollector $collector,
        private readonly DebugToolbarRenderer $renderer,
    ) {}

    /**
     * Run the inner handler, collect telemetry, and inject the toolbar into HTML responses.
     *
     * Returns the response unchanged when debug mode is off or the response
     * does not qualify for toolbar injection.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$request instanceof Request || !$this->config->enabled()) {
            return $handler->handle($request);
        }

        $this->collector->start($request);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $e) {
            $this->collector->recordException($e);
            throw $e;
        }

        $response = $response instanceof Response
            ? $response
            : new Response(
                statusCode: $response->getStatusCode(),
                reasonPhrase: $response->getReasonPhrase(),
                headers: $response->getHeaders(),
                body: $response->getBody(),
                version: $response->getProtocolVersion(),
            );

        $this->collector->finish($response);

        if (!$this->config->shouldInject($request, $response)) {
            return $response;
        }

        $body = (string) $response->getBody();
        $toolbar = $this->renderer->render($this->collector->data());
        $body = str_ireplace('</body>', $toolbar . '</body>', $body, $count);
        if ($count === 0) {
            $body .= $toolbar;
        }

        return $response->withBody(Stream::fromString($body));
    }
}
