<?php

declare(strict_types=1);

namespace Crab\Contracts;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Worker;

/**
 * Runs work in worker processes of the application's own, apart from the
 * ones that serve HTTP: a queue's consumers, the scheduler. Each worker
 * starts as an HTTP worker does, with every WorkerProvider started and
 * stopped around it and every failure sent to the logger, so that a slow
 * job can never hold up a request.
 */
interface Runner
{
    /**
     * Runs the work in each worker until the process is told to stop
     * (SIGTERM or SIGINT), and returns then. The work receives its Worker
     * and a Cancellation that fires when the worker is stopping; it should
     * finish what it has in hand and return. A worker whose work returns
     * or that dies is started again, except in a single process, which
     * ends when the work returns.
     *
     * @param string $group A name for these workers, such as "queue".
     * @param \Closure(Worker, Cancellation): void $work
     * @param int $workers How many processes run it.
     * @param float $shutdownTimeout Seconds a worker is given to finish before it is ended.
     * @param bool $single Run it in this process, without pcntl and posix; for one worker.
     */
    public function run(string $group, \Closure $work, int $workers = 1, float $shutdownTimeout = 30.0, bool $single = false): void;
}
