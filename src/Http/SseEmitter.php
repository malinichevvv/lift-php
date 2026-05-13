<?php

declare(strict_types=1);

namespace Lift\Http;

/**
 * Callable wrapper used inside {@see SseResponse} generators to send events.
 *
 * ```php
 * new SseResponse(function (SseEmitter $emit) {
 *     $emit(SseEvent::json(['hello' => 'world'], 'greet'));
 * });
 * ```
 */
final class SseEmitter
{
    /** Frames sent so far (useful in tests). */
    private array $sent = [];

    public function __invoke(SseEvent $event): void
    {
        $frame = $event->encode();
        $this->sent[] = $frame;
        echo $frame;
        if (function_exists('fastcgi_finish_request')) {
            // not applicable inside SSE — just flush
        }
        flush();
    }

    /** @return string[] All encoded frames sent via this emitter (for testing). */
    public function getSent(): array
    {
        return $this->sent;
    }
}
