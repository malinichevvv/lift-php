<?php

declare(strict_types=1);

namespace Lift\Console\Commands;

use Lift\Console\Command;
use Lift\Console\Input;
use Lift\Console\Output;
use Lift\Routing\Router;

/**
 * Prints all registered routes in a table.
 *
 * Usage: lift routes:list
 */
final class ListRoutesCommand extends Command
{
    public function __construct(private readonly Router $router) {}

    public function getName(): string        { return 'routes:list'; }
    public function getDescription(): string { return 'List all registered routes'; }

    public function execute(Input $input, Output $output): int
    {
        $routes = $this->router->getRoutes();

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
}
