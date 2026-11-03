<?php

declare(strict_types=1);

namespace Crab\Testing;

use Crab\Container\Container;
use Crab\Contracts\Container\Container as ContainerContract;
use Crab\Contracts\Plugin;
use Crab\Contracts\WorkerPlugin;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;

/**
 * Runs plugins through a real worker's life in the current process, for
 * tests: register, start, a body of test code, then stop.
 */
final class WorkerHarness
{
    /**
     * The body receives the Worker and the container and runs as a task, so
     * it may sleep or await. Its return value is returned; whatever it, a
     * plugin or a timer throws is thrown again once the worker has
     * stopped.
     *
     * @param list<Plugin> $plugins
     */
    public static function run(array $plugins, \Closure $body, ContainerContract $container = new Container()): mixed
    {
        foreach ($plugins as $plugin) {
            $plugin->register($container);
        }
        $workerPlugins = array_values(array_filter(
            $plugins,
            static fn(Plugin $plugin): bool => $plugin instanceof WorkerPlugin,
        ));
        $result = null;
        $failure = null;
        $runtime = new Runtime(shutdownTimeout: 1.0);
        $runtime
            ->group('test')
            ->onStart(static function (Worker $worker) use ($workerPlugins, $container, $body, &$result): void {
                foreach ($workerPlugins as $plugin) {
                    $plugin->start($worker, $container);
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
            ->onStop(static function (Worker $worker) use ($workerPlugins, $container, &$failure): void {
                foreach (array_reverse($workerPlugins) as $plugin) {
                    try {
                        $plugin->stop($worker, $container);
                    } catch (\Throwable $error) {
                        $failure ??= $error;
                    }
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
