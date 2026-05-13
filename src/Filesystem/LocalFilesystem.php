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
    /**
     * @param string      $root      Absolute base path for all operations.
     * @param string|null $publicUrl URL prefix returned by {@see url()} for publicly accessible files.
     */
    public function __construct(
        private readonly string  $root,
        private readonly ?string $publicUrl = null,
    ) {}

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
            return $this->root;
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return rtrim($this->root, '/') . '/' . ltrim($path, '/');
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
