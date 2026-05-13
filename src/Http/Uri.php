<?php

declare(strict_types=1);

namespace Lift\Http;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

final class Uri implements UriInterface
{
    private const STANDARD_PORTS = ['http' => 80, 'https' => 443, 'ftp' => 21];

    private string $scheme;
    private string $userInfo;
    private string $host;
    private ?int $port;
    private string $path;
    private string $query;
    private string $fragment;

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            $this->scheme = $this->userInfo = $this->host = $this->path = $this->query = $this->fragment = '';
            $this->port = null;
            return;
        }

        $parts = parse_url($uri);
        if ($parts === false) {
            throw new InvalidArgumentException("Invalid URI: {$uri}");
        }

        $this->scheme   = strtolower($parts['scheme'] ?? '');
        $this->host     = strtolower($parts['host'] ?? '');
        $this->port     = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->path     = $parts['path'] ?? '';
        $this->query    = $parts['query'] ?? '';
        $this->fragment = $parts['fragment'] ?? '';

        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $this->userInfo = $user . $pass;
    }

    public static function fromServer(array $server): self
    {
        $scheme = (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $server['HTTP_HOST'] ?? ($server['SERVER_NAME'] ?? 'localhost');
        $port   = isset($server['SERVER_PORT']) ? (int) $server['SERVER_PORT'] : 80;
        $uri    = $server['REQUEST_URI'] ?? '/';

        $standardPort = self::STANDARD_PORTS[$scheme] ?? null;
        if ($standardPort === $port) {
            return new self("{$scheme}://{$host}{$uri}");
        }

        // Strip port if already in host (HTTP_HOST may include it)
        if (!str_contains($host, ':')) {
            return new self("{$scheme}://{$host}:{$port}{$uri}");
        }

        return new self("{$scheme}://{$host}{$uri}");
    }

    public function getScheme(): string   { return $this->scheme; }
    public function getUserInfo(): string  { return $this->userInfo; }
    public function getHost(): string      { return $this->host; }
    public function getPath(): string      { return $this->path; }
    public function getQuery(): string     { return $this->query; }
    public function getFragment(): string  { return $this->fragment; }

    public function getAuthority(): string
    {
        $authority = $this->host;
        if ($this->userInfo !== '') {
            $authority = "{$this->userInfo}@{$authority}";
        }
        $port = $this->getPort();
        if ($port !== null) {
            $authority .= ":{$port}";
        }
        return $authority;
    }

    public function getPort(): ?int
    {
        if ($this->port === null) {
            return null;
        }
        $standard = self::STANDARD_PORTS[$this->scheme] ?? null;
        return ($standard !== null && $standard === $this->port) ? null : $this->port;
    }

    public function withScheme(string $scheme): static
    {
        $clone = clone $this;
        $clone->scheme = strtolower($scheme);
        return $clone;
    }

    public function withUserInfo(string $user, ?string $password = null): static
    {
        $clone = clone $this;
        $clone->userInfo = $password !== null ? "{$user}:{$password}" : $user;
        return $clone;
    }

    public function withHost(string $host): static
    {
        $clone = clone $this;
        $clone->host = strtolower($host);
        return $clone;
    }

    public function withPort(?int $port): static
    {
        $clone = clone $this;
        $clone->port = $port;
        return $clone;
    }

    public function withPath(string $path): static
    {
        $clone = clone $this;
        $clone->path = $path;
        return $clone;
    }

    public function withQuery(string $query): static
    {
        $clone = clone $this;
        $clone->query = ltrim($query, '?');
        return $clone;
    }

    public function withFragment(string $fragment): static
    {
        $clone = clone $this;
        $clone->fragment = ltrim($fragment, '#');
        return $clone;
    }

    public function __toString(): string
    {
        $uri = '';
        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }
        $authority = $this->getAuthority();
        if ($authority !== '') {
            $uri .= '//' . $authority;
        }
        $uri .= $this->path;
        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }
        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }
        return $uri;
    }
}
