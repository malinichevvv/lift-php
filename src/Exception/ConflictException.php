<?php

declare(strict_types=1);

namespace Lift\Exception;

/** HTTP 409 Conflict — the request conflicts with the current state of the server (e.g. duplicate resource). */
class ConflictException extends HttpException
{
    public function __construct(string $message = 'Conflict', ?\Throwable $previous = null)
    {
        parent::__construct(409, $message, $previous);
    }
}
