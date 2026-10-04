<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Routing\Router;

final readonly class Routes implements Command
{
    public function __construct(private Router $router)
    {
    }

    public function description(): string
    {
        return 'List the registered routes';
    }

    public function handle(Input $input, Output $output): int
    {
        $routes = $this->router->routes();
        if ($routes === []) {
            $output->line('No routes are registered.');
            return 0;
        }
        $output->table($routes);
        return 0;
    }
}
