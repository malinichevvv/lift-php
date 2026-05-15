<?php

declare(strict_types=1);

namespace Lift\Log\Handler;

use Lift\Log\Formatter\FormatterInterface;
use RuntimeException;

/**
 * Writes log records to daily-rotated files.
 *
 * Each day a new file is opened automatically:
 * `/var/log/app.log` → `/var/log/app-2026-05-15.log`
 *
 * Old files are pruned when `$maxFiles` is greater than zero.
 *
 * ```php
 * new RotatingFileHandler(
 *     path: storage_path('logs/app.log'),
 *     minLevel: 'info',
 *     formatter: new JsonFormatter(),
 *     maxFiles: 30,
 * )
 * ```
 */
final class RotatingFileHandler extends AbstractHandler
{
    /** @var resource|null */
    private mixed $stream = null;
    private string $currentDate = '';

    public function __construct(
        private readonly string $path,
        string $minLevel = 'debug',
        ?FormatterInterface $formatter = null,
        private readonly int $maxFiles = 0,
    ) {
        parent::__construct($minLevel, $formatter);
    }

    public function __destruct()
    {
        $this->closeStream();
    }

    protected function write(string $formatted): void
    {
        $today = date('Y-m-d');

        if ($this->currentDate !== $today) {
            $this->rotate($today);
        }

        fwrite($this->stream, $formatted);
    }

    private function rotate(string $date): void
    {
        $this->closeStream();
        $this->currentDate = $date;

        $file = $this->resolveFilePath($date);
        $dir  = dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0755, recursive: true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create log directory: {$dir}");
        }

        $handle = fopen($file, 'a');
        if ($handle === false) {
            throw new RuntimeException("Cannot open log file for writing: {$file}");
        }

        $this->stream = $handle;

        if ($this->maxFiles > 0) {
            $this->pruneOldFiles();
        }
    }

    /**
     * Derive the date-stamped file path.
     *
     * `/var/log/app.log` → `/var/log/app-2026-05-15.log`
     * `/var/log/app`     → `/var/log/app-2026-05-15`
     */
    private function resolveFilePath(string $date): string
    {
        $ext = pathinfo($this->path, PATHINFO_EXTENSION);
        if ($ext !== '') {
            $base = substr($this->path, 0, -strlen($ext) - 1);
            return "{$base}-{$date}.{$ext}";
        }
        return "{$this->path}-{$date}";
    }

    private function pruneOldFiles(): void
    {
        $ext     = pathinfo($this->path, PATHINFO_EXTENSION);
        $base    = $ext !== '' ? substr($this->path, 0, -strlen($ext) - 1) : $this->path;
        $pattern = $base . '-????-??-??' . ($ext !== '' ? ".{$ext}" : '');

        $files = glob($pattern) ?: [];
        rsort($files);

        foreach (array_slice($files, $this->maxFiles) as $old) {
            @unlink($old);
        }
    }

    private function closeStream(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
            $this->stream = null;
        }
    }
}
