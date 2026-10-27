<?php

declare(strict_types=1);

namespace Crab\Queue;

use Crab\Contracts\Queue\JobRan;
use Crab\Contracts\Queue\Message;
use Crab\Contracts\Queue\Store;
use Crab\Contracts\Queue\TimedOut;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\Process\Worker;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Takes jobs from a store and runs them. A job that throws is tried again
 * after a pause, and given up on when it has been started as many times
 * as it may be; either way the failure is logged and the consumer goes on
 * to the next job.
 *
 * A job is resolved for each try: the container determines whether it
 * is kept, and without a container it is constructed each time. Its
 * constructor takes services and it holds nothing of one run.
 *
 * In a worker, a job that runs keeps its reservation: the consumer
 * renews it every third of $retryAfter, so another worker is given the
 * job only when this one has died or is blocked that long. A job with a
 * timeout is told by the Cancellation its handle() may take, under any
 * name, when the time is up; one that has not stopped within $grace
 * after that has its worker's process ended by the supervisor, as one
 * that blocks the process does. Every other job of that process ends
 * with it and is given to another worker later, so a supervisor whose
 * jobs have a timeout is best run at concurrency 1.
 */
final readonly class Consumer
{
    /**
     * @param ContainerInterface|null $container Makes the job classes; without one a job is made with `new`.
     * @param int $tries How many times a job is started before it is given up on.
     * @param list<int> $backoff Seconds to wait before the second try, the third, and so on; the last is used for every later one.
     * @param int $retryAfter Seconds after which a job that was taken and neither renewed nor finished is given to another worker. Longer than a job may block its process.
     * @param EventDispatcherInterface|null $events Told of each try of a job, with a JobRan.
     * @param Worker|null $worker Counts each try in its "jobs" and each that failed in its "failed", which `status` shows; runs the renewals and timeouts, which a consumer without one does not have.
     * @param int|null $timeout Seconds one try may run, unless the job says otherwise with #[Timeout]; null for no limit.
     * @param float $grace Seconds a job past its timeout is given to stop before its process is ended.
     */
    public function __construct(
        private Store $store,
        private ?ContainerInterface $container = null,
        private ?LoggerInterface $logger = null,
        private ?ClockInterface $clock = null,
        private int $tries = 3,
        private array $backoff = [10, 60],
        private int $retryAfter = 90,
        private ?EventDispatcherInterface $events = null,
        private ?Worker $worker = null,
        private ?int $timeout = null,
        private float $grace = 30.0,
    ) {
        if ($tries < 1 || $retryAfter < 1 || $timeout !== null && $timeout < 1) {
            throw new \InvalidArgumentException(
                'A queue consumer needs at least one try for a job, a positive number of seconds to retry after, and a timeout of at least one second or none.',
            );
        }
    }

    /**
     * A consumer of the same store that works within a supervisor's
     * limits in place of this one's.
     */
    public function within(Supervisor $supervisor, ?Worker $worker = null): self
    {
        return new self(
            $this->store,
            $this->container,
            $this->logger,
            $this->clock,
            $supervisor->tries,
            $supervisor->backoff,
            $supervisor->retryAfter,
            $this->events,
            $worker ?? $this->worker,
            $supervisor->timeout,
            $this->grace,
        );
    }

    /**
     * Runs the next job that is due, if there is one.
     *
     * @param list<string> $queues Queue names, the most urgent first.
     * @return bool Whether there was a job, however it ended.
     */
    public function runNext(array $queues = ['default']): bool
    {
        $message = $this->store->reserve($queues, $this->now(), $this->retryAfter);
        if ($message === null) {
            return false;
        }
        $started = hrtime(true);
        try {
            $retry = Retry::for($message->job);
            $timeout = Timeout::for($message->job)->seconds ?? $this->timeout;
        } catch (\InvalidArgumentException $error) {
            // An older producer may have stored a job whose declaration is now invalid.
            $this->giveUp($message, $error, $message->attempts);
            $this->tell(new JobRan($message, (hrtime(true) - $started) / 1e9, $error, true));
            return true;
        }
        $tries = $retry->tries ?? $this->tries;
        $backoff = $retry->backoff ?? $this->backoff;
        if ($message->attempts > $tries) {
            // This reservation only detects exhaustion; the limit may have changed since earlier tries.
            $attempts = $message->attempts - 1;
            $taken = $attempts === 1 ? 'once' : "{$attempts} times";
            $error = new \RuntimeException(
                "The retry limit of {$tries} is exhausted; the job was taken {$taken} before this check. This can happen without a recorded failure when a worker stopped while running it, its process was ended because it ran past its timeout, or it blocked its process longer than {$this->retryAfter} seconds (queue.retry_after). It can also happen when its limit was lowered. Review the job before retrying it with queue:retry.",
            );
            $this->giveUp($message, $error, $attempts);
            $this->tell(new JobRan($message, (hrtime(true) - $started) / 1e9, $error, true));
            return true;
        }
        $run = new Cancellation();
        $end = $this->watch($message, $run, $timeout);
        try {
            try {
                $job = $this->container?->has($message->job)
                    ? $this->container->get($message->job)
                    : self::make($message->job);
                $given = self::cancellationParameter($message->job);
                $job->handle(...$message->data, ...$given === null ? [] : [$given => $run]);
            } finally {
                // Before the store hears how it ended, so that no renewal follows.
                $end();
            }
        } catch (\Throwable $error) {
            if ($error instanceof CancelledException && $error->getPrevious() instanceof TimedOut) {
                $error = $error->getPrevious();
            }
            $seconds = (hrtime(true) - $started) / 1e9;
            $gaveUp = $message->attempts >= $tries;
            if ($gaveUp) {
                $this->giveUp($message, $error, $message->attempts);
            } else {
                $wait = $backoff === [] ? 0 : $backoff[min($message->attempts, count($backoff)) - 1];
                $this->store->release($message, $this->now() + $wait);
                $this->logger?->warning('Job {job} failed on try {attempt} of {tries} and will be tried again in {wait} seconds: {message}', [
                    'job' => $message->job,
                    'id' => $message->id,
                    'queue' => $message->queue,
                    'attempt' => $message->attempts,
                    'tries' => $tries,
                    'wait' => $wait,
                    'message' => $error->getMessage(),
                    'exception' => $error,
                ]);
            }
            // After the store knows: a listener that looks for the failed job finds it.
            $this->tell(new JobRan($message, $seconds, $error, $gaveUp));
            return true;
        }
        $this->store->complete($message);
        $this->tell(new JobRan($message, (hrtime(true) - $started) / 1e9));
        return true;
    }

    /**
     * Keeps the job this worker's while it runs, and times it: renews the
     * reservation, cancels $run when the job is past its timeout or the
     * reservation was lost after all, and sets the process's deadline.
     *
     * @return \Closure(): void Ends it all, once the job has returned.
     */
    private function watch(Message $message, Cancellation $run, ?int $timeout): \Closure
    {
        $worker = $this->worker;
        if ($worker === null) {
            return static function (): void {};
        }
        $loop = $worker->loop;
        $ended = new Cancellation();
        Task::spawn($loop, function () use ($loop, $ended, $message, $run): void {
            $every = max(1.0, $this->retryAfter / 3);
            while (true) {
                try {
                    Async::sleep($loop, $every, $ended);
                } catch (CancelledException) {
                    return;
                }
                try {
                    $kept = $this->store->renew($message, $this->now());
                } catch (\Throwable $error) {
                    // Tried again at the next turn; a reservation lasts three of them.
                    $this->logger?->warning('Job {job} could not be renewed: {message}', [
                        'job' => $message->job,
                        'id' => $message->id,
                        'queue' => $message->queue,
                        'message' => $error->getMessage(),
                        'exception' => $error,
                    ]);
                    continue;
                }
                if ($ended->isCancelled()) {
                    return;
                }
                if (!$kept) {
                    $this->logger?->warning(
                        'Job {job} is no longer this worker\'s; it was given to another after {seconds} seconds without a renewal, and is told to stop.',
                        [
                            'job' => $message->job,
                            'id' => $message->id,
                            'queue' => $message->queue,
                            'seconds' => $this->retryAfter,
                        ],
                    );
                    $run->cancel(new \RuntimeException('The job was given to another worker.'));
                    return;
                }
            }
        });
        $timer = null;
        $withdraw = static function (): void {};
        if ($timeout !== null) {
            $timer = $loop->delay($timeout, static function () use ($run, $timeout): void {
                $run->cancel(new TimedOut($timeout));
            });
            $withdraw = $worker->deadline($timeout + $this->grace);
        }
        return static function () use ($ended, $loop, $timer, $withdraw): void {
            $ended->cancel();
            if ($timer !== null) {
                $loop->cancel($timer);
            }
            $withdraw();
        };
    }

    /**
     * Whether a parameter of a job's handle() is for the cancellation the
     * worker gives it, and so is not the job's data.
     *
     * @internal
     */
    public static function isCancellation(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();
        return $type instanceof \ReflectionNamedType && $type->getName() === Cancellation::class;
    }

    /**
     * The name of the parameter a job's handle() takes its cancellation
     * by, once per process; null when it takes none.
     */
    private static function cancellationParameter(string $job): ?string
    {
        /** @var array<string, string|null> $names */
        static $names = [];
        if (array_key_exists($job, $names)) {
            return $names[$job];
        }
        foreach (new \ReflectionMethod($job, 'handle')->getParameters() as $parameter) {
            if (!self::isCancellation($parameter)) {
                continue;
            }
            return $names[$job] = $parameter->getName();
        }
        return $names[$job] = null;
    }

    /**
     * Tells the application's listeners of a try. What a listener throws
     * is logged and goes no further: a job's fate is not theirs to change.
     */
    private function tell(JobRan $event): void
    {
        $this->worker?->count('jobs');
        if ($event->error !== null) {
            $this->worker?->count('failed');
        }
        try {
            $this->events?->dispatch($event);
        } catch (\Throwable $error) {
            $this->logger?->error('A listener for {event} failed: {message}', [
                'event' => JobRan::class,
                'message' => $error->getMessage(),
                'exception' => $error,
            ]);
        }
    }

    /**
     * Runs jobs one after another until the cancellation fires, waiting
     * when there are none. A failure of the store itself, such as a
     * database that cannot be reached, is logged and waited out too.
     *
     * @param list<string> $queues Queue names, the most urgent first.
     * @param float $sleep Seconds to wait before looking again when no job was due.
     * @param bool $stopWhenEmpty Return when no job is due, instead of waiting for one.
     * @return int How many jobs were run.
     */
    public function work(
        EventLoop $loop,
        Cancellation $cancellation,
        array $queues = ['default'],
        float $sleep = 1.0,
        bool $stopWhenEmpty = false,
    ): int {
        $ran = 0;
        while (!$cancellation->isCancelled()) {
            $found = false;
            try {
                $found = $this->runNext($queues);
            } catch (\Throwable $error) {
                $this->logger?->error('The queue could not be read: {message}', [
                    'message' => $error->getMessage(),
                    'exception' => $error,
                ]);
            }
            if ($found) {
                ++$ran;
                // Other fibers of this worker get a turn between two jobs.
                $pause = 0.0;
            } elseif ($stopWhenEmpty) {
                break;
            } else {
                $pause = $sleep;
            }
            try {
                Async::sleep($loop, $pause, $cancellation);
            } catch (CancelledException) {
                break;
            }
        }
        return $ran;
    }

    /**
     * Keeps the failed job and logs its counted attempts. A reservation
     * used only to detect exhaustion is left out of the log's count.
     */
    private function giveUp(Message $message, \Throwable $error, int $attempts): void
    {
        $this->store->fail($message, $error::class . ': ' . $error->getMessage(), $this->now());
        $this->logger?->error('Job {job} failed for good after {attempt} tries: {message}', [
            'job' => $message->job,
            'id' => $message->id,
            'queue' => $message->queue,
            'attempt' => $attempts,
            'message' => $error->getMessage(),
            'exception' => $error,
        ]);
    }

    private static function make(string $class): object
    {
        if (!class_exists($class) || !method_exists($class, 'handle')) {
            throw new \LogicException(
                "The job {$class} cannot be run: there is no such class with a handle() method. It may have been renamed or removed since the job was pushed.",
            );
        }
        return new $class();
    }

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }
}
