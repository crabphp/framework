<?php

declare(strict_types=1);

namespace Crab\Runs;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Provider;
use Crab\Contracts\Queue\Queue;
use Crab\Contracts\Runs\Runs as RunsContract;
use Crab\Runs\Commands\InstallRuns;
use Crab\Runs\Commands\ListRuns;
use Crab\Runs\Commands\RetryRun;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Binds runs from the "runs" configuration section, and adds the
 * `runs:install`, `runs:list` and `runs:retry` commands. Runs are kept in
 * the database and carried out by queue workers, so the database and the
 * queue providers come before this one.
 *
 *     table      the runs' table; "runs"
 *     steps      the table of their steps; "run_steps"
 *     database   the name of the database they are in; the default one when null
 *     queue      the queue their work is pushed to; the queue's own when null
 *     tries      how many times a run is tried before it is failed; 3
 *     backoff    seconds before the second try, the third, and so on; [10, 60]
 *     lease      seconds a worker's hold on a run lasts unless renewed; 90
 */
final class RunsProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults(
            'runs',
            [
                'table' => 'runs',
                'steps' => 'run_steps',
                'database' => null,
                'queue' => null,
                'tries' => 3,
                'backoff' => [10, 60],
                'lease' => 90,
            ],
            [
                'table' => ['type' => 'string', 'minLength' => 1],
                'steps' => ['type' => 'string', 'minLength' => 1],
                'database' => ['type' => ['string', 'null'], 'minLength' => 1],
                'queue' => ['type' => ['string', 'null'], 'minLength' => 1],
                'tries' => ['type' => 'integer', 'minimum' => 1],
                'backoff' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 0]],
                'lease' => ['type' => 'integer', 'minimum' => 3],
            ],
        );
        $container->singleton(Runs::class, static function (Container $container): Runs {
            $config = $container->get(Config::class);
            $config->check('runs');
            if (!$container->has(Database::class) || !$container->has(Queue::class)) {
                throw new \LogicException(
                    'Runs are kept in the database and carried out by queue workers: register DatabaseProvider and QueueProvider as well as RunsProvider.',
                );
            }
            $database = $container->get(Database::class);
            $name = $config->get('runs.database');
            $queue = $container->get(Queue::class);
            $on = $config->get('runs.queue');
            return new Runs(
                is_string($name) ? $database->connection($name) : $database,
                is_string($on) ? $queue->on($on) : $queue,
                $container,
                $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null,
                $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
                (string) $config->get('runs.table'),
                (string) $config->get('runs.steps'),
                (int) $config->get('runs.tries'),
                array_map(intval(...), (array) $config->get('runs.backoff')),
                (int) $config->get('runs.lease'),
            );
        });
        $container->singleton(RunsContract::class, Runs::class);
        if ($container->has(Console::class)) {
            $console = $container->get(Console::class);
            $console->add('runs:list', ListRuns::class);
            $console->add('runs:retry', RetryRun::class);
            $console->add('runs:install', InstallRuns::class);
        }
    }
}
