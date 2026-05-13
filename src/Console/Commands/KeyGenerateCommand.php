<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;

/**
 * Generates a cryptographically-random APP_KEY and writes it to the .env file.
 *
 * If `APP_KEY` already exists in the file it is replaced in-place; otherwise
 * it is appended. Pass `$envFile` to the constructor to override the default
 * path (useful in tests or Docker entrypoints).
 */
final class KeyGenerateCommand extends Command
{
    /** @param string|null $envFile Absolute path to the .env file. Defaults to `<cwd>/.env`. */
    public function __construct(private readonly ?string $envFile = null) {}

    public function getName(): string        { return 'key:generate'; }
    public function getDescription(): string { return 'Generate a new application key and write it to .env'; }

    public function execute(Input $input, Output $output): int
    {
        $key     = 'base64:' . base64_encode(random_bytes(32));
        $envFile = $this->envFile ?? getcwd() . '/.env';

        if (!is_file($envFile)) {
            if (file_put_contents($envFile, "APP_KEY={$key}\n") === false) {
                $output->error("Could not write to {$envFile}");
                return 1;
            }
            $output->success("Application key set: {$key}");
            return 0;
        }

        $contents = file_get_contents($envFile);
        if ($contents === false) {
            $output->error("Could not read {$envFile}");
            return 1;
        }

        if (str_contains($contents, 'APP_KEY=')) {
            $contents = (string) preg_replace('/^APP_KEY=.*/m', "APP_KEY={$key}", $contents);
        } else {
            $contents .= "\nAPP_KEY={$key}\n";
        }

        if (file_put_contents($envFile, $contents) === false) {
            $output->error("Could not write to {$envFile}");
            return 1;
        }

        $output->success("Application key set: {$key}");
        return 0;
    }
}
