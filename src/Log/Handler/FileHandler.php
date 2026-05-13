<?php

declare(strict_types=1);

namespace Lift\Log\Handler;

use Lift\Log\Formatter\FormatterInterface;
use RuntimeException;

/**
 * Writes log records to a file.
 *
 * ```php
 * new FileHandler(
 *     path: '/var/log/app.log',
 *     minLevel: 'debug',
 *     formatter: new JsonFormatter(),
 * )
 * ```
 */
final class FileHandler extends AbstractHandler
{
    /** @var resource */
    private $stream;

    public function __construct(
        private readonly string $path,
        string $minLevel = 'debug',
        ?FormatterInterface $formatter = null,
    ) {
        parent::__construct($minLevel, $formatter);

        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0755, recursive: true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create log directory: {$dir}");
        }

        $handle = fopen($this->path, 'a');
        if ($handle === false) {
            throw new RuntimeException("Cannot open log file for writing: {$this->path}");
        }
        $this->stream = $handle;
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    protected function write(string $formatted): void
    {
        fwrite($this->stream, $formatted);
    }
}
