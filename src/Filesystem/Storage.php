<?php

declare(strict_types=1);

namespace Lift\Filesystem;

/**
 * Multi-disk filesystem registry.
 *
 * Holds named disk instances and exposes a static-style API via `disk()`.
 * The default disk is used when `disk()` is called without a name.
 *
 * ```php
 * // Bootstrap
 * $storage = new Storage();
 * $storage->addDisk('local',  new LocalFilesystem(__DIR__ . '/storage/app', '/files'));
 * $storage->addDisk('public', new LocalFilesystem(__DIR__ . '/public/uploads', '/uploads'));
 * $storage->setDefault('local');
 *
 * // Or use the static singleton pattern after boot
 * Storage::setInstance($storage);
 * Storage::disk()->put('reports/2026.pdf', $pdf);
 * Storage::disk('public')->put('avatars/1.jpg', $jpg);
 * ```
 */
final class Storage
{
    /** @var array<string, FilesystemInterface> */
    private array $disks = [];
    private string $default = 'local';

    /** Singleton instance for static access. */
    private static ?self $instance = null;

    // -----------------------------------------------------------------
    // Static singleton
    // -----------------------------------------------------------------

    /**
     * Set the global Storage instance used by static `disk()` calls.
     */
    public static function setInstance(self $storage): void
    {
        self::$instance = $storage;
    }

    /**
     * Return the named disk (or the default disk when `$name` is `null`) via
     * the global singleton.
     *
     * @throws \RuntimeException When no instance has been set.
     */
    public static function disk(?string $name = null): FilesystemInterface
    {
        if (self::$instance === null) {
            throw new \RuntimeException(
                'Storage has not been initialised. Call Storage::setInstance() during bootstrap.'
            );
        }
        return self::$instance->getDisk($name);
    }

    // -----------------------------------------------------------------
    // Instance API
    // -----------------------------------------------------------------

    /**
     * Register a filesystem adapter under a named disk.
     */
    public function addDisk(string $name, FilesystemInterface $filesystem): self
    {
        $this->disks[$name] = $filesystem;
        return $this;
    }

    /**
     * Set the name of the default disk returned when `getDisk()` is called
     * without a name.
     */
    public function setDefault(string $name): self
    {
        $this->default = $name;
        return $this;
    }

    /**
     * Retrieve a disk by name, or the default disk when `$name` is `null`.
     *
     * @throws \InvalidArgumentException When the named disk is not registered.
     */
    public function getDisk(?string $name = null): FilesystemInterface
    {
        $key = $name ?? $this->default;
        if (!isset($this->disks[$key])) {
            throw new \InvalidArgumentException("Storage disk [{$key}] is not registered.");
        }
        return $this->disks[$key];
    }

    /**
     * Return `true` when a disk with the given name has been registered.
     */
    public function hasDisk(string $name): bool
    {
        return isset($this->disks[$name]);
    }

    /**
     * Delegate `put()` to the default disk.
     *
     * @see FilesystemInterface::put()
     */
    public function put(string $path, string $contents): void
    {
        $this->getDisk()->put($path, $contents);
    }

    /**
     * Delegate `get()` to the default disk.
     *
     * @see FilesystemInterface::get()
     */
    public function get(string $path): string
    {
        return $this->getDisk()->get($path);
    }

    /**
     * Delegate `delete()` to the default disk.
     *
     * @see FilesystemInterface::delete()
     */
    public function delete(string $path): void
    {
        $this->getDisk()->delete($path);
    }

    /**
     * Delegate `exists()` to the default disk.
     *
     * @see FilesystemInterface::exists()
     */
    public function exists(string $path): bool
    {
        return $this->getDisk()->exists($path);
    }
}
