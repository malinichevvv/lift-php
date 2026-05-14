<?php

declare(strict_types=1);

namespace Lift\Http;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\StreamInterface;

abstract class Message implements MessageInterface
{
    protected string $protocolVersion = '1.1';
    /** @var array<string, string[]> $headers Original-case header map */
    protected array $headers = [];
    /** @var array<string, string> $headerNames Lowercase → original-case map */
    protected array $headerNames = [];
    protected StreamInterface $body;

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): static
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;
        return $clone;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headerNames[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        $lower = strtolower($name);
        if (!isset($this->headerNames[$lower])) {
            return [];
        }
        return $this->headers[$this->headerNames[$lower]];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): static
    {
        self::assertValidHeaderName($name);
        $values = is_array($value) ? array_values($value) : [(string) $value];
        array_walk($values, static fn(string $v) => self::assertValidHeaderValue($v));

        $clone  = clone $this;
        $lower  = strtolower($name);
        if (isset($clone->headerNames[$lower])) {
            unset($clone->headers[$clone->headerNames[$lower]]);
        }
        $clone->headerNames[$lower] = $name;
        $clone->headers[$name]      = $values;
        return $clone;
    }

    public function withAddedHeader(string $name, $value): static
    {
        self::assertValidHeaderName($name);
        $values = is_array($value) ? array_values($value) : [(string) $value];
        array_walk($values, static fn(string $v) => self::assertValidHeaderValue($v));

        $clone = clone $this;
        $lower = strtolower($name);
        if (isset($clone->headerNames[$lower])) {
            $existing = $clone->headerNames[$lower];
            $clone->headers[$existing] = array_merge($clone->headers[$existing], $values);
        } else {
            $clone->headerNames[$lower] = $name;
            $clone->headers[$name]      = $values;
        }
        return $clone;
    }

    public function withoutHeader(string $name): static
    {
        $clone = clone $this;
        $lower = strtolower($name);
        if (isset($clone->headerNames[$lower])) {
            unset($clone->headers[$clone->headerNames[$lower]], $clone->headerNames[$lower]);
        }
        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): static
    {
        $clone = clone $this;
        $clone->body = $body;
        return $clone;
    }

    protected function setHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            self::assertValidHeaderName($name);
            $lower = strtolower($name);
            $this->headerNames[$lower] = $name;
            $values = is_array($value) ? array_values($value) : [(string) $value];
            array_walk($values, static fn(string $v) => self::assertValidHeaderValue($v));
            $this->headers[$name] = $values;
        }
    }

    private static function assertValidHeaderName(string $name): void
    {
        if ($name === '' || !preg_match('/^[a-zA-Z0-9\'`#$%&*+\-.^_|~!]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid HTTP header name: [{$name}]");
        }
    }

    private static function assertValidHeaderValue(string $value): void
    {
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new \InvalidArgumentException(
                'Invalid HTTP header value: must not contain CR, LF, or NUL characters.'
            );
        }
    }
}
