<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Routing\Router;

final readonly class Routes implements Command
{
    public function __construct(
        private Router $router,
    ) {}

    public function description(): string
    {
        return 'List the registered routes, each with its name, its action and the middleware around it';
    }

    public function handle(Input $input, Output $output): int
    {
        $routes = $this->router->routes();
        if ($routes === []) {
            $output->line('No routes are registered.');
            return 0;
        }
        // A name column only when some route has a name.
        $named = array_filter($routes, static fn($route): bool => $route->name !== null) !== [];
        $rows = [];
        foreach ($routes as $route) {
            $name = $named ? [$route->name ?? ''] : [];
            $rows[] = [$route->method, $route->pattern, ...$name, $route->action, implode(', ', $route->middleware)];
        }
        $output->table($rows);
        return 0;
    }
}
