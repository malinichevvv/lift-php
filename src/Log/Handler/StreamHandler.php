<?php

declare(strict_types=1);

namespace Lift\Log\Handler;

use Lift\Log\Formatter\FormatterInterface;
use RuntimeException;

/**
 * Writes log records to any writable PHP stream resource.
 *
 * Useful for piping logs into memory streams in tests, or into any
 * file/socket opened externally.
 *
 * ```php
 * // Test usage — capture log output in memory
 * $stream = fopen('php://memory', 'rw');
 * $logger = new Logger([new StreamHandler($stream, 'debug')]);
 * $logger->info('Hello');
 * rewind($stream);
 * echo stream_get_contents($stream); // [... INFO Hello]
 *
 * // Named file
 * $logger = new Logger([new StreamHandler(fopen('/tmp/app.log', 'a'))]);
 * ```
 */
final class StreamHandler extends AbstractHandler
{
    /** @param resource $stream */
    public function __construct(
        private readonly mixed $stream,
        string $minLevel = 'debug',
        ?FormatterInterface $formatter = null,
    ) {
        if (!is_resource($stream)) {
            throw new RuntimeException('StreamHandler requires a valid resource.');
        }
        parent::__construct($minLevel, $formatter);
    }

    protected function write(string $formatted): void
    {
        fwrite($this->stream, $formatted);
    }
}