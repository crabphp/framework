<?php

declare(strict_types=1);

namespace Crab\Schedule;

use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Cache\Lock;
use Crab\Contracts\Schedule\Schedule as ScheduleContract;
use Crab\Contracts\Schedule\TaskRan;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;
use Crab\Engine\Process\Worker;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs a schedule's tasks when they are due. It wakes at the start of
 * each minute and starts every task due in it, each in a fiber of its
 * own, so a slow task delays no other. A task that fails is logged and
 * runs again when it is next due. A task still running from an earlier
 * minute is not started a second time; one set withoutOverlapping() is
 * not started while it runs on another server or in another generation
 * of this one either.
 *
 * A task set with everySeconds() is not part of that: it has a timer of
 * its own on the worker's loop, which counts from when the scheduler
 * started.
 *
 * A minute during which the scheduler was not running is not made up
 * for: a task due at 03:00 does not run when the scheduler starts at
 * 03:01.
 */
final class Scheduler
{
    /**
     * The names of the tasks running now, in this worker.
     *
     * @var array<string, true>
     */
    private array $running = [];
    /**
     * The lock on each task set with everySeconds() that this scheduler
     * keeps for one server, by the lock's key.
     *
     * @var array<string, Lock>
     */
    private array $kept = [];

    /**
     * @param ContainerInterface|null $container Makes task classes, and holds the console and the queue that commands and jobs go to.
     * @param ClockInterface|null $clock The real time when left out.
     * @param Cache|null $cache Through which servers agree who runs a task marked onOneServer() or withoutOverlapping().
     * @param \DateTimeZone|null $timezone The zone the tasks' times are in; the clock's when left out.
     * @param EventDispatcherInterface|null $events Told of each run of a task, with a TaskRan.
     * @param int $lease Seconds a lease on a run of a task set withoutOverlapping() lasts unless renewed; it is renewed every third of that, so a scheduler that dies leaves the task to others within a lease.
     */
    public function __construct(
        private readonly ScheduleContract $schedule,
        private readonly ?ContainerInterface $container = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?Cache $cache = null,
        private readonly ?\DateTimeZone $timezone = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly int $lease = 60,
    ) {
        if ($lease < 3) {
            throw new \InvalidArgumentException(
                'A lease on a scheduled run lasts at least 3 seconds, so it can be renewed in time.',
            );
        }
    }

    /**
     * The time now, in the schedule's zone.
     */
    public function now(): \DateTimeImmutable
    {
        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        return $this->timezone === null ? $now : $now->setTimezone($this->timezone);
    }

    /**
     * @return list<Task> The tasks due in the minute this time is in.
     */
    public function due(\DateTimeInterface $at): array
    {
        return array_values(array_filter($this->schedule->tasks(), static fn(Task $task): bool => $task->isDue($at)));
    }

