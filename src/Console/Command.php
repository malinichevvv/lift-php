<?php

declare(strict_types=1);

namespace Lift\Console;

/**
 * Base class for all console commands.
 *
 * ```php
 * final class MyCommand extends Command
 * {
 *     public function getName(): string    { return 'my:command'; }
 *     public function getDescription(): string { return 'Does something'; }
 *
 *     public function execute(Input $input, Output $output): int
 *     {
 *         $output->success('Done!');
 *         return 0;
 *     }
 * }
 * ```
 */
abstract class Command
{
    abstract public function getName(): string;
    abstract public function getDescription(): string;

    /**
     * Run the command.
     *
     * @return int Exit code (0 = success, non-zero = failure).
     */
    abstract public function execute(Input $input, Output $output): int;

    /** Optional longer help text shown by `lift help <command>`. */
    public function getHelp(): string
    {
        return $this->getDescription();
    }
}
