<?php

declare(strict_types=1);

namespace Lift\Console;

/** Parses argv into command name, arguments, and options. */
final class Input
{
    private string $command = '';
    /** @var string[] */
    private array $arguments = [];
    /** @var array<string, string|bool> */
    private array $options = [];

    /**
     * @param string[] $argv Raw argv array (without the script name).
     */
    public function __construct(array $argv = [])
    {
        $this->parse($argv);
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getArgument(int $index, string $default = ''): string
    {
        return $this->arguments[$index] ?? $default;
    }

    /** @return string[] */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getOption(string $name, string|bool $default = false): string|bool
    {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool
    {
        return isset($this->options[$name]);
    }

    private function parse(array $argv): void
    {
        if (empty($argv)) {
            return;
        }

        // First non-option token is the command
        $args = [];
        foreach ($argv as $token) {
            if (str_starts_with($token, '--')) {
                $token = ltrim($token, '-');
                if (str_contains($token, '=')) {
                    [$key, $val] = explode('=', $token, 2);
                    $this->options[$key] = $val;
                } else {
                    $this->options[$token] = true;
                }
            } elseif (str_starts_with($token, '-') && strlen($token) === 2) {
                $this->options[ltrim($token, '-')] = true;
            } else {
                $args[] = $token;
            }
        }

        if (!empty($args)) {
            $this->command   = array_shift($args);
            $this->arguments = $args;
        }
    }
}
