<?php

declare(strict_types=1);

namespace Crab\Testing;

use Crab\Container\Container;
use Crab\Contracts\Container\Container as ContainerContract;
use Crab\Contracts\Provider;
use Crab\Contracts\WorkerProvider;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;

/**
 * Runs providers through a real worker's life in the current process, for
 * tests: register, start, a body of test code, then stop.
 */
final class WorkerHarness
{
    /**
     * The body receives the Worker and the container and runs as a task, so
     * it may sleep or await. Its return value is returned; whatever it, a
     * provider or a timer throws is thrown again once the worker has
     * stopped.
     *
     * @param list<Provider> $providers
     */
    public static function run(array $providers, \Closure $body, ContainerContract $container = new Container()): mixed
    {
        foreach ($providers as $provider) {
            $provider->register($container);
        }
        $workerProviders = array_values(array_filter($providers, static fn(Provider $provider): bool => $provider instanceof WorkerProvider));
        $result = null;
        $failure = null;
        $runtime = new Runtime(shutdownTimeout: 1.0);
        $runtime->group('test')
            ->onStart(static function (Worker $worker) use ($workerProviders, $container, $body, &$result): void {
                foreach ($workerProviders as $provider) {
                    $provider->start($worker, $container);
                }
                $worker->spawn(static function () use ($worker, $container, $body, &$result): void {
                    try {
                        $result = $body($worker, $container);
                    } finally {
                        // Stopping cancels tasks, this one included, so do it after it returns.
                        $worker->loop->defer(static fn() => $worker->stop());
                    }
                });
            })
            ->onStop(static function (Worker $worker) use ($workerProviders, $container): void {
                foreach (array_reverse($workerProviders) as $provider) {
                    $provider->stop($worker, $container);
                }
            })
            ->onFailure(static function (\Throwable $error) use (&$failure): void {
                $failure ??= $error;
            });
        $runtime->run(singleProcess: true);
        if ($failure !== null) {
            throw $failure;
        }
        return $result;
    }
}
