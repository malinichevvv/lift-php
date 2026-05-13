<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;

/**
 * Starts the PHP built-in development server.
 *
 * Usage:
 *   lift serve [--host=127.0.0.1] [--port=8000] [--root=public]
 */
final class ServeCommand extends Command
{
    public function getName(): string        { return 'serve'; }
    public function getDescription(): string { return 'Start the built-in PHP development server'; }

    public function execute(Input $input, Output $output): int
    {
        $host = (string) $input->getOption('host', '127.0.0.1');
        $port = (string) $input->getOption('port', '8000');
        $root = (string) $input->getOption('root', 'public');

        if (!is_dir($root)) {
            $output->error("Document root '{$root}' does not exist.");
            return 1;
        }

        $addr = "{$host}:{$port}";
        $output->info("Lift development server started: <bold>http://{$addr}</bold>");
        $output->writeln("<grey>Document root: {$root}</grey>");
        $output->writeln('<grey>Press Ctrl+C to stop.</grey>');

        passthru(sprintf(
            '%s -S %s -t %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($addr),
            escapeshellarg($root),
        ), $code);

        return $code ?? 0;
    }
}
