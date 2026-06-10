<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\App;
use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;
use Lift\Routing\Router;

/** Build a PHP route cache file for production deployments. */
final class RouteCacheCommand extends Command
{
    public function getName(): string { return 'route:cache'; }
    public function getDescription(): string { return 'Compile routes into a PHP cache file'; }

    public function getHelp(): string
    {
        return 'Usage: lift route:cache [--bootstrap=path/to/app.php] [--path=storage/framework/routes.cache.php]';
    }

    public function execute(Input $input, Output $output): int
    {
        $router = $this->resolveRouter($input, $output);
        if ($router === null) {
            return 1;
        }

        $path = (string) $input->getOption('path', getcwd() . '/storage/framework/routes.cache.php');
        $count = $router->writeCache($path);
        $output->success("Cached {$count} route entries to {$path}");
        return 0;
    }

    private function resolveRouter(Input $input, Output $output): ?Router
    {
        $bootstrap = self::bootstrapPath($input);
        if ($bootstrap === null) {
            $output->error('No bootstrap file found. Tried: bootstrap/app.php, app/bootstrap.php, app.php');
            return null;
        }

        try {
            $app = require $bootstrap;
        } catch (\Throwable $e) {
            $output->error('Could not load bootstrap: ' . $e->getMessage());
            return null;
        }

        return $app instanceof App ? $app->router() : ($app instanceof Router ? $app : null);
    }

    public static function bootstrapPath(Input $input): ?string
    {
        $bootstrap = (string) $input->getOption('bootstrap', '');
        if ($bootstrap !== '') {
            return file_exists($bootstrap) ? $bootstrap : null;
        }
        foreach ([getcwd() . '/bootstrap/app.php', getcwd() . '/app/bootstrap.php', getcwd() . '/app.php'] as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }
}
