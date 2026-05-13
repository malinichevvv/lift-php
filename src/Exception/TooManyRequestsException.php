<?php

declare(strict_types=1);

namespace Lift\Exception;

/** HTTP 429 Too Many Requests — the client has sent too many requests in a given time window. */
class TooManyRequestsException extends HttpException
{
    /**
     * @param int|null $retryAfter Optional seconds to wait before retrying (written to `Retry-After` header by the error handler).
     */
    public function __construct(
        string $message = 'Too Many Requests',
        public readonly ?int $retryAfter = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(429, $message, $previous);
    }
}
