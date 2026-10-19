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
    public function __construct(
        private App $app,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Stop the server running in the background

            Options:
              --timeout=15   Graceful wait in seconds; if omitted, read from the running server
              --force        Cut requests and end every server process now
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $server = new Server($this->app);
        $timeout = $input->option('timeout');
        if ($timeout === true) {
            throw new \InvalidArgumentException('--timeout needs a value; use --timeout=value.');
        }
        $seconds = is_numeric($timeout) ? (float) $timeout : null;
        if ($timeout !== null && ($seconds === null || !is_finite($seconds) || $seconds < 0)) {
            throw new \InvalidArgumentException(
                '--timeout must be a finite number of seconds at least zero; use --timeout=15.',
            );
        }
        try {
            $pid = $server->stop($seconds, $input->flag('force'));
        } catch (\RuntimeException $error) {
            $output->error($error->getMessage());
            return 1;
        }
        $output->line($pid === null ? 'No server is running in the background.' : "Stopped the server, pid {$pid}.");
        return 0;
    }
}
