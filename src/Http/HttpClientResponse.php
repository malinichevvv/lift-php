<?php

declare(strict_types=1);

namespace Lift\Http;

/**
 * Immutable HTTP response returned by {@see HttpClient}.
 *
 * ```php
 * $response = HttpClient::new()->get('https://api.example.com/users/1');
 *
 * if ($response->ok()) {
 *     $user = $response->json();
 * }
 * ```
 */
final class HttpClientResponse
{
    /**
     * @param int                      $status  HTTP status code.
     * @param string                   $body    Raw response body.
     * @param array<string, string[]>  $headers Response headers (name → list of values).
     */
    public function __construct(
        private readonly int    $status,
        private readonly string $body,
        private readonly array  $headers,
    ) {}

    /** Return the HTTP status code. */
    public function status(): int
    {
        return $this->status;
    }

    /** Return the raw response body string. */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Decode and return the response body as an associative array.
     *
     * @throws \RuntimeException When the body is not valid JSON.
     */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Response body is not valid JSON: ' . substr($this->body, 0, 200));
        }
        return $data;
    }

    /**
     * Return the first value of a response header (case-insensitive), or `null` when absent.
     */
    public function header(string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($this->headers as $key => $values) {
            if (strtolower($key) === $lower) {
                return $values[0] ?? null;
            }
        }
        return null;
    }

    /**
     * Return all values for a response header (case-insensitive).
     *
     * @return string[]
     */
    public function headerValues(string $name): array
    {
        $lower = strtolower($name);
        foreach ($this->headers as $key => $values) {
            if (strtolower($key) === $lower) {
                return $values;
            }
        }
        return [];
    }

    /** Return all response headers. */
    public function headers(): array
    {
        return $this->headers;
    }

    /** Return `true` when the status code is 2xx. */
    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** Return `true` when the status code is 4xx or 5xx. */
    public function failed(): bool
    {
        return $this->status >= 400;
    }

    /** Return `true` when the status code is 4xx. */
    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    /** Return `true` when the status code is 5xx. */
    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    /**
     * Throw an exception when the response is a 4xx or 5xx.
     *
     * @throws \RuntimeException
     */
    public function throw(): static
    {
        if ($this->failed()) {
            throw new \RuntimeException(
                "HTTP request failed with status {$this->status}: " . substr($this->body, 0, 200)
            );
        }
        return $this;
    }
}
