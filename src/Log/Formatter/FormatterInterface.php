<?php

declare(strict_types=1);

namespace Lift\Log\Formatter;

interface FormatterInterface
{
    /**
     * Format a log record into a string ready to be written by a handler.
     *
     * @param string               $level   PSR-3 log level string.
     * @param string               $message Interpolated message.
     * @param array<string, mixed> $context
     */
    public function format(string $level, string $message, array $context): string;
}
