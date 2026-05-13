<?php

declare(strict_types=1);

namespace Lift\Exception;

/** HTTP 403 Forbidden — the client does not have permission to access the requested resource. */
class ForbiddenException extends HttpException
{
    public function __construct(string $message = 'Forbidden', ?\Throwable $previous = null)
    {
        parent::__construct(403, $message, $previous);
    }
}
