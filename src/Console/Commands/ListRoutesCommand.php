<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\App;
use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;
use Lift\Routing\Router;

/**
 * Prints all registered routes in a table.
 *
 * When registered without a Router (the default for the bundled `lift` CLI),
 * the command boots the project's app from a bootstrap file — `bootstrap/app.php`,
 * `app/bootstrap.php` or `app.php` — and reads the routes from it. A Router can
 * still be supplied explicitly when wiring the command from application code.
 *
 * Usage:
 *   lift routes:list
 *   lift routes:list --bootstrap=path/to/app.php
 */
final class ListRoutesCommand extends Command
{
    public function __construct(private readonly ?Router $router = null) {}

    public function getName(): string        { return 'routes:list'; }
    public function getDescription(): string { return 'List all registered routes'; }

    public function getHelp(): string
    {
        return 'Usage: lift routes:list [--bootstrap=path/to/app.php]' . PHP_EOL
            . '  Boots the project app (bootstrap/app.php, app/bootstrap.php or app.php)'
            . ' and prints every registered route.';
    }

    public function execute(Input $input, Output $output): int
    {
        $router = $this->router ?? $this->resolveRouter($input, $output);

        if ($router === null) {
            return 1;
        }

        $routes = $router->getRoutes();

        if (empty($routes)) {
            $output->warn('No routes registered.');
            return 0;
        }

        $rows = [];
        foreach ($routes as $route) {
            $rows[] = [
                'Methods'    => implode('|', $route->getMethods()),
                'Path'       => $route->getPath(),
                'Name'       => $route->getName() ?? '',
                'Middleware' => implode(', ', array_map(
                    static fn($m) => basename(str_replace('\\', '/', is_string($m) ? $m : get_class($m))),
                    $route->getMiddleware(),
                )),
            ];
        }

        $output->table(['Methods', 'Path', 'Name', 'Middleware'], $rows);
        return 0;
    }

    /**
     * Boot the project app from a bootstrap file and extract its Router.
     */
    private function resolveRouter(Input $input, Output $output): ?Router
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
            $output->error('No bootstrap file found. Tried: bootstrap/app.php, app/bootstrap.php, app.php');
            $output->writeln('Use --bootstrap=path/to/app.php to specify one manually.');
            return null;
        }

        try {
            $app = require $bootstrap;
        } catch (\Throwable $e) {
            $output->error('Could not load bootstrap: ' . $e->getMessage());
            return null;
        }

        if ($app instanceof Router) {
            return $app;
        }

        if ($app instanceof App) {
            return $app->router();
        }

        $output->error('Bootstrap file must return a Lift\\App (or Lift\\Routing\\Router) instance.');
        return null;
    }
}
