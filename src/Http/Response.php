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
            body: Stream::fromString((string) json_encode($data, $flags)),
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

    public static function redirect(string $url, int $status = 302): self
    {
        return new self(statusCode: $status, headers: ['Location' => $url]);
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
                      ->withBody(Stream::fromString((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        if ($status !== null) {
            $clone = $clone->withStatus($status);
        }
        /** @var self $clone */
        return $clone;
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
