<?php

declare(strict_types=1);

namespace Lift\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

class Response extends Message implements ResponseInterface
{
    private static array $phrases = [
        100 => 'Continue',          101 => 'Switching Protocols',
        200 => 'OK',                201 => 'Created',
        202 => 'Accepted',          204 => 'No Content',
        206 => 'Partial Content',
        301 => 'Moved Permanently', 302 => 'Found',
        303 => 'See Other',         304 => 'Not Modified',
        307 => 'Temporary Redirect', 308 => 'Permanent Redirect',
        400 => 'Bad Request',       401 => 'Unauthorized',
        403 => 'Forbidden',         404 => 'Not Found',
        405 => 'Method Not Allowed', 409 => 'Conflict',
        410 => 'Gone',              422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error', 502 => 'Bad Gateway',
        503 => 'Service Unavailable',   504 => 'Gateway Timeout',
    ];

    public function __construct(
        private int $statusCode = 200,
        private string $reasonPhrase = '',
        array $headers = [],
        ?StreamInterface $body = null,
        string $version = '1.1',
    ) {
        $this->protocolVersion = $version;
        $this->setHeaders($headers);
        $this->body = $body ?? Stream::empty();
        if ($this->reasonPhrase === '') {
            $this->reasonPhrase = self::$phrases[$statusCode] ?? '';
        }
    }

    // -----------------------------------------------------------------
    // Factory methods — fast object construction
    // -----------------------------------------------------------------

    public static function json(
        mixed $data,
        int $status = 200,
        int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    ): self {
        return new self(
            statusCode: $status,
            headers: ['Content-Type' => 'application/json; charset=utf-8'],
            body: Stream::fromString(json_encode($data, $flags | JSON_THROW_ON_ERROR)),
        );
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self(
            statusCode: $status,
            headers: ['Content-Type' => 'text/html; charset=utf-8'],
            body: Stream::fromString($content),
        );
    }

    public static function text(string $content, int $status = 200): self
    {
        return new self(
            statusCode: $status,
            headers: ['Content-Type' => 'text/plain; charset=utf-8'],
            body: Stream::fromString($content),
        );
    }

    /**
     * @param array<string, string> $headers Additional headers to include in the redirect response.
     */
    public static function redirect(string $url, int $status = 302, array $headers = []): self
    {
        return new self(statusCode: $status, headers: array_merge(['Location' => $url], $headers));
    }

    public static function noContent(): self
    {
        return new self(statusCode: 204);
    }

    // -----------------------------------------------------------------
    // Fluent builder helpers (return new instances, PSR-7 immutable)
    // -----------------------------------------------------------------

    public function withJson(mixed $data, ?int $status = null): self
    {
        $clone = $this->withHeader('Content-Type', 'application/json; charset=utf-8')
                      ->withBody(Stream::fromString(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
        if ($status !== null) {
            $clone = $clone->withStatus($status);
        }
        /** @var self $clone */
        return $clone;
    }

    /**
     * Add a `Set-Cookie` header to the response.
     *
     * ```php
     * return $response->withCookie('remember_token', $token, ['max_age' => 86400 * 30]);
     * ```
     *
     * Supported `$options` keys:
     * - `max_age`  (int)    — `Max-Age` in seconds.
     * - `expires`  (int)    — Unix timestamp; ignored when `max_age` is set.
     * - `path`     (string) — Cookie path. Default: `/`.
     * - `domain`   (string) — Cookie domain.
     * - `secure`   (bool)   — Add `Secure` flag.
     * - `http_only`(bool)   — Add `HttpOnly` flag (default `true`).
     * - `same_site`(string) — `Strict`, `Lax`, or `None` (default `Lax`).
     *
     * @param array<string, mixed> $options
     */
    public function withCookie(string $name, string $value, array $options = []): static
    {
        return $this->withAddedHeader('Set-Cookie', self::buildCookieHeader($name, $value, $options));
    }

    /**
     * Expire a cookie by name, causing the browser to delete it.
     *
     * Sends an empty value with `Max-Age=0` and `Expires` in the past.
     */
    public function withoutCookie(string $name, string $path = '/'): static
    {
        $header = urlencode($name) . '=; Path=' . $path
            . '; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; HttpOnly; SameSite=Lax';
        return $this->withAddedHeader('Set-Cookie', $header);
    }

    /**
     * Build a single `Set-Cookie` header value string.
     *
     * @param array<string, mixed> $options
     */
    private static function buildCookieHeader(string $name, string $value, array $options): string
    {
        $parts = [urlencode($name) . '=' . urlencode($value)];
        $parts[] = 'Path=' . ($options['path'] ?? '/');

        if (isset($options['domain'])) {
            $parts[] = 'Domain=' . $options['domain'];
        }

        if (isset($options['max_age'])) {
            $parts[] = 'Max-Age=' . (int) $options['max_age'];
        } elseif (isset($options['expires'])) {
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', (int) $options['expires']);
        }

        if ($options['http_only'] ?? true) {
            $parts[] = 'HttpOnly';
        }

        $sameSite = $options['same_site'] ?? 'Lax';
        if ($sameSite !== '') {
            $parts[] = 'SameSite=' . $sameSite;
        }

        if ($options['secure'] ?? false) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    // -----------------------------------------------------------------
    // PSR-7 ResponseInterface
    // -----------------------------------------------------------------

    public function getStatusCode(): int      { return $this->statusCode; }
    public function getReasonPhrase(): string { return $this->reasonPhrase; }

    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $clone = clone $this;
        $clone->statusCode   = $code;
        $clone->reasonPhrase = $reasonPhrase !== '' ? $reasonPhrase : (self::$phrases[$code] ?? '');
        return $clone;
    }
}
