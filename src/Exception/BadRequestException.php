<?php

declare(strict_types=1);

namespace Lift\Exception;

/** HTTP 400 Bad Request — the server cannot process the request due to a client error. */
class BadRequestException extends HttpException
{
    public function __construct(string $message = 'Bad Request', ?\Throwable $previous = null)
    {
        parent::__construct(400, $message, $previous);
    }
}