    /**
     * Runs one task for the minute it is due in, unless it is still
     * running here or another server has taken this run. What it throws
     * is logged, not thrown.
     *
     * A task that runs by an interval and is still running is passed over
     * without a word: at several runs a second the warning would fill the
     * log.
     *
     * @param Worker|null $worker Counts the run in its "runs", and a failure in its "failed".
     * @return bool Whether it ran and did not fail.
     */
    public function run(Task $task, \DateTimeInterface $minute, ?Worker $worker = null): bool
    {
        $name = $task->named();
        if (isset($this->running[$name])) {
            if ($task->interval() !== null) {
                if ($task->runsOnOneServer()) {
                    // Still at it: the task stays this server's for as long as it runs.
                    try {
                        $this->keep($task, $task->interval());
                    } catch (\Throwable $error) {
                        $this->logger?->error('Scheduled task {task} could not be kept for this server: {message}', [
                            'task' => $name,
                            'message' => $error->getMessage(),
                            'exception' => $error,
                        ]);
                    }
                }
                return false;
            }
            $this->logger?->warning('Scheduled task {task} was due and is still running from before, so it was not started again.', [
                'task' => $name,
            ]);
            return false;
        }
        $this->running[$name] = true;
        $lease = null;
        $renewal = null;
        try {
            if ($task->runsOnOneServer() && !$this->claim($task, $minute)) {
                return false;
            }
            if ($task->preventsOverlaps()) {
                $lease = $this->lease($task);
                if ($lease === null) {
                    $this->logger?->info('Scheduled task {task} is still running on another server, so it was not started here.', [
                        'task' => $name,
                    ]);
                    return false;
                }
                $renewal = $this->renew($lease, $name);
            }
            $started = hrtime(true);
            try {
                $task->run($this->container);
            } catch (\Throwable $error) {
                $this->tell(new TaskRan($name, (hrtime(true) - $started) / 1e9, $error), $worker);
                throw $error;
            }
            $this->tell(new TaskRan($name, (hrtime(true) - $started) / 1e9), $worker);
            return true;
        } catch (\Throwable $error) {
            $this->logger?->error('Scheduled task {task} failed: {message}', [
                'task' => $name,
                'message' => $error->getMessage(),
                'exception' => $error,
            ]);
            return false;
        } finally {
            unset($this->running[$name]);
            if ($renewal !== null) {
                EventLoop::current()->cancel($renewal);
            }
            $this->release($lease, $name);
        }
    }

    /**
     * Starts every task due in the minute this time is in, each as a task
     * of the worker, and returns without waiting for them.
     *
     * @return int How many were started.
     */
    public function tick(Worker $worker, \DateTimeImmutable $at): int
    {
        if ($worker->isDraining()) {
            return 0;
        }
        $due = $this->due($at);
        foreach ($due as $task) {
            $worker->spawn(fn(): bool => $this->run($task, $at, $worker));
        }
        return count($due);
    }

    /**
     * Gives every task that runs by an interval a repeating timer on the
     * worker's loop. Each firing starts the task as a task of the worker,
     * so a slow run holds up neither the timer nor the other tasks.
     *
     * @return list<string> The timers, for EventLoop::cancel().
     */
    public function repeat(Worker $worker): array
    {
        $timers = [];
        foreach ($this->schedule->tasks() as $task) {
            $seconds = $task->interval();
            if ($seconds === null) {
                continue;
            }
            $timers[] = $worker->loop->repeat($seconds, function () use ($worker, $task): void {
                // A worker that is stopping takes no new task, and a timer may still fire while it finishes one.
                if ($worker->isDraining()) {
                    return;
                }
                $worker->spawn(fn(): bool => $this->run($task, $this->now(), $worker));
            });
        }
        return $timers;
    }

    /**
     * Runs the schedule until the cancellation fires: starts the timers
     * of the tasks that run by an interval, then sleeps to the start of
     * each minute and starts what is due in it.
     */
    public function work(Worker $worker, Cancellation $cancellation): void
    {
        $this->check();
        $timers = $this->repeat($worker);
        try {
            $last = $this->now()->format('Y-m-d H:i');
            while (!$cancellation->isCancelled()) {
                $now = $this->now();
                // A little past the minute, so that a timer that fires early still wakes inside it.
                $wait = 60.0 - (float) $now->format('s.u') + 0.01;
                try {
                    Async::sleep($worker->loop, $wait, $cancellation);
                } catch (CancelledException) {
                    return;
                }
                $now = $this->now();
                $minute = $now->format('Y-m-d H:i');
                if ($minute === $last) {
                    continue;
                }
                $last = $minute;
                $this->tick($worker, $now);
            }
        } finally {
            foreach ($timers as $timer) {
                $worker->loop->cancel($timer);
            }
        }
    }

    /**
     * Refuses a schedule that cannot be run as declared, before its first
     * minute and not at three in the morning.
     */
    public function check(): void
    {
        foreach ($this->schedule->tasks() as $task) {
            if ($task->runsOnOneServer() && $this->cache === null) {
                throw new \LogicException(
                    "The scheduled task {$task->named()} is marked onOneServer(), which servers agree on through the cache, and there is none: register the cache provider, CacheProvider, with a store they share.",
                );
            }
            if ($task->preventsOverlaps() && $this->cache === null) {
                throw new \LogicException(
                    "The scheduled task {$task->named()} is marked withoutOverlapping(), which servers agree on through the cache, and there is none: register the cache provider, CacheProvider, with a store they share.",
                );
            }
        }
    }

