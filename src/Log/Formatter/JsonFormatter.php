<?php

declare(strict_types=1);

namespace Lift\Log\Formatter;

/**
 * JSON (newline-delimited) log formatter.
 *
 * Each log record is emitted as a single JSON object on its own line — ideal
 * for structured log aggregators (Datadog, Loki, CloudWatch, etc.).
 *
 * Output: `{"ts":"2026-05-13T12:00:00+00:00","level":"info","message":"...","context":{...}}`
 */
final class JsonFormatter implements FormatterInterface
{
    public function format(string $level, string $message, array $context): string
    {
        $record = [
            'ts'      => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339_EXTENDED),
            'level'   => $level,
            'message' => $message,
        ];

        if (!empty($context)) {
            // Serialize exceptions to a readable structure
            if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
                $e = $context['exception'];
                $context['exception'] = [
                    'class'   => get_class($e),
                    'message' => $e->getMessage(),
                    'code'    => $e->getCode(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ];
            }
            $record['context'] = $context;
        }

        return json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
}
