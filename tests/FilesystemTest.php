<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Filesystem\LocalFilesystem;
use Lift\Filesystem\Storage;
use PHPUnit\Framework\TestCase;

final class FilesystemTest extends TestCase
{
    private string $root;
    private LocalFilesystem $fs;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/lift_fs_test_' . uniqid();
        $this->fs   = new LocalFilesystem($this->root, '/files');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function testPutAndGet(): void
    {
        $this->fs->put('hello.txt', 'world');
        $this->assertSame('world', $this->fs->get('hello.txt'));
    }

    public function testPutCreatesIntermediateDirectories(): void
    {
        $this->fs->put('a/b/c/file.txt', 'deep');
        $this->assertSame('deep', $this->fs->get('a/b/c/file.txt'));
    }

    public function testExists(): void
    {
        $this->assertFalse($this->fs->exists('missing.txt'));
        $this->fs->put('present.txt', 'yes');
        $this->assertTrue($this->fs->exists('present.txt'));
    }

    public function testDelete(): void
    {
        $this->fs->put('del.txt', 'bye');
        $this->fs->delete('del.txt');
        $this->assertFalse($this->fs->exists('del.txt'));
    }

    public function testDeleteMissingFileDoesNotThrow(): void
    {
        $this->fs->delete('ghost.txt'); // must not throw
        $this->assertTrue(true);
    }

    public function testAppend(): void
    {
        $this->fs->put('log.txt', 'line1');
        $this->fs->append('log.txt', "\nline2");
        $this->assertSame("line1\nline2", $this->fs->get('log.txt'));
    }

    public function testCopy(): void
    {
        $this->fs->put('src.txt', 'original');
        $this->fs->copy('src.txt', 'dst.txt');
        $this->assertSame('original', $this->fs->get('dst.txt'));
        $this->assertTrue($this->fs->exists('src.txt')); // source still exists
    }

    public function testMove(): void
    {
        $this->fs->put('old.txt', 'data');
        $this->fs->move('old.txt', 'new.txt');
        $this->assertFalse($this->fs->exists('old.txt'));
        $this->assertSame('data', $this->fs->get('new.txt'));
    }

    public function testSize(): void
    {
        $this->fs->put('sized.txt', '12345');
        $this->assertSame(5, $this->fs->size('sized.txt'));
    }

    public function testLastModified(): void
    {
        $this->fs->put('ts.txt', 'x');
        $mtime = $this->fs->lastModified('ts.txt');
        $this->assertGreaterThan(0, $mtime);
        $this->assertLessThanOrEqual(time(), $mtime);
    }

    public function testFiles(): void
    {
        $this->fs->put('a.txt', '');
        $this->fs->put('b.txt', '');
        $this->fs->put('sub/c.txt', '');

        $files = $this->fs->files();
        $this->assertCount(2, $files); // a.txt + b.txt only (non-recursive)
    }

    public function testFilesRecursive(): void
    {
        $this->fs->put('a.txt', '');
        $this->fs->put('sub/b.txt', '');
        $this->fs->put('sub/deep/c.txt', '');

        $files = $this->fs->files('', recursive: true);
        $this->assertCount(3, $files);
    }

    public function testDirectories(): void
    {
        $this->fs->makeDirectory('dir1');
        $this->fs->makeDirectory('dir2');
        $this->fs->put('file.txt', '');

        $dirs = $this->fs->directories();
        $this->assertCount(2, $dirs);
    }

    public function testDeleteDirectory(): void
    {
        $this->fs->put('mydir/a.txt', '');
        $this->fs->put('mydir/b.txt', '');
        $this->fs->deleteDirectory('mydir');
        $this->assertSame([], $this->fs->files('mydir'));
    }

    public function testUrl(): void
    {
        $url = $this->fs->url('images/logo.png');
        $this->assertSame('/files/images/logo.png', $url);
    }

    public function testUrlNullWhenNoPublicUrl(): void
    {
        $fs = new LocalFilesystem($this->root);
        $this->assertNull($fs->url('any.txt'));
    }

    public function testGetThrowsOnMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->fs->get('nonexistent.txt');
    }

    // -----------------------------------------------------------------
    // Storage registry
    // -----------------------------------------------------------------

    public function testStorageDiskRegistry(): void
    {
        $storage = new Storage();
        $storage->addDisk('local', $this->fs);
        $storage->setDefault('local');

        $storage->put('test.txt', 'from storage');
        $this->assertSame('from storage', $storage->get('test.txt'));
        $this->assertTrue($storage->exists('test.txt'));
        $storage->delete('test.txt');
        $this->assertFalse($storage->exists('test.txt'));
    }

    public function testStorageHasDisk(): void
    {
        $storage = new Storage();
        $this->assertFalse($storage->hasDisk('local'));
        $storage->addDisk('local', $this->fs);
        $this->assertTrue($storage->hasDisk('local'));
    }

    public function testStorageGetDiskByName(): void
    {
        $storage = new Storage();
        $storage->addDisk('disk1', $this->fs);
        $this->assertSame($this->fs, $storage->getDisk('disk1'));
    }

    public function testStorageGetUnknownDiskThrows(): void
    {
        $storage = new Storage();
        $this->expectException(\InvalidArgumentException::class);
        $storage->getDisk('unknown');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? $this->removeDir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
