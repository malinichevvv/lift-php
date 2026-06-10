<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;

/** Remove the generated route cache file. */
final class RouteClearCommand extends Command
{
    public function getName(): string { return 'route:clear'; }
    public function getDescription(): string { return 'Remove the route cache file'; }

    public function getHelp(): string
    {
        return 'Usage: lift route:clear [--path=storage/framework/routes.cache.php]';
    }

    public function execute(Input $input, Output $output): int
    {
        $path = (string) $input->getOption('path', getcwd() . '/storage/framework/routes.cache.php');
        if (!is_file($path)) {
            $output->warn('Route cache file does not exist: ' . $path);
            return 0;
        }
        if (!unlink($path)) {
            $output->error('Could not delete route cache file: ' . $path);
            return 1;
        }
        $output->success('Deleted route cache file: ' . $path);
        return 0;
    }
}
