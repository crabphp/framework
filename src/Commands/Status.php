<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\App;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Server;

final readonly class Status implements Command
{
    public function __construct(private App $app)
    {
    }

    public function description(): string
    {
        return "Say whether the server is running in the background\n\nThe exit status is 0 when it is and 1 when it is not.";
    }

    public function handle(Input $input, Output $output): int
    {
        $server = new Server($this->app);
        $pid = $server->running();
        if ($pid === null) {
            $output->line('No server is running in the background.');
            return 1;
        }
        $output->line("The server is running in the background, pid {$pid}.");
        $output->line("Its output goes to {$server->logFile()}.");
        return 0;
    }
}
