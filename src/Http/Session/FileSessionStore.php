<?php

declare(strict_types=1);

namespace Lift\Http\Session;

/** File-backed session store compatible with shared-nothing PHP deployments. */
class FileSessionStore implements SessionStoreInterface
{
    public function __construct(private readonly string $path)
    {
        if (!is_dir($this->path) && !mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            throw new \RuntimeException('Unable to create session directory: ' . $this->path);
        }
    }

    public function read(string $id): ?string
    {
        $file = $this->file($id);
        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            return null;
        }

        [$expires, $payload] = array_pad(explode("\n", $raw, 2), 2, '');
        if ((int) $expires < time()) {
            @unlink($file);
            return null;
        }

        return $payload;
    }

    public function write(string $id, string $payload, int $ttl): void
    {
        file_put_contents($this->file($id), (time() + $ttl) . "\n" . $payload, LOCK_EX);
    }

    public function destroy(string $id): void
    {
        @unlink($this->file($id));
    }

    public function gc(int $maxLifetime): void
    {
        foreach (glob(rtrim($this->path, '/') . '/sess_*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $handle = fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            $expires = (int) fgets($handle);
            fclose($handle);
            if ($expires < time()) {
                @unlink($file);
            }
        }
    }

    private function file(string $id): string
    {
        return rtrim($this->path, '/') . '/sess_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    }
}
