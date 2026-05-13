<?php

declare(strict_types=1);

namespace Lift\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Appends security-hardening HTTP response headers.
 *
 * All headers are enabled by default with safe values and can be individually
 * customised or disabled.
 *
 * ```php
 * // Default — all headers with safe presets
 * $app->use(new SecurityHeadersMiddleware());
 *
 * // Custom Content-Security-Policy and disabled HSTS (e.g. non-HTTPS dev)
 * $app->use(new SecurityHeadersMiddleware(
 *     csp: "default-src 'self'; script-src 'self' cdn.example.com",
 *     hsts: null,
 * ));
 * ```
 *
 * Headers applied:
 * - `X-Content-Type-Options: nosniff`
 * - `X-Frame-Options`
 * - `Referrer-Policy`
 * - `Content-Security-Policy`
 * - `Strict-Transport-Security` (HSTS)
 * - `Permissions-Policy`
 * - `X-XSS-Protection` (legacy; kept for older browsers)
 *
 * @see https://securityheaders.com
 * @see https://owasp.org/www-project-secure-headers/
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @param string|null $csp         Content-Security-Policy value. Null = disabled.
     * @param string|null $hsts        HSTS value. Set to null on non-HTTPS environments.
     * @param string      $frameOptions X-Frame-Options ("DENY" or "SAMEORIGIN").
     * @param string      $referrer    Referrer-Policy value.
     * @param string|null $permissions Permissions-Policy value. Null = disabled.
     * @param bool        $noSniff     Emit X-Content-Type-Options: nosniff.
     * @param bool        $xssProtect  Emit X-XSS-Protection: 1; mode=block (legacy).
     */
    public function __construct(
        private readonly ?string $csp = "default-src 'self'",
        private readonly ?string $hsts = 'max-age=31536000; includeSubDomains',
        private readonly string $frameOptions = 'DENY',
        private readonly string $referrer = 'strict-origin-when-cross-origin',
        private readonly ?string $permissions = 'camera=(), microphone=(), geolocation=()',
        private readonly bool $noSniff = true,
        private readonly bool $xssProtect = true,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($this->noSniff) {
            $response = $response->withHeader('X-Content-Type-Options', 'nosniff');
        }

        if ($this->xssProtect) {
            $response = $response->withHeader('X-XSS-Protection', '1; mode=block');
        }

        $response = $response->withHeader('X-Frame-Options', $this->frameOptions);
        $response = $response->withHeader('Referrer-Policy', $this->referrer);

        if ($this->csp !== null) {
            $response = $response->withHeader('Content-Security-Policy', $this->csp);
        }

        if ($this->hsts !== null) {
            $response = $response->withHeader('Strict-Transport-Security', $this->hsts);
        }

        if ($this->permissions !== null) {
            $response = $response->withHeader('Permissions-Policy', $this->permissions);
        }

        return $response;
    }
}
