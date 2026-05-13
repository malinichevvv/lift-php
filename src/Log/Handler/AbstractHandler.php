<?php

declare(strict_types=1);

namespace Lift\Log\Handler;

use Lift\Log\Formatter\FormatterInterface;
use Lift\Log\Formatter\LineFormatter;
use Psr\Log\LogLevel;

abstract class AbstractHandler implements HandlerInterface
{
    private const LEVELS = [
        LogLevel::DEBUG     => 0,
        LogLevel::INFO      => 1,
        LogLevel::NOTICE    => 2,
        LogLevel::WARNING   => 3,
        LogLevel::ERROR     => 4,
        LogLevel::CRITICAL  => 5,
        LogLevel::ALERT     => 6,
        LogLevel::EMERGENCY => 7,
    ];

    protected readonly FormatterInterface $formatter;

    public function __construct(
        protected readonly string $minLevel = LogLevel::DEBUG,
        ?FormatterInterface $formatter = null,
    ) {
        $this->formatter = $formatter ?? new LineFormatter();
    }

    public function isHandling(string $level): bool
    {
        return (self::LEVELS[$level] ?? 0) >= (self::LEVELS[$this->minLevel] ?? 0);
    }

    public function handle(string $level, string $message, array $context): void
    {
        if (!$this->isHandling($level)) {
            return;
        }
        $this->write($this->formatter->format($level, $message, $context));
    }

    abstract protected function write(string $formatted): void;
}
