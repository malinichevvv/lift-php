<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Console\Application;
use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;
use PHPUnit\Framework\TestCase;

// --- Stub command for testing ---

final class HelloCommand extends Command
{
    public string $lastArg = '';

    public function getName(): string        { return 'hello'; }
    public function getDescription(): string { return 'Say hello'; }

    public function execute(Input $input, Output $output): int
    {
        $this->lastArg = $input->getArgument(0, 'World');
        $output->writeln("Hello, {$this->lastArg}!");
        return 0;
    }
}

final class FailingCommand extends Command
{
    public function getName(): string        { return 'fail'; }
    public function getDescription(): string { return 'Always fails'; }

    public function execute(Input $input, Output $output): int
    {
        return 1;
    }
}

class ConsoleTest extends TestCase
{
    // -----------------------------------------------------------------
    // Input parsing
    // -----------------------------------------------------------------

    public function testInputParsesCommand(): void
    {
        $input = new Input(['hello', 'Alice']);
        self::assertSame('hello', $input->getCommand());
        self::assertSame('Alice', $input->getArgument(0));
    }

    public function testInputParsesLongOption(): void
    {
        $input = new Input(['cmd', '--env=production']);
        self::assertSame('production', $input->getOption('env'));
    }

    public function testInputParsesLongFlag(): void
    {
        $input = new Input(['cmd', '--verbose']);
        self::assertTrue($input->getOption('verbose'));
    }

    public function testInputParsesShortFlag(): void
    {
        $input = new Input(['cmd', '-v']);
        self::assertTrue($input->getOption('v'));
    }

    public function testInputHasOption(): void
    {
        $input = new Input(['cmd', '--dry-run']);
        self::assertTrue($input->hasOption('dry-run'));
        self::assertFalse($input->hasOption('verbose'));
    }

    public function testInputDefaultOption(): void
    {
        $input = new Input(['cmd']);
        self::assertSame('3', $input->getOption('sleep', '3'));
        self::assertFalse($input->getOption('nonexistent'));
    }

    public function testInputMultipleArguments(): void
    {
        $input = new Input(['deploy', 'prod', 'v2']);
        self::assertSame('deploy', $input->getCommand());
        self::assertSame('prod',   $input->getArgument(0));
        self::assertSame('v2',     $input->getArgument(1));
        self::assertSame(['prod', 'v2'], $input->getArguments());
    }

    public function testEmptyInput(): void
    {
        $input = new Input([]);
        self::assertSame('', $input->getCommand());
        self::assertSame([], $input->getArguments());
    }

    // -----------------------------------------------------------------
    // Application dispatch
    // -----------------------------------------------------------------

    public function testApplicationRunsCommand(): void
    {
        $cmd = new HelloCommand();
        $cli = new Application('test', '1.0');
        $cli->register($cmd);

        $code = $cli->run(['hello', 'Alice']);
        self::assertSame(0, $code);
        self::assertSame('Alice', $cmd->lastArg);
    }

    public function testApplicationReturnsExitCode(): void
    {
        $cli = new Application('test', '1.0');
        $cli->register(new FailingCommand());

        self::assertSame(1, $cli->run(['fail']));
    }

    public function testApplicationUnknownCommandReturns1(): void
    {
        $cli  = new Application('test', '1.0');
        $code = $cli->run(['nonexistent']);
        self::assertSame(1, $code);
    }

    public function testApplicationListReturns0(): void
    {
        $cli = new Application('test', '1.0');
        $cli->register(new HelloCommand());
        self::assertSame(0, $cli->run(['list']));
    }

    public function testApplicationVersionReturns0(): void
    {
        $cli  = new Application('myapp', '2.5.0');
        $code = $cli->run(['version']);
        self::assertSame(0, $code);
    }

    public function testApplicationHelpReturns0(): void
    {
        $cli = new Application('test', '1.0');
        $cli->register(new HelloCommand());
        self::assertSame(0, $cli->run(['help', 'hello']));
    }

    public function testApplicationHelpUnknownCommandListsAll(): void
    {
        $cli  = new Application('test', '1.0');
        $code = $cli->run(['help']);
        self::assertSame(0, $code);
    }

    // -----------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------

    private function captureOutput(callable $fn): string
    {
        $stream = fopen('php://memory', 'r+');
        $fn(new Output($stream));
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        return (string) $content;
    }

    public function testOutputWriteln(): void
    {
        $out = $this->captureOutput(fn(Output $o) => $o->writeln('test line'));
        self::assertStringContainsString('test line', $out);
    }

    public function testOutputTable(): void
    {
        $out = $this->captureOutput(function (Output $o) {
            $o->table(['Name', 'Age'], [
                ['Name' => 'Alice', 'Age' => '30'],
                ['Name' => 'Bob',   'Age' => '25'],
            ]);
        });
        self::assertStringContainsString('Alice', $out);
        self::assertStringContainsString('Bob',   $out);
        self::assertStringContainsString('Name',  $out);
    }

    public function testOutputColourTagsStripped(): void
    {
        $out = $this->captureOutput(fn(Output $o) => $o->success('ok'));
        // When not a tty (injected stream), colour tags must be stripped
        self::assertStringNotContainsString('<green>', $out);
        self::assertStringContainsString('ok', $out);
    }
}
