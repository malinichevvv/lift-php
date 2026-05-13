<?php

declare(strict_types=1);

namespace Lift\Log\Handler;

use Lift\Log\Formatter\FormatterInterface;

/**
 * Writes log records to stdout (or stderr for ERROR and above).
 *
 * ```php
 * new StdoutHandler(minLevel: 'warning')
 * ```
 */
final class StdoutHandler extends AbstractHandler
{
    public function __construct(string $minLevel = 'debug', ?FormatterInterface $formatter = null)
    {
        parent::__construct($minLevel, $formatter);
    }

    protected function write(string $formatted): void
    {
        fwrite(STDOUT, $formatted);
    }
}
