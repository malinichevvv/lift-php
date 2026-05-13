<?php

declare(strict_types=1);

namespace Lift\Middleware;

use Lift\Http\Response;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * CSRF protection using the Double-Submit Cookie pattern.
 *
 * A cryptographically random token is issued as a cookie. Mutating requests
 * (POST, PUT, PATCH, DELETE) must echo the same token in either:
 * - The `X-CSRF-Token` request header (for AJAX/API clients), or
 * - The `_csrf_token` form field (for traditional HTML forms).
 *
 * The cookie is `HttpOnly: false` so JavaScript can read and submit it.
 *
 * Safe methods (GET, HEAD, OPTIONS, TRACE) are always allowed through.
 *
 * **JSON API note:** If your API is fully CORS-protected and uses
 * `Authorization` headers (not cookies), you typically do not need CSRF
 * protection. Add this middleware only for session-cookie-based applications.
 *
 * ```php
 * $app->use(new CsrfMiddleware(secret: $_ENV['APP_SECRET']));
 * ```
 *
 * @see https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS  = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];
    private const COOKIE_NAME   = 'csrf_token';
    private const HEADER_NAME   = 'X-CSRF-Token';
    private const FIELD_NAME    = '_csrf_token';
    private const TOKEN_BYTES   = 32;

    /**
     * @param string $secret      Non-empty application secret used to HMAC-sign tokens.
     * @param bool   $secure      Set the Secure flag on the CSRF cookie. Required for SameSite=None.
     * @param string $sameSite    SameSite cookie attribute: "Strict", "Lax", or "None".
     * @param string $cookiePath  Cookie path sent in the Set-Cookie header.
     * @throws InvalidArgumentException When the secret is empty or cookie flags are invalid.
     */
    public function __construct(
        private readonly string $secret,
        private readonly bool $secure = true,
        private readonly string $sameSite = 'Lax',
        private readonly string $cookiePath = '/',
    ) {
        if ($this->secret === '') {
            throw new InvalidArgumentException('CSRF secret must not be empty');
        }

        if (!in_array($this->sameSite, ['Strict', 'Lax', 'None'], true)) {
            throw new InvalidArgumentException('CSRF SameSite must be one of Strict, Lax, or None');
        }

        if ($this->sameSite === 'None' && !$this->secure) {
            throw new InvalidArgumentException('CSRF SameSite=None requires the Secure cookie flag');
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = $request->getMethod();

        // Generate or reuse the token for this request
        $cookieToken = $request->getCookieParams()[self::COOKIE_NAME] ?? null;

        if ($cookieToken === null || !$this->isValidToken($cookieToken)) {
            $cookieToken = $this->generateToken();
        }

        // Attach the token to request attributes so templates can read it
        $request = $request->withAttribute('csrf_token', $cookieToken);

        // Validate mutating requests
        if (!in_array($method, self::SAFE_METHODS, true)) {
            $submitted = $this->extractSubmittedToken($request);

            if ($submitted === null || !hash_equals($cookieToken, $submitted)) {
                return Response::json(['error' => 'CSRF token mismatch'], 403);
            }
        }

        $response = $handler->handle($request);

        // Refresh cookie on every response
        return $this->withCsrfCookie($response, $cookieToken);
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Generate a new HMAC-signed CSRF token: `random|hmac(secret, random)`.
     */
    private function generateToken(): string
    {
        $random = bin2hex(random_bytes(self::TOKEN_BYTES));
        $hmac   = hash_hmac('sha256', $random, $this->secret);
        return $random . '|' . $hmac;
    }

    /**
     * Validate that a token's HMAC is correct.
     * Prevents forgery even if the attacker can set cookies on a subdomain.
     */
    private function isValidToken(string $token): bool
    {
        [$random, $hmac] = array_pad(explode('|', $token, 2), 2, '');
        if (!ctype_xdigit($random) || strlen($random) !== self::TOKEN_BYTES * 2 || !ctype_xdigit($hmac)) {
            return false;
        }

        $expected = hash_hmac('sha256', $random, $this->secret);
        return hash_equals($expected, $hmac);
    }

    /**
     * Extract the submitted token from the request header or parsed body.
     */
    private function extractSubmittedToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine(self::HEADER_NAME);
        if ($header !== '') {
            return $header;
        }

        $body = $request->getParsedBody();
        if (is_array($body) && isset($body[self::FIELD_NAME])) {
            return (string) $body[self::FIELD_NAME];
        }

        if (is_object($body) && isset($body->{self::FIELD_NAME})) {
            return (string) $body->{self::FIELD_NAME};
        }

        return null;
    }

    /**
     * Append a `Set-Cookie` header to the response with the CSRF token.
     */
    private function withCsrfCookie(ResponseInterface $response, string $token): ResponseInterface
    {
        $cookie = sprintf(
            '%s=%s; Path=%s; SameSite=%s%s',
            self::COOKIE_NAME,
            rawurlencode($token),
            $this->cookiePath,
            $this->sameSite,
            $this->secure ? '; Secure' : '',
        );

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }
}
