<?php

declare(strict_types=1);

namespace Crab\Runs;

use Crab\Engine\Async\Cancellation;
use Crab\Queue\Retry;

/**
 * The job that carries a run forward: pushed when it starts, when a try
 * fails, when a sleep ends and when a signal comes. A run counts and
 * spaces its own tries; this job's own tries are for its worker dying or
 * being stopped, after which the queue hands it to another.
 */
#[Retry(tries: 5, backoff: [5, 30])]
final readonly class ResumeRun
{
    public function __construct(
        private Runs $runs,
    ) {}

    public function handle(int|string $run, Cancellation $cancellation): void
    {
        $this->runs->resume($run, $cancellation);
    }
}
