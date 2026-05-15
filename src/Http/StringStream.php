<?php

declare(strict_types=1);

namespace Lift\Http;

use Psr\Http\Message\StreamInterface;

/**
 * Lightweight in-memory stream backed by a plain PHP string.
 *
 * Drop-in replacement for `Stream::fromString()` that avoids the fopen + fwrite +
 * rewind syscall sequence. Every Response factory method (json, html, text) uses
 * this internally, so the gain applies to every request.
 *
 * Fully implements StreamInterface — including seek/tell/rewind — so middleware
 * that reads the response body continues to work without changes.
 */
final class StringStream implements StreamInterface
{
    private int $position = 0;
    private bool $detached = false;

    public function __construct(private string $content) {}

    public function __toString(): string
    {
        return $this->detached ? '' : $this->content;
    }

    public function close(): void {}

    public function detach(): mixed
    {
        $this->detached = true;
        return null;
    }

    public function getSize(): ?int
    {
        return $this->detached ? null : strlen($this->content);
    }

    public function tell(): int
    {
        if ($this->detached) {
            throw new \RuntimeException('Stream is detached');
        }
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->detached || $this->position >= strlen($this->content);
    }

    public function isSeekable(): bool
    {
        return !$this->detached;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if ($this->detached) {
            throw new \RuntimeException('Stream is detached');
        }
        $len = strlen($this->content);
        $this->position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $len + $offset,
            default  => throw new \RuntimeException("Invalid whence: {$whence}"),
        };
        if ($this->position < 0) {
            $this->position = 0;
        }
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('StringStream is read-only');
    }

    public function isReadable(): bool
    {
        return !$this->detached;
    }

    public function read(int $length): string
    {
        if ($this->detached) {
            throw new \RuntimeException('Stream is detached');
        }
        $chunk          = substr($this->content, $this->position, $length);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function getContents(): string
    {
        if ($this->detached) {
            throw new \RuntimeException('Stream is detached');
        }
        $remaining      = substr($this->content, $this->position);
        $this->position = strlen($this->content);
        return $remaining;
    }

    public function getMetadata(?string $key = null): mixed
    {
        $meta = [
            'wrapper_type' => 'string',
            'stream_type'  => 'MEMORY',
            'mode'         => 'r',
            'unread_bytes' => 0,
            'seekable'     => true,
            'uri'          => '',
            'timed_out'    => false,
            'blocked'      => false,
            'eof'          => $this->eof(),
        ];
        return $key === null ? $meta : ($meta[$key] ?? null);
    }
}