    /**
     * Tells the listeners of a run, and counts it in the worker's "runs",
     * and a failure in its "failed", which `status` shows.
     */
    private function tell(TaskRan $event, ?Worker $worker): void
    {
        $worker?->count('runs');
        if ($event->error !== null) {
            $worker?->count('failed');
        }
        try {
            $this->events?->dispatch($event);
        } catch (\Throwable $error) {
            $this->logger?->error('A listener for {event} failed: {message}', [
                'event' => TaskRan::class,
                'message' => $error->getMessage(),
                'exception' => $error,
            ]);
        }
    }

    private function claim(Task $task, \DateTimeInterface $minute): bool
    {
        if ($this->cache === null) {
            $this->check();
        }
        if ($task->interval() !== null) {
            return $this->keep($task, $task->interval());
        }
        // A lock on this minute's run, never released: kept for an hour, long enough that no server's clock is still in it.
        return $this->cache->lock('schedule.' . sha1($task->named()) . '.' . $minute->format('YmdHi'), 3600) !== null;
    }

    /**
     * Whether this server is the one that runs a task set with
     * everySeconds(). Servers started at different moments share no name
     * for a run, as they do for a minute, so one of them keeps the task
     * instead: it holds a lock lasting three intervals and refreshes it at
     * every run. When it stops, the lock runs out and the next server to
     * ask takes the task; a refresh after that fails, so two never both
     * think they have it.
     */
    private function keep(Task $task, float $interval): bool
    {
        $key = 'schedule.' . sha1($task->named()) . '.kept';
        $seconds = max(2, (int) ceil($interval * 3));
        if (isset($this->kept[$key]) && $this->kept[$key]->refresh($seconds)) {
            return true;
        }
        unset($this->kept[$key]);
        $lock = $this->cache->lock($key, $seconds);
        if ($lock === null) {
            return false;
        }
        $this->kept[$key] = $lock;
        return true;
    }

    /**
     * A lease on running a task set withoutOverlapping(), or null when
     * another scheduler holds it.
     */
    private function lease(Task $task): ?Lock
    {
        if ($this->cache === null) {
            $this->check();
        }
        return $this->cache->lock('schedule.' . sha1($task->named()) . '.running', $this->lease);
    }

    /**
     * Renews a lease while its task runs, so that a run of any length
     * keeps it and one whose scheduler died gives it up within a lease.
     * A renewal that fails, because the lease ran out or its lock was
     * cleared, is logged: the run goes on, and no longer keeps others out.
     *
     * @return string The timer, to cancel when the run ends.
     */
    private function renew(Lock $lease, string $name): string
    {
        $lost = false;
        return EventLoop::current()->repeat($this->lease / 3, function () use ($lease, $name, &$lost): void {
            if ($lost) {
                return;
            }
            try {
                if ($lease->refresh($this->lease)) {
                    return;
                }
                $lost = true;
                $problem = 'its lease ran out or was cleared';
            } catch (\Throwable $error) {
                // The store did not answer; the next renewal tries again.
                $problem = $error->getMessage();
            }
            $this->logger?->error('Scheduled task {task} could not renew its lease, so another server may start it while it runs: {message}', [
                'task' => $name,
                'message' => $problem,
            ]);
        });
    }

    /**
     * Gives up a lease when its run ends, whatever the end.
     */
    private function release(?Lock $lease, string $name): void
    {
        try {
            $lease?->release();
        } catch (\Throwable $error) {
            $this->logger?->error('Scheduled task {task} could not give up its lease, which runs out by itself: {message}', [
                'task' => $name,
                'message' => $error->getMessage(),
                'exception' => $error,
            ]);
        }
    }
}
