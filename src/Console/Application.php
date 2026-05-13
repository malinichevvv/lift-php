<?php

declare(strict_types=1);

namespace Lift\Console;

/**
 * CLI application — registers commands and dispatches argv to them.
 *
 * ```php
 * $cli = new Application('lift', '1.0.0');
 * $cli->register(new MyCommand());
 * exit($cli->run());
 * ```
 */
final class Application
{
    /** @var array<string, Command> Keyed by command name. */
    private array $commands = [];

    public function __construct(
        private readonly string $name,
        private readonly string $version,
    ) {}

    public function register(Command $command): self
    {
        $this->commands[$command->getName()] = $command;
        return $this;
    }

    /**
     * Run the application.
     *
     * @param  string[]|null $argv Raw argv. Defaults to `$_SERVER['argv']` without the script name.
     * @return int Exit code.
     */
    public function run(?array $argv = null): int
    {
        if ($argv === null) {
            $argv = array_slice($_SERVER['argv'] ?? [], 1);
        }

        $input  = new Input($argv);
        $output = new Output();
        $cmd    = $input->getCommand();

        if ($cmd === '' || $cmd === 'list') {
            $this->printList($output);
            return 0;
        }

        if ($cmd === 'version' || $input->hasOption('version') || $input->hasOption('V')) {
            $output->writeln("{$this->name} <cyan>{$this->version}</cyan>");
            return 0;
        }

        if ($cmd === 'help') {
            $target = $input->getArgument(0);
            if ($target !== '' && isset($this->commands[$target])) {
                $this->printHelp($this->commands[$target], $output);
            } else {
                $this->printList($output);
            }
            return 0;
        }

        if (!isset($this->commands[$cmd])) {
            $output->error("Command '{$cmd}' not found. Run 'lift list' to see available commands.");
            $this->suggest($cmd, $output);
            return 1;
        }

        try {
            return $this->commands[$cmd]->execute($input, $output);
        } catch (\Throwable $e) {
            $output->error($e->getMessage());
            return 1;
        }
    }

    private function printList(Output $output): void
    {
        $output->writeln("<bold>{$this->name}</bold> <cyan>{$this->version}</cyan>");
        $output->writeln('');
        $output->writeln('<bold>Available commands:</bold>');

        $groups = [];
        foreach ($this->commands as $name => $command) {
            $group = str_contains($name, ':') ? explode(':', $name, 2)[0] : '';
            $groups[$group][$name] = $command->getDescription();
        }
        ksort($groups);

        foreach ($groups as $group => $items) {
            if ($group !== '') {
                $output->writeln(" <yellow>{$group}</yellow>");
            }
            foreach ($items as $name => $desc) {
                $output->writeln(sprintf('  <green>%-30s</green> %s', $name, $desc));
            }
        }
    }

    private function printHelp(Command $command, Output $output): void
    {
        $output->writeln('<bold>Description:</bold>');
        $output->writeln('  ' . $command->getDescription());
        $output->writeln('');
        $output->writeln('<bold>Usage:</bold>');
        $output->writeln('  ' . $command->getName());
        $help = $command->getHelp();
        if ($help !== $command->getDescription()) {
            $output->writeln('');
            $output->writeln('<bold>Help:</bold>');
            $output->writeln('  ' . $help);
        }
    }

    private function suggest(string $cmd, Output $output): void
    {
        $best = null;
        $min  = PHP_INT_MAX;
        foreach (array_keys($this->commands) as $name) {
            $d = levenshtein($cmd, $name);
            if ($d < $min) {
                $min  = $d;
                $best = $name;
            }
        }
        if ($best !== null && $min <= 3) {
            $output->writeln('');
            $output->writeln("Did you mean: <cyan>{$best}</cyan>?");
        }
    }
}
