<?php

declare(strict_types=1);

namespace Lift\Jwt;

use Lift\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Bearer-token JWT authentication middleware.
 *
 * Extracts the token from the `Authorization: Bearer <token>` header,
 * verifies it, and injects the decoded payload as the `jwt` request attribute.
 *
 * On failure, returns a 401 JSON response and does **not** call the next handler.
 *
 * ```php
 * $jwt = new Jwt(secret: $_ENV['JWT_SECRET']);
 *
 * $app->middleware(new JwtMiddleware($jwt));
 *
 * // In a handler, access claims via:
 * $claims = $request->getAttribute('jwt');
 * ```
 *
 * ### Protecting only specific routes
 * Apply the middleware on a route group instead of globally:
 * ```php
 * $app->group('/api', function (RouteGroup $g) use ($jwt) {
 *     $g->middleware(new JwtMiddleware($jwt));
 *     $g->get('/me', [UserController::class, 'me']);
 * });
 * ```
 */
final class JwtMiddleware implements MiddlewareInterface
{
    /**
     * @param Jwt         $jwt        Configured Jwt instance used for decoding.
     * @param string      $attribute  Request attribute name to store the decoded payload.
     * @param string[]    $except     Paths that bypass JWT verification (exact match).
     */
    public function __construct(
        private readonly Jwt $jwt,
        private readonly string $attribute = 'jwt',
        private readonly array $except = [],
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getUri()->getPath(), $this->except, true)) {
            return $handler->handle($request);
        }

        $header = $request->getHeaderLine('Authorization');

        if (!str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized('Missing or malformed Authorization header.');
        }

        $token = substr($header, 7);

        try {
            $payload = $this->jwt->decode($token);
        } catch (JwtException) {
            // Return a single neutral message — exposing which check failed
            // (signature, issuer, audience, expiry) gives an attacker a
            // token-validation oracle.
            return $this->unauthorized('Invalid or expired token.');
        }

        return $handler->handle($request->withAttribute($this->attribute, $payload));
    }

    private function unauthorized(string $message): ResponseInterface
    {
        return Response::json(['error' => 'Unauthorized', 'message' => $message], 401)
            ->withHeader('WWW-Authenticate', 'Bearer');
    }
}
