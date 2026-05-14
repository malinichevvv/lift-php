<?php

declare(strict_types=1);

namespace Lift\Filesystem;

/**
 * Local-disk filesystem adapter.
 *
 * All paths are resolved relative to the configured `$root` directory.
 * Absolute paths passed to any method are used as-is when they start with `/`.
 *
 * ```php
 * $fs = new LocalFilesystem(__DIR__ . '/storage');
 * $fs->put('uploads/avatar.png', file_get_contents($tmpFile));
 * $url = $fs->url('uploads/avatar.png'); // '/storage/uploads/avatar.png'
 * ```
 */
final class LocalFilesystem implements FilesystemInterface
{
    /** Resolved canonical root (via realpath). */
    private readonly string $realRoot;

    /**
     * @param string      $root      Absolute base path for all operations. Must exist.
     * @param string|null $publicUrl URL prefix returned by {@see url()} for publicly accessible files.
     * @throws \InvalidArgumentException When $root does not exist or cannot be resolved.
     */
    public function __construct(
        private readonly string  $root,
        private readonly ?string $publicUrl = null,
    ) {
        if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
            throw new \InvalidArgumentException("Storage root could not be created: [{$root}]");
        }
        $real = realpath($root);
        if ($real === false) {
            throw new \InvalidArgumentException("Storage root does not exist: [{$root}]");
        }
        $this->realRoot = $real;
    }

    // -----------------------------------------------------------------
    // Read / write
    // -----------------------------------------------------------------

    public function put(string $path, string $contents): void
    {
        $full = $this->fullPath($path);
        $this->ensureDirectory(dirname($full));
        if (file_put_contents($full, $contents, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write file [{$path}]");
        }
    }

    public function append(string $path, string $contents): void
    {
        $full = $this->fullPath($path);
        $this->ensureDirectory(dirname($full));
        if (file_put_contents($full, $contents, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("Failed to append to file [{$path}]");
        }
    }

    public function get(string $path): string
    {
        $full = $this->fullPath($path);
        if (!is_file($full)) {
            throw new \RuntimeException("File [{$path}] does not exist");
        }
        $contents = file_get_contents($full);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read file [{$path}]");
        }
        return $contents;
    }

    public function exists(string $path): bool
    {
        return is_file($this->fullPath($path));
    }

    // -----------------------------------------------------------------
    // File operations
    // -----------------------------------------------------------------

    public function delete(string $path): void
    {
        $full = $this->fullPath($path);
        if (is_file($full)) {
            unlink($full);
        }
    }

    public function copy(string $source, string $destination): void
    {
        $dst = $this->fullPath($destination);
        $this->ensureDirectory(dirname($dst));
        if (!copy($this->fullPath($source), $dst)) {
            throw new \RuntimeException("Failed to copy [{$source}] to [{$destination}]");
        }
    }

    public function move(string $source, string $destination): void
    {
        $dst = $this->fullPath($destination);
        $this->ensureDirectory(dirname($dst));
        if (!rename($this->fullPath($source), $dst)) {
            throw new \RuntimeException("Failed to move [{$source}] to [{$destination}]");
        }
    }

    public function size(string $path): int
    {
        $size = filesize($this->fullPath($path));
        if ($size === false) {
            throw new \RuntimeException("Failed to stat file [{$path}]");
        }
        return $size;
    }

    public function lastModified(string $path): int
    {
        $mtime = filemtime($this->fullPath($path));
        if ($mtime === false) {
            throw new \RuntimeException("Failed to stat file [{$path}]");
        }
        return $mtime;
    }

    // -----------------------------------------------------------------
    // Directory listing
    // -----------------------------------------------------------------

    public function files(string $directory = '', bool $recursive = false): array
    {
        $dir = $this->fullPath($directory);
        if (!is_dir($dir)) {
            return [];
        }

        if ($recursive) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );
        } else {
            $iterator = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        }

        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    public function directories(string $directory = ''): array
    {
        $dir = $this->fullPath($directory);
        if (!is_dir($dir)) {
            return [];
        }

        $dirs = [];
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item instanceof \SplFileInfo && $item->isDir()) {
                $dirs[] = $item->getPathname();
            }
        }
        sort($dirs);
        return $dirs;
    }

    public function makeDirectory(string $path, int $mode = 0755): void
    {
        $this->ensureDirectory($this->fullPath($path), $mode);
    }

    public function deleteDirectory(string $path): void
    {
        $full = $this->fullPath($path);
        if (!is_dir($full)) {
            return;
        }
        $this->removeRecursive($full);
    }

    // -----------------------------------------------------------------
    // URL
    // -----------------------------------------------------------------

    /**
     * Return a public URL for the path using the configured `$publicUrl` prefix.
     *
     * Returns `null` when no `$publicUrl` was set on construction.
     */
    public function url(string $path): ?string
    {
        if ($this->publicUrl === null) {
            return null;
        }
        return rtrim($this->publicUrl, '/') . '/' . ltrim($path, '/');
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    private function fullPath(string $path): string
    {
        if ($path === '') {
            return $this->realRoot;
        }

        // Reject null bytes and absolute paths — all paths must be relative to root
        if (str_contains($path, "\0")) {
            throw new \InvalidArgumentException("Path must not contain null bytes: [{$path}]");
        }
        if (str_starts_with($path, '/')) {
            throw new \InvalidArgumentException(
                "Absolute paths are not allowed; paths must be relative to the storage root: [{$path}]"
            );
        }

        $full = $this->realRoot . DIRECTORY_SEPARATOR . ltrim($path, '/\\');

        // For existing files/directories use realpath to get the canonical path
        $real = realpath($full);
        if ($real !== false) {
            $this->assertContained($real, $path);
            return $real;
        }

        // File doesn't exist yet (e.g. for writes): normalise without realpath
        // and verify the logical path doesn't escape the root
        $normalised = $this->normalisePath($full);
        $this->assertContained($normalised, $path);
        return $normalised;
    }

    /**
     * @throws \RuntimeException When $resolved escapes the storage root.
     */
    private function assertContained(string $resolved, string $original): void
    {
        // Ensure the resolved path starts with realRoot followed by a separator or is exactly realRoot
        $prefix = $this->realRoot . DIRECTORY_SEPARATOR;
        if ($resolved !== $this->realRoot && !str_starts_with($resolved, $prefix)) {
            throw new \RuntimeException(
                "Access denied: path escapes the storage root [{$original}]"
            );
        }
    }

    /**
     * Normalise a path by resolving `.` and `..` segments without touching the filesystem.
     */
    private function normalisePath(string $path): string
    {
        $parts  = [];
        $isAbs  = str_starts_with($path, DIRECTORY_SEPARATOR);

        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
            } else {
                $parts[] = $segment;
            }
        }

        return ($isAbs ? DIRECTORY_SEPARATOR : '') . implode(DIRECTORY_SEPARATOR, $parts);
    }

    private function ensureDirectory(string $path, int $mode = 0755): void
    {
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
            throw new \RuntimeException("Failed to create directory [{$path}]");
        }
    }

    private function removeRecursive(string $path): void
    {
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? $this->removeRecursive($item->getPathname()) : unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
