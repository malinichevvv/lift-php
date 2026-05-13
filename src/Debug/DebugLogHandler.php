<?php

declare(strict_types=1);

namespace Lift\Debug;

use Lift\Log\Handler\AbstractHandler;
use Psr\Log\LogLevel;

/**
 * PSR-3 log handler that forwards log entries to the debug toolbar collector.
 *
 * Add this handler to your logger during bootstrap when debug mode is active:
 *
 * ```php
 * $collector = $app->container()->make(DebugCollector::class);
 * $logger->pushHandler(new DebugLogHandler($collector));
 * ```
 *
 * All log entries at or above `$minLevel` will appear in the toolbar's Logs tab.
 */
final class DebugLogHandler extends AbstractHandler
{
    public function __construct(
        private readonly DebugCollector $collector,
        string $minLevel = LogLevel::DEBUG,
    ) {
        parent::__construct($minLevel);
    }

    public function handle(string $level, string $message, array $context): void
    {
        if ($this->isHandling($level)) {
            $this->collector->recordLog($level, $message, $context);
        }
    }

    protected function write(string $formatted): void
    {
        // Intentionally empty — we override handle() to bypass formatting.
    }
}
