<?php

declare(strict_types=1);

namespace Lift\Log\Handler;

interface HandlerInterface
{
    /** Returns true if this handler should process the given log level. */
    public function isHandling(string $level): bool;

    /** Write a formatted log record. */
    public function handle(string $level, string $message, array $context): void;
}
