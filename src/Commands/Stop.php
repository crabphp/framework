<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\App;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Server;

final readonly class Stop implements Command
{
    public function __construct(private App $app)
    {
    }

    public function description(): string
    {
        return <<<'TEXT'
            Stop the server running in the background

            Options:
              --timeout=15   Seconds to wait for it to finish its requests
              --force        End it outright when it has not stopped in that time
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $server = new Server($this->app);
        try {
            $pid = $server->stop((float) $input->option('timeout', '15'), $input->flag('force'));
        } catch (\RuntimeException $error) {
            $output->error($error->getMessage());
            return 1;
        }
        $output->line($pid === null ? 'No server is running in the background.' : "Stopped the server, pid {$pid}.");
        return 0;
    }
}
