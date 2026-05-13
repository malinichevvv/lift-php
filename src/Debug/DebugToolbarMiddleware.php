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

final class DebugToolbarMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly DebugConfig $config,
        private readonly DebugCollector $collector,
        private readonly DebugToolbarRenderer $renderer,
    ) {}

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
