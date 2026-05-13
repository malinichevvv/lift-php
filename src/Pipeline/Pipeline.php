<?php

declare(strict_types=1);

namespace Lift\Pipeline;

use Lift\Container\Container;
use Lift\Http\Request;
use Lift\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Pipeline
{
    public function __construct(private readonly Container $container) {}

    /**
     * Run the request through a middleware stack and a final handler.
     *
     * @param array<MiddlewareInterface|string> $middleware
     * @param callable(ServerRequestInterface): Response $finalHandler
     */
    public function run(Request $request, array $middleware, callable $finalHandler): Response
    {
        $handler = $this->wrapFinal($finalHandler);

        foreach (array_reverse($middleware) as $mw) {
            $resolved = is_string($mw) ? $this->container->make($mw) : $mw;
            $handler  = $this->wrapMiddleware($resolved, $handler);
        }

        $response = $handler->handle($request);

        // Ensure we always return our Response type
        if ($response instanceof Response) {
            return $response;
        }

        return new Response(
            statusCode: $response->getStatusCode(),
            reasonPhrase: $response->getReasonPhrase(),
            headers: $response->getHeaders(),
            body: $response->getBody(),
            version: $response->getProtocolVersion(),
        );
    }

    private function wrapFinal(callable $handler): RequestHandlerInterface
    {
        return new class($handler) implements RequestHandlerInterface {
            public function __construct(private readonly mixed $handler) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handler)($request);
            }
        };
    }

    private function wrapMiddleware(MiddlewareInterface $mw, RequestHandlerInterface $next): RequestHandlerInterface
    {
        return new class($mw, $next) implements RequestHandlerInterface {
            public function __construct(
                private readonly MiddlewareInterface $middleware,
                private readonly RequestHandlerInterface $next,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, $this->next);
            }
        };
    }
}
