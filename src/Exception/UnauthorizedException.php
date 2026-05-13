<?php

declare(strict_types=1);

namespace Lift\Exception;

/** HTTP 401 Unauthorized — authentication is required and has not been provided or has failed. */
class UnauthorizedException extends HttpException
{
    public function __construct(string $message = 'Unauthorized', ?\Throwable $previous = null)
    {
        parent::__construct(401, $message, $previous);
    }
}
