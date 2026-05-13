<?php

declare(strict_types=1);

namespace Lift\Log\Formatter;

/**
 * Human-readable single-line log formatter.
 *
 * Output: `[2026-05-13 12:00:00] INFO  Request processed  {"user_id":42}`
 */
final class LineFormatter implements FormatterInterface
{
    public function __construct(
        private readonly string $dateFormat = 'Y-m-d H:i:s',
        private readonly bool $includeContext = true,
    ) {}

    public function format(string $level, string $message, array $context): string
    {
        $ts  = date($this->dateFormat);
        $lvl = str_pad(strtoupper($level), 9);
        $line = "[{$ts}] {$lvl} {$message}";

        if ($this->includeContext && !empty($context)) {
            $line .= '  ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $line . PHP_EOL;
    }
}
