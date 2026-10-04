<?php

declare(strict_types=1);

namespace Crab\Contracts;

use Crab\Contracts\Container\Container;
use Crab\Engine\Process\Worker;

/**
 * A provider with work to do in each worker: what holds a socket, a file or
 * a timer is opened in start() and released in stop(), never in register(),
 * which runs once in the parent process.
 */
interface WorkerProvider extends Provider
{
    /**
     * Runs in each worker after the engine forks and before the worker
     * accepts requests. Throwing stops the worker from starting.
     */
    public function start(Worker $worker, Container $container): void;

    /**
     * Runs in each worker after it has drained, in reverse order of
     * registration, and only if every provider started. The event loop has
     * stopped, so it must not suspend.
     */
    public function stop(Worker $worker, Container $container): void;
}
