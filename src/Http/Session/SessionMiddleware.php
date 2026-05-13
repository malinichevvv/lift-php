<?php

declare(strict_types=1);

namespace Lift\Http\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Starts a driver-backed session and exposes it as a request attribute. */
class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly string $attribute = 'session',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->session->start();
        try {
            return $handler->handle($request->withAttribute($this->attribute, $this->session));
        } finally {
            $this->session->ageFlashData();
            $this->session->save();
        }
    }
}
