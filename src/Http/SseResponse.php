<?php

declare(strict_types=1);

namespace Lift\Http;

/**
 * Server-Sent Events response.
 *
 * Creates a streaming HTTP response that keeps the connection open and lets
 * the server push events to the browser.
 *
 * ```php
 * $app->get('/stream', function () {
 *     return new SseResponse(function (SseEmitter $emit) {
 *         for ($i = 1; $i <= 5; $i++) {
 *             $emit(SseEvent::json(['count' => $i], 'tick'));
 *             sleep(1);
 *         }
 *     });
 * });
 * ```
 *
 * The callback receives an {@see SseEmitter} (a callable wrapper) so tests can
 * inject a spy; production code ignores it and relies on the flush strategy.
 */
final class SseResponse extends Response
{
    /** @var callable(SseEmitter): void */
    private $generator;

    /**
     * @param callable(SseEmitter): void $generator Called when the response is emitted.
     * @param int                        $status    HTTP status (almost always 200).
     */
    public function __construct(callable $generator, int $status = 200)
    {
        parent::__construct($status, '', [
            'Content-Type'      => ['text/event-stream'],
            'Cache-Control'     => ['no-cache'],
            'X-Accel-Buffering' => ['no'],
        ]);
        $this->generator = $generator;
    }

    /**
     * Stream events to the client.
     *
     * Disables output buffering, calls the generator, and flushes after every event.
     * Should be called by the HTTP emitter instead of the regular body-emit path.
     */
    public function stream(): void
    {
        // Disable any active output buffer layers.
        if (PHP_SAPI !== 'cli') {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
        }

        $emitter = new SseEmitter();
        ($this->generator)($emitter);
    }
}
