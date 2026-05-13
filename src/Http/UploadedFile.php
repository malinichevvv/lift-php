<?php

declare(strict_types=1);

namespace Lift\Http;

use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

final class UploadedFile implements UploadedFileInterface
{
    private bool $moved = false;

    public function __construct(
        private readonly string|StreamInterface $file,
        private readonly ?int $size,
        private readonly int $error,
        private readonly ?string $clientFilename = null,
        private readonly ?string $clientMediaType = null,
    ) {}

    public static function fromArray(array $file): self
    {
        return new self(
            file: $file['tmp_name'],
            size: isset($file['size']) ? (int) $file['size'] : null,
            error: (int) ($file['error'] ?? UPLOAD_ERR_OK),
            clientFilename: $file['name'] ?? null,
            clientMediaType: $file['type'] ?? null,
        );
    }

    public function getStream(): StreamInterface
    {
        if ($this->moved) {
            throw new RuntimeException('Uploaded file has already been moved');
        }
        if ($this->file instanceof StreamInterface) {
            return $this->file;
        }
        $resource = fopen($this->file, 'r');
        if ($resource === false) {
            throw new RuntimeException("Cannot open uploaded file: {$this->file}");
        }
        return new Stream($resource);
    }

    public function moveTo(string $targetPath): void
    {
        if ($this->moved) {
            throw new RuntimeException('File has already been moved');
        }
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException("Cannot move file with upload error code {$this->error}");
        }

        if ($this->file instanceof StreamInterface) {
            $dest = fopen($targetPath, 'w');
            if ($dest === false) {
                throw new RuntimeException("Cannot open target path: {$targetPath}");
            }
            $this->file->rewind();
            while (!$this->file->eof()) {
                fwrite($dest, $this->file->read(8192));
            }
            fclose($dest);
        } elseif (PHP_SAPI === 'cli' || !is_uploaded_file($this->file)) {
            rename($this->file, $targetPath);
        } else {
            move_uploaded_file($this->file, $targetPath);
        }

        $this->moved = true;
    }

    public function getSize(): ?int          { return $this->size; }
    public function getError(): int          { return $this->error; }
    public function getClientFilename(): ?string { return $this->clientFilename; }
    public function getClientMediaType(): ?string { return $this->clientMediaType; }
}
