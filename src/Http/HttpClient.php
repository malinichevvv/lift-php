<?php

declare(strict_types=1);

namespace Lift\Http;

/**
 * Fluent HTTP client for outgoing requests.
 *
 * Uses cURL when available, falling back to `file_get_contents` with a stream
 * context. Immutable modifiers return clones so the same base client can be
 * reused safely across requests.
 *
 * ```php
 * $client = HttpClient::new()
 *     ->withToken('Bearer', $accessToken)
 *     ->timeout(10);
 *
 * $users = $client->get('https://api.example.com/users')->throw()->json();
 * $post  = $client->post('https://api.example.com/posts', ['title' => 'Hello'])->json();
 * ```
 */
final class HttpClient
{
    /** @var array<string, string> */
    private array $headers    = [];
    private int   $timeoutSec = 30;
    private int   $maxRetries = 0;
    private bool  $followRedirects = true;
    private bool  $verifySsl = true;

    // -----------------------------------------------------------------
    // Static factory
    // -----------------------------------------------------------------

    /** Create a new HttpClient instance. */
    public static function new(): self
    {
        return new self();
    }

    // -----------------------------------------------------------------
    // Fluent configuration (each returns a clone — immutable API)
    // -----------------------------------------------------------------

    /**
     * Merge additional request headers.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        $clone = clone $this;
        $clone->headers = array_merge($this->headers, $headers);
        return $clone;
    }

    /**
     * Add an `Authorization` header with a type/token pair.
     *
     * ```php
     * $client->withToken('Bearer', $jwt);
     * $client->withToken('Basic', base64_encode("user:pass"));
     * ```
     */
    public function withToken(string $type, string $token): static
    {
        return $this->withHeaders(['Authorization' => "{$type} {$token}"]);
    }

    /** Add HTTP Basic authentication. */
    public function withBasicAuth(string $username, string $password): static
    {
        return $this->withToken('Basic', base64_encode("{$username}:{$password}"));
    }

    /** Set `Content-Type` and `Accept` headers to `application/json`. */
    public function asJson(): static
    {
        return $this->withHeaders([
            'Content-Type' => 'application/json; charset=utf-8',
            'Accept'       => 'application/json',
        ]);
    }

    /** Set the connection timeout in seconds (default: 30). */
    public function timeout(int $seconds): static
    {
        $clone = clone $this;
        $clone->timeoutSec = $seconds;
        return $clone;
    }

    /**
     * Retry failed (5xx) requests.
     *
     * @param int $times Total attempts (including the first).
     */
    public function retry(int $times): static
    {
        $clone = clone $this;
        $clone->maxRetries = max(0, $times - 1);
        return $clone;
    }

    /** Disable SSL certificate verification (development / testing only). */
    public function withoutVerifying(): static
    {
        $clone = clone $this;
        $clone->verifySsl = false;
        return $clone;
    }

    /** Disable automatic redirect following. */
    public function withoutRedirecting(): static
    {
        $clone = clone $this;
        $clone->followRedirects = false;
        return $clone;
    }

    // -----------------------------------------------------------------
    // HTTP methods
    // -----------------------------------------------------------------

