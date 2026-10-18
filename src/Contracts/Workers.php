<?php

declare(strict_types=1);

namespace Crab\Contracts;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Crab;
use Crab\Engine\Process\Worker;

/**
 * One group of worker processes that serve no HTTP requests, for
 * Runner::runAll() or a service of `serve`: its name, how many there
 * are, and what each does. That is work, a loop that runs until it is
 * told to stop, such as taking jobs from a queue; or a setup, which has
 * the group listen on an address of its own, as a WebSocket server
 * does; or both.
 */
final readonly class Workers
{
    /**
     * @param string $group A name for these workers, such as "queue:mail"; no two groups run together share one.
     * @param (\Closure(Worker, Cancellation): void)|null $work What each worker does until its cancellation fires. A worker whose work returns is started again.
     * @param int $workers How many processes run it.
     * @param (\Closure(Crab): void)|null $setup Called once before the workers start, with the engine's group, to have it listen: `$group->listen('tcp://0.0.0.0:8081', $server)`.
     */
    public function __construct(
        public string $group,
        public ?\Closure $work = null,
        public int $workers = 1,
        public ?\Closure $setup = null,
    ) {
        if ($group === '' || $workers < 1) {
            throw new \InvalidArgumentException('A group of workers needs a name and at least one worker.');
        }
        if ($work === null && $setup === null) {
            throw new \InvalidArgumentException(
                "The group of workers \"{$group}\" has nothing to do: give it work, or a setup that has it listen.",
            );
        }
    }
}
