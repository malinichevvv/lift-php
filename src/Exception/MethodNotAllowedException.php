<?php

declare(strict_types=1);

namespace Lift\Exception;

class MethodNotAllowedException extends HttpException
{
    public function __construct(string $message = 'Method Not Allowed', ?\Throwable $previous = null)
    {
        parent::__construct(405, $message, $previous);
    }
}
