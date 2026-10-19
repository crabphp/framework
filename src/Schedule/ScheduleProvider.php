<?php

declare(strict_types=1);

namespace Crab\Schedule;

use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Provider;
use Crab\Contracts\Schedule\Schedule as ScheduleContract;
use Crab\Contracts\Servers;
use Crab\Contracts\Workers;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Worker;
use Crab\Schedule\Commands\ListTasks;
use Crab\Schedule\Commands\Work;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Binds the schedule, and adds the `schedule:work` and `schedule:list`
 * commands. Register it before the providers that declare tasks, which
 * they do in register():
 *
 *     $container->get(Schedule::class)->command('reports:send')->daily('07:00');
 *
 * "schedule.timezone" is the zone the tasks' times are in, such as
 * "Europe/Paris"; PHP's own when null.
 */
final class ScheduleProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults('schedule', [
            'timezone' => null,
        ]);
        // Made now, in the parent: the tasks are part of what the application is defined as.
        $schedule = new Schedule();
        $container->instance(ScheduleContract::class, $schedule);
        $container->singleton(Schedule::class, ScheduleContract::class);
        $container->singleton(Scheduler::class, static function (Container $container): Scheduler {
            $timezone = $container->get(Config::class)->get('schedule.timezone');
            return new Scheduler(
                $container->get(ScheduleContract::class),
                $container,
                $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
                $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null,
                $container->has(Cache::class) ? $container->get(Cache::class) : null,
                is_string($timezone) && $timezone !== '' ? new \DateTimeZone($timezone) : null,
                $container->has(EventDispatcherInterface::class)
                    ? $container->get(EventDispatcherInterface::class)
                    : null,
            );
        });
        if ($container->has(Servers::class)) {
            // Named in "server.with", the scheduler is run by `serve` itself, beside HTTP, in one worker of its own.
            $container->get(Servers::class)->add(
                'schedule',
                static function () use ($container): array {
                    $scheduler = $container->get(Scheduler::class);
                    // Before anything is started, so that a schedule that cannot run stops the server from starting.
                    $scheduler->check();
                    return [new Workers('schedule', static function (Worker $worker, Cancellation $cancellation) use (
                        $scheduler,
                    ): void {
                        $scheduler->work($worker, $cancellation);
                    })];
                },
                30.0,
                'schedule:work',
            );
        }
        if ($container->has(Console::class)) {
            $console = $container->get(Console::class);
            $console->add('schedule:work', Work::class);
            $console->add('schedule:list', ListTasks::class);
        }
    }
}
