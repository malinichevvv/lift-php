<?php

declare(strict_types=1);

namespace Lift\Filesystem;

/**
 * Contract for filesystem adapters.
 *
 * Implementations must provide atomic read/write operations and return
 * consistent types so higher-level code can swap adapters (local → S3, etc.)
 * without changing application logic.
 */
interface FilesystemInterface
{
    /**
     * Write `$contents` to `$path`, creating intermediate directories as needed.
     *
     * @throws \RuntimeException On write failure.
     */
    public function put(string $path, string $contents): void;

    /**
     * Append `$contents` to `$path`, creating the file when it does not exist.
     *
     * @throws \RuntimeException On write failure.
     */
    public function append(string $path, string $contents): void;

    /**
     * Read and return the file contents.
     *
     * @throws \RuntimeException When the file does not exist or cannot be read.
     */
    public function get(string $path): string;

    /**
     * Return `true` when the path exists as a file.
     */
    public function exists(string $path): bool;

    /**
     * Delete a file.
     *
     * Must not throw when the file is already absent.
     */
    public function delete(string $path): void;

    /**
     * Copy a file from `$source` to `$destination`.
     *
     * @throws \RuntimeException On failure.
     */
    public function copy(string $source, string $destination): void;

    /**
     * Move (rename) a file from `$source` to `$destination`.
     *
     * @throws \RuntimeException On failure.
     */
    public function move(string $source, string $destination): void;

    /**
     * Return the file size in bytes.
     *
     * @throws \RuntimeException When the file does not exist.
     */
    public function size(string $path): int;

    /**
     * Return the last-modified Unix timestamp of a file.
     *
     * @throws \RuntimeException When the file does not exist.
     */
    public function lastModified(string $path): int;

    /**
     * List files in a directory (non-recursive by default).
     *
     * @return string[] Absolute or relative paths depending on the adapter.
     */
    public function files(string $directory = '', bool $recursive = false): array;

    /**
     * List subdirectory names inside a directory.
     *
     * @return string[]
     */
    public function directories(string $directory = ''): array;

    /**
     * Create a directory (and any intermediate paths) with the given mode.
     *
     * @throws \RuntimeException On failure.
     */
    public function makeDirectory(string $path, int $mode = 0755): void;

    /**
     * Delete a directory and all of its contents recursively.
     *
     * Must not throw when the directory is already absent.
     */
    public function deleteDirectory(string $path): void;

    /**
     * Return a public URL for the given path, or `null` when the adapter does
     * not support public URLs (e.g. local private storage).
     */
    public function url(string $path): ?string;
}
