<?php

declare(strict_types=1);

namespace Crab\Contracts;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Worker;

/**
 * One group of worker processes for Runner::runAll(): its name, the work
 * each of them does, and how many there are.
 */
final readonly class Workers
{
    /**
     * @param string $group A name for these workers, such as "queue:mail"; no two groups run together share one.
     * @param \Closure(Worker, Cancellation): void $work What each worker does until its cancellation fires.
     * @param int $workers How many processes run it.
     */
    public function __construct(
        public string $group,
        public \Closure $work,
        public int $workers = 1,
    ) {
        if ($group === '' || $workers < 1) {
            throw new \InvalidArgumentException('A group of workers needs a name and at least one worker.');
        }
    }
}
