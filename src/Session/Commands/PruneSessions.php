<?php

declare(strict_types=1);

namespace Crab\Session\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Session\Driver;
use Psr\Clock\ClockInterface;

/**
 * Removes expired sessions, for an application that sweeps them on a
 * schedule rather than by the lottery.
 */
final readonly class PruneSessions implements Command
{
    public function __construct(
        private Driver $driver,
        private ClockInterface $clock,
    ) {}

    public function description(): string
    {
        return (
            "Remove expired sessions\n\nThe file and memory drivers keep expired sessions until they are swept, which"
            . ' session.lottery does now and then as sessions are written. Set it to [0, 1] and schedule this command'
            . ' instead to sweep at a time of your choosing. Cookie and Redis sessions expire by themselves.'
        );
    }

    public function handle(Input $input, Output $output): int
    {
        $removed = $this->driver->sweep($this->clock->now()->getTimestamp());
        $output->line("Removed {$removed} expired sessions.");
        return 0;
    }
}
