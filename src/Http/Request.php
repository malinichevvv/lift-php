<?php

declare(strict_types=1);

namespace Lift\Http;

use Lift\Translation\Translator;
use Lift\Validation\ValidationException;
use Lift\Validation\Validator;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\UploadedFileInterface;

final class Request extends Message implements ServerRequestInterface
{
    private array $attributes = [];
    private array $routeParams = [];

    public function __construct(
        private string $method,
        private UriInterface $uri,
        array $headers = [],
        ?StreamInterface $body = null,
        private string $requestTarget = '',
        private array $queryParams = [],
        private array $parsedBody = [],
        private array $uploadedFiles = [],
        private readonly array $serverParams = [],
        private array $cookieParams = [],
    ) {
        $this->method = strtoupper($method);
        $this->setHeaders($headers);
        // Defer Stream::empty() allocation — body is rarely read on simple GET requests.
        // Message::$body is assigned null here; getBody() materialises it on demand.
        $this->body = $body ?? new StringStream('');
    }

    public static function fromGlobals(): self
    {
        $method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri     = Uri::fromServer($_SERVER);
        $headers = self::headersFromServer($_SERVER);

        $parsedBody = [];
        $body       = null;

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $ct = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($ct, 'application/json')) {
                $raw        = (string) Stream::fromInput();
                $parsedBody = json_decode($raw, true) ?? [];
                $body       = new StringStream($raw);
            } else {
                $parsedBody = $_POST;
                $body       = Stream::fromInput();
            }
        }
        // GET/HEAD/DELETE: no body stream opened at all — Stream::empty() is lazy-created
        // in the constructor only if $body === null and someone calls getBody().

        return new self(
            method: $method,
            uri: $uri,
            headers: $headers,
            body: $body,
            queryParams: $_GET,
            parsedBody: $parsedBody,
            uploadedFiles: $_FILES !== [] ? self::normalizeFiles($_FILES) : [],
            serverParams: $_SERVER,
            cookieParams: $_COOKIE,
        );
    }

    /**
     * Create a Lift Request from any PSR-7 ServerRequestInterface.
     *
     * Enables integration with runtimes that produce PSR-7 requests
     * (RoadRunner, ReactPHP, …) without coupling Lift to their packages.
     *
     * ```php
     * // RoadRunner worker.php
     * while ($psr7Request = $psr7Worker->waitRequest()) {
     *     $request  = Request::fromPsr7($psr7Request);
     *     $response = $app->handle($request);
     *     $psr7Worker->respond($response);   // Lift Response IS a ResponseInterface
     * }
     * ```
     */
    public static function fromPsr7(\Psr\Http\Message\ServerRequestInterface $psr7): self
    {
        $parsedBody = $psr7->getParsedBody();

        $request = new self(
            method:        $psr7->getMethod(),
            uri:           $psr7->getUri(),
            headers:       $psr7->getHeaders(),
            body:          $psr7->getBody(),
            queryParams:   $psr7->getQueryParams(),
            parsedBody:    is_array($parsedBody) ? $parsedBody : [],
            uploadedFiles: $psr7->getUploadedFiles(),
            serverParams:  $psr7->getServerParams(),
            cookieParams:  $psr7->getCookieParams(),
        );

        foreach ($psr7->getAttributes() as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        return $request;
    }

    // -----------------------------------------------------------------
    // Convenience helpers
    // -----------------------------------------------------------------

    /** Get a named route parameter, e.g. /users/{id} → $req->param('id') */
    public function param(string $name, mixed $default = null): mixed
    {
        return $this->routeParams[$name] ?? $default;
    }

    /** Get all route parameters */
    public function params(): array
    {
        return $this->routeParams;
    }

    /** Get a query string value, e.g. ?page=2 → $req->query('page') */
    public function query(string $name, mixed $default = null): mixed
    {
        return $this->queryParams[$name] ?? $default;
    }

    /** Get a value from the parsed request body (form or JSON) */
    public function input(string $name, mixed $default = null): mixed
    {
        return $this->parsedBody[$name] ?? $default;
    }

    /** Get the decoded JSON body as an array */
    public function json(): array
    {
        $content = (string) $this->body;
        $data    = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /** Get a cookie value */
    public function cookie(string $name, mixed $default = null): mixed
    {
        return $this->cookieParams[$name] ?? $default;
    }

    /** Get an uploaded file by field name */
    public function file(string $name): ?UploadedFileInterface
    {
        $file = $this->uploadedFiles[$name] ?? null;
        return $file instanceof UploadedFileInterface ? $file : null;
    }

    /** Check if the request has a JSON Content-Type */
    public function isJson(): bool
    {
        return str_contains($this->getHeaderLine('Content-Type'), 'application/json');
    }

    /** Check if the request expects a JSON response */
    public function wantsJson(): bool
    {
        return str_contains($this->getHeaderLine('Accept'), 'application/json');
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * Validate the request input against the given rules.
     *
     * Merges parsed body, query params, and route params (in that priority order)
     * and runs them through the Validator.
     *
     * @param  array<string, string> $rules  e.g. ['email' => 'required|email', 'age' => 'integer|min:18']
     * @return array<string, mixed>          The validated (and potentially transformed) data.
     * @throws ValidationException           When one or more rules fail.
     */
    /**
     * @param array<string, string|string[]> $rules
     * @param array<string, string>          $messages  Custom error messages.
     * @param Translator|null                $translator Localisation; falls back to Validator's global default.
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validate(array $rules, array $messages = [], ?Translator $translator = null): array
    {
        $data = array_merge(
            $this->queryParams,
            is_array($this->parsedBody) ? $this->parsedBody : [],
            $this->routeParams,
        );

        return (new Validator($data, $rules, $messages, $translator))->validated();
    }

    /** @internal Used by the router to inject matched route params */
    public function withRouteParams(array $params): self
    {
        $clone = clone $this;
        $clone->routeParams = $params;
        return $clone;
    }

    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    // -----------------------------------------------------------------
    // PSR-7 ServerRequestInterface
    // -----------------------------------------------------------------

    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== '') {
            return $this->requestTarget;
        }
        $target = $this->uri->getPath() ?: '/';
        if ($this->uri->getQuery() !== '') {
            $target .= '?' . $this->uri->getQuery();
        }
        return $target;
    }

    public function withRequestTarget(string $requestTarget): static
    {
        $clone = clone $this;
        $clone->requestTarget = $requestTarget;
        return $clone;
    }

    public function getMethod(): string { return $this->method; }

    public function withMethod(string $method): static
    {
        $clone = clone $this;
        $clone->method = strtoupper($method);
        return $clone;
    }

    public function getUri(): UriInterface { return $this->uri; }

    public function withUri(UriInterface $uri, bool $preserveHost = false): static
    {
        $clone = clone $this;
        $clone->uri = $uri;
        if (!$preserveHost && $uri->getHost() !== '') {
            $clone = $clone->withHeader('Host', $uri->getHost());
        }
        return $clone;
    }

    public function getServerParams(): array { return $this->serverParams; }
    public function getCookieParams(): array { return $this->cookieParams; }

    public function withCookieParams(array $cookies): static
    {
        $clone = clone $this;
        $clone->cookieParams = $cookies;
        return $clone;
    }

    public function getQueryParams(): array { return $this->queryParams; }

    public function withQueryParams(array $query): static
    {
        $clone = clone $this;
        $clone->queryParams = $query;
        return $clone;
    }

    public function getUploadedFiles(): array { return $this->uploadedFiles; }

    public function withUploadedFiles(array $uploadedFiles): static
    {
        $clone = clone $this;
        $clone->uploadedFiles = $uploadedFiles;
        return $clone;
    }

    public function getParsedBody(): null|array|object { return $this->parsedBody ?: null; }

    public function withParsedBody($data): static
    {
        $clone = clone $this;
        $clone->parsedBody = is_array($data) ? $data : (array) $data;
        return $clone;
    }

    public function getAttributes(): array { return $this->attributes; }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, mixed $value): static
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    public function withoutAttribute(string $name): static
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);
        return $clone;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name           = ucwords(strtolower(str_replace('_', '-', substr($key, 5))), '-');
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name           = ucwords(strtolower(str_replace('_', '-', $key)), '-');
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    private static function normalizeFiles(array $files): array
    {
        $result = [];
        foreach ($files as $key => $file) {
            if (is_array($file['tmp_name'] ?? null)) {
                $result[$key] = self::normalizeNestedFiles($file);
            } else {
                $result[$key] = UploadedFile::fromArray($file);
            }
        }
        return $result;
    }

    private static function normalizeNestedFiles(array $file): array
    {
        $result = [];
        foreach (array_keys($file['tmp_name']) as $idx) {
            $result[$idx] = UploadedFile::fromArray([
                'tmp_name' => $file['tmp_name'][$idx],
                'size'     => $file['size'][$idx],
                'error'    => $file['error'][$idx],
                'name'     => $file['name'][$idx],
                'type'     => $file['type'][$idx],
            ]);
        }
        return $result;
    }
}
