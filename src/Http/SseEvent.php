<?php

declare(strict_types=1);

namespace Lift\Http;

/**
 * Builds a single Server-Sent Event frame.
 *
 * ```php
 * $frame = SseEvent::create('Hello, world!')
 *     ->event('message')
 *     ->id('42')
 *     ->retry(3000)
 *     ->encode();
 * // data: Hello, world!\n
 * // event: message\n
 * // id: 42\n
 * // retry: 3000\n
 * // \n
 * ```
 */
final class SseEvent
{
    private ?string $event = null;
    private ?string $id    = null;
    private ?int    $retry = null;

    public function __construct(private readonly string $data) {}

    public static function create(string $data): self
    {
        return new self($data);
    }

    /** Event type field (`event: <name>`). */
    public function event(string $event): self
    {
        $clone        = clone $this;
        $clone->event = $event;
        return $clone;
    }

    /** Event ID field (`id: <id>`). */
    public function id(string $id): self
    {
        $clone     = clone $this;
        $clone->id = $id;
        return $clone;
    }

    /** Reconnection time in milliseconds (`retry: <ms>`). */
    public function retry(int $ms): self
    {
        $clone        = clone $this;
        $clone->retry = $ms;
        return $clone;
    }

    /**
     * Encode the event to its wire representation (ready to flush to output).
     *
     * Multi-line data values are split into multiple `data:` lines as per the spec.
     */
    public function encode(): string
    {
        $out = '';

        if ($this->event !== null) {
            $out .= "event: {$this->event}\n";
        }

        if ($this->id !== null) {
            $out .= "id: {$this->id}\n";
        }

        if ($this->retry !== null) {
            $out .= "retry: {$this->retry}\n";
        }

        foreach (explode("\n", $this->data) as $line) {
            $out .= "data: {$line}\n";
        }

        $out .= "\n";
        return $out;
    }

    /**
     * Convenience: encode a JSON payload.
     *
     * @param mixed $data JSON-serialisable value.
     */
    public static function json(mixed $data, ?string $event = null): self
    {
        $encoded = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $frame   = new self($encoded);
        return $event !== null ? $frame->event($event) : $frame;
    }
}
