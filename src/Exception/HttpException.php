<?php

declare(strict_types=1);

namespace Lift\Exception;

/**
 * Base class for HTTP-layer exceptions that carry an HTTP status code.
 *
 * Throw an `HttpException` (or a named subclass) from a route handler to return
 * a specific HTTP status without going through the normal response flow.
 * `App::run()` catches it and emits the appropriate error response.
 *
 * ```php
 * throw new HttpException(403, 'Access denied');
 * throw new NotFoundException();          // 404
 * throw new MethodNotAllowedException();  // 405
 * ```
 */
class HttpException extends \RuntimeException
{
    /**
     * @param int             $statusCode HTTP status code (4xx or 5xx).
     * @param string          $message    Optional human-readable message.
     * @param \Throwable|null $previous   Optional causing exception.
     */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /** Return the HTTP status code associated with this exception. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
