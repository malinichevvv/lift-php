<?php

declare(strict_types=1);

namespace Lift\Http\Session;

/**
 * File-backed session store compatible with shared-nothing PHP deployments.
 *
 * Each session is stored as a single file named `sess_{id}` inside the
 * configured directory. The file format is:
 * ```
 * {unix_expiry_timestamp}\n{serialised_payload}
 * ```
 *
 * The directory is created automatically with mode `0775` when it does not exist.
 * Expired sessions are removed lazily on read and eagerly via {@see gc()}.
 */
class FileSessionStore implements SessionStoreInterface
{
    /**
     * @param string $path Absolute path to the directory where session files are stored.
     * @throws \RuntimeException When the directory cannot be created.
     */
    public function __construct(private readonly string $path)
    {
        if (!is_dir($this->path) && !mkdir($this->path, 0775, true) && !is_dir($this->path)) {
            throw new \RuntimeException('Unable to create session directory: ' . $this->path);
        }
    }

    /**
     * Read the session payload from disk, returning `null` when absent or expired.
     *
     * Expired session files are deleted on read to avoid accumulation.
     */
    public function read(string $id): ?string
    {
        $file = $this->file($id);
        if (!is_file($file)) {
            return null;
        }

        // Shared lock so a concurrent write() (which holds LOCK_EX) cannot be
        // observed half-written.
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return null;
        }
        flock($handle, LOCK_SH);
        $raw = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
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

    /** Write the session payload to disk, prepending the expiry timestamp. */
    public function write(string $id, string $payload, int $ttl): void
    {
        $file = $this->file($id);
        file_put_contents($file, (time() + $ttl) . "\n" . $payload, LOCK_EX);
        // Session files contain authentication state — keep them private to the
        // owning process user regardless of the process umask.
        @chmod($file, 0600);
    }

    /** Delete the session file, silently ignoring missing files. */
    public function destroy(string $id): void
    {
        @unlink($this->file($id));
    }

    /** Scan the session directory and remove all expired session files. */
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
