<?php

declare(strict_types=1);

namespace Crab\Queue;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Provider;
use Crab\Contracts\Queue\Queue as QueueContract;
use Crab\Contracts\Queue\Store;
use Crab\Queue\Commands\FailedJobs;
use Crab\Queue\Commands\ForgetJobs;
use Crab\Queue\Commands\InstallQueue;
use Crab\Queue\Commands\RetryJobs;
use Crab\Queue\Commands\Work;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Binds the queue from the "queue" configuration section, and adds the
 * `queue:work`, `queue:failed`, `queue:retry`, `queue:forget` and
 * `queue:install` commands.
 *
 *     store         "database", or "memory" for tests; database unless set
 *     table         the database store's table; "jobs"
 *     database      the name of the database it is in; the default one when null
 *     queue         the name jobs are pushed under and taken from when none is given; "default"
 *     tries         how many times a job is started before it is given up on; 3
 *     backoff       seconds to wait before the second try, the third, and so on; [10, 60]
 *     retry_after   seconds after which a job that was taken and never finished is given to another worker; 90
 *     workers       worker processes `queue:work` starts; 1
 *     concurrency   jobs one worker runs at a time; 1
 *     sleep         seconds a worker waits before looking again when no job was due; 1
 *     grace         seconds a worker is given to finish its job when it is told to stop; 30
 *     supervisors   named sets of workers, each with its queues and limits; see Supervisor. None unless set
 *
 * To keep jobs somewhere else, bind Contracts\Queue\Store to a store of
 * your own after registering this provider.
 */
final class QueueProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults('queue', [
            'store' => 'database',
            'table' => 'jobs',
            'database' => null,
            'queue' => 'default',
            'tries' => 3,
            'backoff' => [10, 60],
            'retry_after' => 90,
            'workers' => 1,
            'concurrency' => 1,
            'sleep' => 1.0,
            'grace' => 30.0,
            'supervisors' => [],
        ]);
        $container->singleton(Store::class, static function (Container $container): Store {
            $config = $container->get(Config::class);
            $store = (string) $config->get('queue.store');
            if ($store === 'memory') {
                return new MemoryStore();
            }
            if ($store === 'database') {
                if (!$container->has(Database::class)) {
                    throw new \LogicException('The queue keeps its jobs in the database ("queue.store" is "database"): register the database provider, DatabaseProvider, as well as QueueProvider.');
                }
                $database = $container->get(Database::class);
                $name = $config->get('queue.database');
                return new DatabaseStore(is_string($name) ? $database->connection($name) : $database, (string) $config->get('queue.table'));
            }
            throw new \LogicException("\"queue.store\" is \"{$store}\", and the stores are \"database\" and \"memory\". For another, bind " . Store::class . ' to a store of your own.');
        });
        $container->singleton(QueueContract::class, static fn (Container $container): Queue => new Queue(
            $container->get(Store::class),
            (string) $container->get(Config::class)->get('queue.queue'),
            $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null,
        ));
        $container->singleton(Queue::class, QueueContract::class);
        $container->singleton(Consumer::class, static function (Container $container): Consumer {
            $config = $container->get(Config::class);
            return new Consumer(
                $container->get(Store::class),
                $container,
                $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
                $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null,
                (int) $config->get('queue.tries'),
                array_map(intval(...), array_values((array) $config->get('queue.backoff'))),
                (int) $config->get('queue.retry_after'),
                $container->has(EventDispatcherInterface::class) ? $container->get(EventDispatcherInterface::class) : null,
            );
        });
        if ($container->has(Console::class)) {
            $console = $container->get(Console::class);
            $console->add('queue:work', Work::class);
            $console->add('queue:failed', FailedJobs::class);
            $console->add('queue:retry', RetryJobs::class);
            $console->add('queue:forget', ForgetJobs::class);
            $console->add('queue:install', InstallQueue::class);
        }
    }
}
