<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Log\Formatter\JsonFormatter;
use Lift\Log\Formatter\LineFormatter;
use Lift\Log\Handler\FileHandler;
use Lift\Log\Handler\NullHandler;
use Lift\Log\Handler\StdoutHandler;
use Lift\Log\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

class LogTest extends TestCase
{
    // -----------------------------------------------------------------
    // LineFormatter
    // -----------------------------------------------------------------

    public function testLineFormatterContainsLevelAndMessage(): void
    {
        $f    = new LineFormatter();
        $line = $f->format(LogLevel::INFO, 'Hello!', []);
        self::assertStringContainsString('INFO', $line);
        self::assertStringContainsString('Hello!', $line);
    }

    public function testLineFormatterIncludesContext(): void
    {
        $f    = new LineFormatter();
        $line = $f->format(LogLevel::DEBUG, 'msg', ['key' => 'val']);
        self::assertStringContainsString('key', $line);
        self::assertStringContainsString('val', $line);
    }

    // -----------------------------------------------------------------
    // JsonFormatter
    // -----------------------------------------------------------------

    public function testJsonFormatterProducesValidJson(): void
    {
        $f    = new JsonFormatter();
        $line = $f->format(LogLevel::ERROR, 'Boom', ['code' => 42]);
        $data = json_decode($line, true);
        self::assertIsArray($data);
        self::assertSame('error', $data['level']);
        self::assertSame('Boom',  $data['message']);
        self::assertSame(42,      $data['context']['code']);
    }

    public function testJsonFormatterSerializesException(): void
    {
        $f   = new JsonFormatter();
        $e   = new \RuntimeException('fail');
        $out = $f->format(LogLevel::ERROR, 'err', ['exception' => $e]);
        $d   = json_decode($out, true);
        self::assertIsArray($d['context']['exception']);
        self::assertSame('RuntimeException', $d['context']['exception']['class']);
    }

    // -----------------------------------------------------------------
    // NullHandler
    // -----------------------------------------------------------------

    public function testNullHandlerDiscardsEverything(): void
    {
        $h = new NullHandler();
        self::assertTrue($h->isHandling(LogLevel::DEBUG));
        // handle() should not throw
        $h->handle(LogLevel::DEBUG, 'msg', []);
        $this->addToAssertionCount(1);
    }

    // -----------------------------------------------------------------
    // FileHandler
    // -----------------------------------------------------------------

    public function testFileHandlerWritesToFile(): void
    {
        $path = sys_get_temp_dir() . '/lift_test_' . uniqid() . '.log';
        $h    = new FileHandler($path);
        $h->handle(LogLevel::INFO, 'File log test', ['x' => 1]);
        unset($h); // trigger destruct / close

        self::assertFileExists($path);
        $content = file_get_contents($path);
        self::assertStringContainsString('File log test', $content);
        unlink($path);
    }

    public function testFileHandlerCreatesDirectory(): void
    {
        $dir  = sys_get_temp_dir() . '/lift_nested_' . uniqid();
        $path = $dir . '/app.log';
        $h    = new FileHandler($path);
        $h->handle(LogLevel::INFO, 'nested dir test', []);
        unset($h);
        self::assertDirectoryExists($dir);
        unlink($path);
        rmdir($dir);
    }

    // -----------------------------------------------------------------
    // Logger
    // -----------------------------------------------------------------

    public function testLoggerCallsHandlers(): void
    {
        $path   = sys_get_temp_dir() . '/lift_logger_' . uniqid() . '.log';
        $logger = new Logger([new FileHandler($path)]);
        $logger->info('Hello');
        $logger->error('Uh oh', ['code' => 500]);
        unset($logger);

        $content = file_get_contents($path);
        self::assertStringContainsString('Hello', $content);
        self::assertStringContainsString('Uh oh',  $content);
        unlink($path);
    }

    public function testLoggerPsr3Interface(): void
    {
        $path   = sys_get_temp_dir() . '/lift_psr_' . uniqid() . '.log';
        $logger = new Logger([new FileHandler($path)]);

        $logger->debug('debug msg');
        $logger->notice('notice msg');
        $logger->warning('warning msg');
        $logger->critical('critical msg');
        $logger->alert('alert msg');
        $logger->emergency('emergency msg');

        unset($logger);
        $content = file_get_contents($path);
        self::assertStringContainsString('debug msg',     $content);
        self::assertStringContainsString('emergency msg', $content);
        unlink($path);
    }

    public function testLoggerPlaceholderInterpolation(): void
    {
        $path   = sys_get_temp_dir() . '/lift_interp_' . uniqid() . '.log';
        $logger = new Logger([new FileHandler($path)]);
        $logger->info('User {username} logged in', ['username' => 'alice']);
        unset($logger);

        $content = file_get_contents($path);
        self::assertStringContainsString('User alice logged in', $content);
        unlink($path);
    }

    public function testLoggerWithHandlerReturnsNewInstance(): void
    {
        $l1 = new Logger([]);
        $l2 = $l1->withHandler(new NullHandler());
        self::assertNotSame($l1, $l2);
    }

    public function testLoggerMinimumLevelFilter(): void
    {
        $path   = sys_get_temp_dir() . '/lift_level_' . uniqid() . '.log';
        $logger = new Logger([new FileHandler($path, LogLevel::WARNING)]);
        $logger->debug('ignored');
        $logger->info('also ignored');
        $logger->warning('logged');
        unset($logger);

        $content = file_get_contents($path);
        self::assertStringNotContainsString('ignored', $content);
        self::assertStringContainsString('logged', $content);
        unlink($path);
    }
}
