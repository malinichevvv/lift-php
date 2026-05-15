<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;

/**
 * Interactive PHP REPL with Lift app context.
 *
 * Boots the app from `bootstrap/app.php` (if present) and exposes `$app`.
 * Variables persist across REPL iterations in the normal PHP way.
 * History is written to ~/.lift_repl_history.
 *
 * Usage:
 *   lift repl
 *   lift repl --bootstrap=path/to/app.php
 */
final class ReplCommand extends Command
{
    public function getName(): string
    {
        return 'repl';
    }

    public function getDescription(): string
    {
        return 'Start an interactive PHP REPL with Lift app context';
    }

    public function getHelp(): string
    {
        return 'Usage: lift repl [--bootstrap=path/to/bootstrap.php]' . PHP_EOL
            . '  Type PHP expressions or statements. Multi-line: end with \\ to continue.' . PHP_EOL
            . '  Type "exit" or press Ctrl+D to quit.';
    }

    public function execute(Input $input, Output $output): int
    {
        if (!function_exists('readline')) {
            $output->error('The readline extension is required. Install php-readline or use php --with-readline.');
            return 1;
        }

        $app = $this->bootApp($input, $output);

        $output->writeln('<bold>Lift REPL</bold> — type PHP and press Enter. Type <cyan>exit</cyan> or Ctrl+D to quit.');
        if ($app !== null) {
            $output->writeln('<cyan>$app</cyan> is available.');
        }
        $output->writeln('');

        $historyFile = $this->homeDir() . '/.lift_repl_history';
        readline_read_history($historyFile);

        $buffer = '';

        while (true) {
            $prompt = $buffer === '' ? '>>> ' : '... ';
            $line   = readline($prompt);

            if ($line === false) {
                break;
            }

            // Continuation: line ends with backslash
            if (str_ends_with(rtrim($line), '\\')) {
                $buffer .= substr(rtrim($line), 0, -1) . ' ';
                continue;
            }

            $code   = $buffer . $line;
            $buffer = '';

            if (trim($code) === '') {
                continue;
            }

            if (in_array(trim($code), ['exit', 'quit'], true)) {
                break;
            }

            readline_add_history($code);
            readline_write_history($historyFile);

            $this->evaluateCode($code, $app, $output);
        }

        $output->writeln('');
        $output->writeln('Bye!');
        return 0;
    }

    // phpcs:ignore
    private function evaluateCode(string $code, mixed $app, Output $output): void
    {
        $trimmed = rtrim(trim($code), ';');

        ob_start();
        try {
            // Attempt as expression — wrapping in return captures the result value.
            $__result = eval("return ({$trimmed});"); // phpcs:ignore
            $printed  = ob_get_clean();

            if ($printed !== '') {
                echo $printed;
            }

            if ($__result !== null) {
                echo self::export($__result) . PHP_EOL;
            }
        } catch (\ParseError) {
            ob_end_clean();
            // Fall back to statement mode (assignments, loops, etc.)
            ob_start();
            try {
                eval($trimmed . ';'); // phpcs:ignore
                $printed = ob_get_clean();
                if ($printed !== '') {
                    echo $printed;
                }
            } catch (\Throwable $e) {
                ob_end_clean();
                $output->error($e::class . ': ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            ob_end_clean();
            $output->error($e::class . ': ' . $e->getMessage());
        }
    }

    private function bootApp(Input $input, Output $output): mixed
    {
        $bootstrap = (string) $input->getOption('bootstrap', '');

        if ($bootstrap === '') {
            $candidates = [
                getcwd() . '/bootstrap/app.php',
                getcwd() . '/app/bootstrap.php',
                getcwd() . '/app.php',
            ];
            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    $bootstrap = $candidate;
                    break;
                }
            }
        }

        if ($bootstrap === '' || !file_exists($bootstrap)) {
            $output->warn('No bootstrap file found. Tried: bootstrap/app.php, app/bootstrap.php, app.php');
            $output->writeln('Use --bootstrap=path/to/bootstrap.php to specify manually.');
            return null;
        }

        try {
            return require $bootstrap;
        } catch (\Throwable $e) {
            $output->error('Could not load bootstrap: ' . $e->getMessage());
            return null;
        }
    }

    private function homeDir(): string
    {
        return (string) (getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir());
    }

    private static function export(mixed $value): string
    {
        if (is_string($value)) {
            return '"' . addcslashes($value, '"\\') . '"';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_null($value)) {
            return 'null';
        }
        if (is_object($value)) {
            return get_class($value) . ' ' . json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return var_export($value, true);
    }
}
