<?php

declare(strict_types=1);

namespace Lift\Log\Handler;

/**
 * Contract for log record handlers.
 *
 * A handler receives a log record (level + message + context), decides whether
 * it should process it based on a minimum log level, and writes the formatted
 * output to its destination (file, stdout, remote service, etc.).
 */
interface HandlerInterface
{
    /** Returns true if this handler should process the given log level. */
    public function isHandling(string $level): bool;

    /** Write a formatted log record. */
    public function handle(string $level, string $message, array $context): void;
}
