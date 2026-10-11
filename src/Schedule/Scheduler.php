<?php

declare(strict_types=1);

namespace Crab\Schedule;

use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Schedule\TaskRan;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
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
 * minute is not started a second time.
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
     * What this scheduler signs with when it keeps a task for one server.
     */
    private readonly string $owner;
    /**
     * When this scheduler last signed each task it keeps for one server, by the entry's key.
     *
     * @var array<string, float>
     */
    private array $kept = [];

    /**
     * @param ContainerInterface|null $container Makes task classes, and holds the console and the queue that commands and jobs go to.
     * @param ClockInterface|null $clock The real time when left out.
     * @param Cache|null $cache Through which servers agree who runs a task marked onOneServer().
     * @param \DateTimeZone|null $timezone The zone the tasks' times are in; the clock's when left out.
     * @param EventDispatcherInterface|null $events Told of each run of a task, with a TaskRan.
     */
    public function __construct(
        private readonly Schedule $schedule,
        private readonly ?ContainerInterface $container = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?Cache $cache = null,
        private readonly ?\DateTimeZone $timezone = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->owner = bin2hex(random_bytes(8));
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
        return array_values(array_filter($this->schedule->tasks(), static fn (Task $task): bool => $task->isDue($at)));
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
     * @return bool Whether it ran and did not fail.
     */
    public function run(Task $task, \DateTimeInterface $minute): bool
    {
        $name = $task->named();
        if (isset($this->running[$name])) {
            if ($task->interval() !== null) {
                if ($task->runsOnOneServer()) {
                    // Still at it: the task stays this server's for as long as it runs.
                    try {
                        $this->keep($task, $task->interval());
                    } catch (\Throwable $error) {
                        $this->logger?->error('Scheduled task {task} could not be kept for this server: {message}', ['task' => $name, 'message' => $error->getMessage(), 'exception' => $error]);
                    }
                }
                return false;
            }
            $this->logger?->warning('Scheduled task {task} was due and is still running from before, so it was not started again.', ['task' => $name]);
            return false;
        }
        $this->running[$name] = true;
        try {
            if ($task->runsOnOneServer() && !$this->claim($task, $minute)) {
                return false;
            }
            $started = hrtime(true);
            try {
                $task->run($this->container);
            } catch (\Throwable $error) {
                $this->tell(new TaskRan($name, (hrtime(true) - $started) / 1e9, $error));
                throw $error;
            }
            $this->tell(new TaskRan($name, (hrtime(true) - $started) / 1e9));
            return true;
        } catch (\Throwable $error) {
            $this->logger?->error('Scheduled task {task} failed: {message}', ['task' => $name, 'message' => $error->getMessage(), 'exception' => $error]);
            return false;
        } finally {
            unset($this->running[$name]);
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
        $due = $this->due($at);
        foreach ($due as $task) {
            $worker->spawn(fn (): bool => $this->run($task, $at));
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
                $worker->spawn(fn (): bool => $this->run($task, $this->now()));
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
                throw new \LogicException("The scheduled task {$task->named()} is marked onOneServer(), which servers agree on through the cache, and there is none: register the cache provider, CacheProvider, with a store they share.");
            }
        }
    }

    /**
     * Tells the application's listeners of a run. What a listener throws
     * is logged and goes no further.
     */
    private function tell(TaskRan $event): void
    {
        try {
            $this->events?->dispatch($event);
        } catch (\Throwable $error) {
            $this->logger?->error('A listener for {event} failed: {message}', ['event' => TaskRan::class, 'message' => $error->getMessage(), 'exception' => $error]);
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
        // Kept for an hour: long enough that no server's clock is still in this minute.
        return $this->cache->add('schedule.' . sha1($task->named()) . '.' . $minute->format('YmdHi'), true, 3600);
    }

    /**
     * Whether this server is the one that runs a task set with
     * everySeconds(). Servers started at different moments share no name
     * for a run, as they do for a minute, so one of them keeps the task
     * instead: it signs an entry that lasts three intervals and signs it
     * again at every run. When it stops, the entry runs out and the next
     * server to ask takes the task.
     */
    private function keep(Task $task, float $interval): bool
    {
        $key = 'schedule.' . sha1($task->named()) . '.kept';
        $seconds = max(2, (int) ceil($interval * 3));
        $now = hrtime(true) / 1e9;
        if ($this->cache->add($key, $this->owner, $seconds)) {
            $this->kept[$key] = $now;
            return true;
        }
        // Signed again only while the last signing cannot have run out. After a pause longer than
        // that, another server may have taken the task, and writing over its entry would give
        // the task to two: it is asked for afresh, which only one of them can win.
        $fresh = isset($this->kept[$key]) && $now - $this->kept[$key] < $seconds * 2 / 3;
        if (!$fresh || $this->cache->get($key) !== $this->owner) {
            unset($this->kept[$key]);
            return false;
        }
        $this->cache->set($key, $this->owner, $seconds);
        $this->kept[$key] = $now;
        return true;
    }
}
