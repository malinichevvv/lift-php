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
    /** @var resource */
    private mixed $stream;

    public function __construct(string $minLevel = 'debug', ?FormatterInterface $formatter = null)
    {
        parent::__construct($minLevel, $formatter);
        // STDOUT is only defined in CLI; in PHP-FPM use the stdout pipe directly.
        $this->stream = \defined('STDOUT') ? \STDOUT : fopen('php://stdout', 'a');
    }

    protected function write(string $formatted): void
    {
        fwrite($this->stream, $formatted);
    }
}
