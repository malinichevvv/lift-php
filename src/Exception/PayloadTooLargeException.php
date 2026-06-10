<?php

declare(strict_types=1);

namespace Lift\Exception;

/** HTTP 413 Payload Too Large. */
final class PayloadTooLargeException extends HttpException
{
    public function __construct(string $message = 'Payload Too Large')
    {
        parent::__construct(413, $message);
    }
}
