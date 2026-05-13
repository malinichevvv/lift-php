<?php

declare(strict_types=1);

namespace Lift\Exception;

/** HTTP 404 Not Found — thrown when a requested resource does not exist. */
class NotFoundException extends HttpException
{
    public function __construct(string $message = 'Not Found', ?\Throwable $previous = null)
    {
        parent::__construct(404, $message, $previous);
    }
}