    /**
     * Send a GET request.
     *
     * @param array<string, mixed> $query Query-string parameters to append to the URL.
     */
    public function get(string $url, array $query = []): HttpClientResponse
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->send('GET', $url);
    }

    /**
     * Send a POST request.
     *
     * Arrays and objects are JSON-encoded automatically; strings are sent raw.
     */
    public function post(string $url, mixed $data = null, array $headers = []): HttpClientResponse
    {
        return $this->send('POST', $url, $data, $headers);
    }

    /** Send a PUT request. */
    public function put(string $url, mixed $data = null, array $headers = []): HttpClientResponse
    {
        return $this->send('PUT', $url, $data, $headers);
    }

    /** Send a PATCH request. */
    public function patch(string $url, mixed $data = null, array $headers = []): HttpClientResponse
    {
        return $this->send('PATCH', $url, $data, $headers);
    }

    /** Send a DELETE request. */
    public function delete(string $url, array $headers = []): HttpClientResponse
    {
        return $this->send('DELETE', $url, null, $headers);
    }

    /** Send a HEAD request. */
    public function head(string $url): HttpClientResponse
    {
        return $this->send('HEAD', $url);
    }

    /**
     * Build and dispatch a raw HTTP request with optional body and headers.
     *
     * Retries 5xx responses up to {@see retry()} times (100 ms between attempts).
     *
     * @param array<string, string> $extraHeaders
     */
    public function send(string $method, string $url, mixed $body = null, array $extraHeaders = []): HttpClientResponse
    {
        $attempt  = 0;
        $response = null;

        do {
            $response = $this->dispatch($method, $url, $body, $extraHeaders);
            if (!$response->serverError() || $attempt >= $this->maxRetries) {
                break;
            }
            $attempt++;
            usleep(100_000);
        } while ($attempt <= $this->maxRetries);

        return $response;
    }

    // -----------------------------------------------------------------
    // Internal transport
    // -----------------------------------------------------------------

    /** @param array<string, string> $extraHeaders */
    private function dispatch(string $method, string $url, mixed $body, array $extraHeaders): HttpClientResponse
    {
        $headers = array_merge($this->headers, $extraHeaders);

        $rawBody = null;
        if ($body !== null) {
            if (is_string($body)) {
                $rawBody = $body;
            } else {
                $rawBody = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $headers['Content-Type'] ??= 'application/json; charset=utf-8';
            }
            $headers['Content-Length'] = (string) strlen($rawBody);
        }

        return extension_loaded('curl')
            ? $this->curlSend($method, $url, $rawBody, $headers)
            : $this->streamSend($method, $url, $rawBody, $headers);
    }

    /** @param array<string, string> $headers */
    private function curlSend(string $method, string $url, ?string $body, array $headers): HttpClientResponse
    {
        $ch = curl_init();

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $this->timeoutSec,
            CURLOPT_FOLLOWLOCATION => $this->followRedirects,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_USERAGENT      => 'Lift-HttpClient/1.0',
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw        = (string) curl_exec($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $errno      = curl_errno($ch);
        $error      = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new \RuntimeException("cURL error [{$errno}]: {$error}");
        }

        $rawHeaders   = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);

        return new HttpClientResponse($status, $responseBody, $this->parseHeaders($rawHeaders));
    }

    /** @param array<string, string> $headers */
    private function streamSend(string $method, string $url, ?string $body, array $headers): HttpClientResponse
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $opts = [
            'http' => [
                'method'          => $method,
                'header'          => implode("\r\n", $headerLines),
                'content'         => $body,
                'timeout'         => $this->timeoutSec,
                'follow_location' => $this->followRedirects ? 1 : 0,
                'ignore_errors'   => true,
            ],
            'ssl'  => [
                'verify_peer'      => $this->verifySsl,
                'verify_peer_name' => $this->verifySsl,
            ],
        ];

        $context  = stream_context_create($opts);
        $result   = @file_get_contents($url, false, $context);

        if ($result === false) {
            throw new \RuntimeException("HTTP request to [{$url}] failed");
        }

        $status          = 200;
        $parsedHeaders   = [];

        /** @phpstan-ignore-next-line */
        if (isset($http_response_header) && is_array($http_response_header)) {
            /** @phpstan-ignore-next-line */
            foreach ($http_response_header as $line) {
                if (str_starts_with((string) $line, 'HTTP/')) {
                    $parts  = explode(' ', (string) $line);
                    $status = (int) ($parts[1] ?? 200);
                }
            }
            /** @phpstan-ignore-next-line */
            $parsedHeaders = $this->parseHeaders(implode("\r\n", $http_response_header));
        }

        return new HttpClientResponse($status, $result, $parsedHeaders);
    }

    /**
     * Parse a raw header block into `name => [values]` map.
     *
     * @return array<string, string[]>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)][] = trim($value);
            }
        }
        return $headers;
    }
}
