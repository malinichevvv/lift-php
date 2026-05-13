<?php

declare(strict_types=1);

namespace Lift\Log;

use Lift\Log\Handler\HandlerInterface;
use Lift\Log\Handler\StdoutHandler;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * PSR-3 compliant logger with multiple handler support.
 *
 * ```php
 * use Lift\Log\Logger;
 * use Lift\Log\Handler\FileHandler;
 * use Lift\Log\Handler\StdoutHandler;
 * use Lift\Log\Formatter\JsonFormatter;
 *
 * $logger = new Logger([
 *     new FileHandler('/var/log/app.log', 'debug', new JsonFormatter()),
 *     new StdoutHandler('warning'),
 * ]);
 *
 * $logger->info('User logged in', ['user_id' => 42]);
 * $logger->error('Payment failed', ['order_id' => 123, 'exception' => $e]);
 * ```
 *
 * Supports PSR-3 placeholder interpolation: `{key}` is replaced from context.
 */
final class Logger extends AbstractLogger
{
    /** @var HandlerInterface[] */
    private array $handlers;

    /**
     * @param HandlerInterface[] $handlers One or more handlers. Defaults to StdoutHandler.
     */
    public function __construct(array $handlers = [])
    {
        $this->handlers = $handlers ?: [new StdoutHandler()];
    }

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $level     = (string) $level;
        $message   = $this->interpolate((string) $message, $context);

        foreach ($this->handlers as $handler) {
            $handler->handle($level, $message, $context);
        }
    }

    public function withHandler(HandlerInterface $handler): self
    {
        $clone           = clone $this;
        $clone->handlers = [...$this->handlers, $handler];
        return $clone;
    }

    /** PSR-3 §1.2 — replace `{key}` placeholders with context values. */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $val) {
            if (is_string($val) || is_numeric($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $val;
            }
        }

        return strtr($message, $replace);
    }
}
