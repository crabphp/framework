<?php

declare(strict_types=1);

namespace Crab\Contracts\Schedule;

/**
 * The work an application does by the clock. Tasks are declared while the
 * application is defined, in a provider's register(), and `schedule:work`
 * runs each when it is due:
 *
 *     $schedule->command('report:send')->daily('07:00');
 *     $schedule->job(PruneTokens::class, olderThanDays: 30)->hourly();
 *     $schedule->call(Heartbeat::class)->everyMinutes(5);
 *
 * A task that has not been told when runs every minute.
 */
interface Schedule
{
    /**
     * Runs a closure, or a class with handle() that the container makes,
     * its services taken in its constructor.
     *
     * @param \Closure(): mixed|class-string $task
     */
    public function call(\Closure|string $task): Task;

    /**
     * Runs one of the application's console commands, with its words:
     * command('report:send', '--month=last'). An exit status other than
     * 0 is logged as a failure.
     */
    public function command(string $name, string ...$arguments): Task;

    /**
     * Pushes a job onto the queue, as Queue::push() does, so that the work
     * is done by a queue worker and retried there when it fails.
     *
     * @param class-string $job
     * @param mixed ...$data The parameters of the job's handle(), by name.
     */
    public function job(string $job, mixed ...$data): Task;

    /**
     * @return list<Task> Every task declared so far, in the order declared.
     */
    public function tasks(): array;
}
