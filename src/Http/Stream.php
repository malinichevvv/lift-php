<?php

declare(strict_types=1);

namespace Lift\Http;

use Psr\Http\Message\StreamInterface;
use RuntimeException;
use InvalidArgumentException;

final class Stream implements StreamInterface
{
    /** @var resource|null */
    private $resource;

    public function __construct(mixed $resource)
    {
        if (!is_resource($resource)) {
            throw new InvalidArgumentException('Stream must be a valid resource');
        }
        $this->resource = $resource;
    }

    public static function fromString(string $content): self
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new RuntimeException('Unable to create temp stream');
        }
        fwrite($resource, $content);
        rewind($resource);
        return new self($resource);
    }

    public static function empty(): self
    {
        $resource = fopen('php://temp', 'r+');
        if ($resource === false) {
            throw new RuntimeException('Unable to create temp stream');
        }
        return new self($resource);
    }

    public static function fromInput(): self
    {
        $resource = fopen('php://input', 'r');
        if ($resource === false) {
            throw new RuntimeException('Unable to open php://input');
        }
        return new self($resource);
    }

    public function __toString(): string
    {
        if ($this->resource === null) {
            return '';
        }
        try {
            if ($this->isSeekable()) {
                rewind($this->resource);
            }
            return stream_get_contents($this->resource) ?: '';
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->resource !== null) {
            fclose($this->resource);
            $this->resource = null;
        }
    }

    public function detach(): mixed
    {
        $resource = $this->resource;
        $this->resource = null;
        return $resource;
    }

    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }
        $stats = fstat($this->resource);
        return $stats !== false ? $stats['size'] : null;
    }

    public function tell(): int
    {
        $this->assertAttached();
        $pos = ftell($this->resource);
        if ($pos === false) {
            throw new RuntimeException('Unable to determine stream position');
        }
        return $pos;
    }

    public function eof(): bool
    {
        return $this->resource === null || feof($this->resource);
    }

    public function isSeekable(): bool
    {
        if ($this->resource === null) {
            return false;
        }
        return (bool) stream_get_meta_data($this->resource)['seekable'];
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->assertAttached();
        if (!$this->isSeekable()) {
            throw new RuntimeException('Stream is not seekable');
        }
        if (fseek($this->resource, $offset, $whence) === -1) {
            throw new RuntimeException('Unable to seek stream');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        if ($this->resource === null) {
            return false;
        }
        $mode = stream_get_meta_data($this->resource)['mode'];
        return str_contains($mode, 'w') || str_contains($mode, 'a') || str_contains($mode, '+');
    }

    public function write(string $string): int
    {
        $this->assertAttached();
        if (!$this->isWritable()) {
            throw new RuntimeException('Stream is not writable');
        }
        $bytes = fwrite($this->resource, $string);
        if ($bytes === false) {
            throw new RuntimeException('Unable to write to stream');
        }
        return $bytes;
    }

    public function isReadable(): bool
    {
        if ($this->resource === null) {
            return false;
        }
        $mode = stream_get_meta_data($this->resource)['mode'];
        return str_contains($mode, 'r') || str_contains($mode, '+');
    }

    public function read(int $length): string
    {
        $this->assertAttached();
        if (!$this->isReadable()) {
            throw new RuntimeException('Stream is not readable');
        }
        $data = fread($this->resource, $length);
        if ($data === false) {
            throw new RuntimeException('Unable to read from stream');
        }
        return $data;
    }

    public function getContents(): string
    {
        $this->assertAttached();
        $contents = stream_get_contents($this->resource);
        if ($contents === false) {
            throw new RuntimeException('Unable to read stream contents');
        }
        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        if ($this->resource === null) {
            return $key !== null ? null : [];
        }
        $meta = stream_get_meta_data($this->resource);
        return $key !== null ? ($meta[$key] ?? null) : $meta;
    }

    private function assertAttached(): void
    {
        if ($this->resource === null) {
            throw new RuntimeException('Stream is detached');
        }
    }
}
