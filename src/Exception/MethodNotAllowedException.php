<?php

declare(strict_types=1);

namespace Lift\Exception;

/** HTTP 405 Method Not Allowed — thrown when a route exists but not for the given HTTP method. */
class MethodNotAllowedException extends HttpException
{
    /** @param list<string> $allowedMethods */
    public function __construct(
        string $message = 'Method Not Allowed',
        private readonly array $allowedMethods = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(405, $message, $previous);
    }

    /** @return list<string> */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
