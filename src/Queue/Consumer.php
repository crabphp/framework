<?php

declare(strict_types=1);

namespace Crab\Queue;

use Crab\Contracts\Queue\JobRan;
use Crab\Contracts\Queue\Message;
use Crab\Contracts\Queue\Store;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;
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
 * A job is made by the container, once per worker, and kept: its
 * constructor takes services and it holds nothing of one run.
 */
final readonly class Consumer
{
    /**
     * @param ContainerInterface|null $container Makes the job classes; without one a job is made with `new`.
     * @param int $tries How many times a job is started before it is given up on.
     * @param list<int> $backoff Seconds to wait before the second try, the third, and so on; the last is used for every later one.
     * @param int $retryAfter Seconds after which a job that was taken and neither finished nor failed is given to another worker. Longer than the longest job takes.
     * @param EventDispatcherInterface|null $events Told of each try of a job, with a JobRan.
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
    ) {
        if ($tries < 1 || $retryAfter < 1) {
            throw new \InvalidArgumentException('A queue consumer needs at least one try for a job and a positive number of seconds to retry after.');
        }
    }

    /**
     * A consumer of the same store that works within a supervisor's
     * limits in place of this one's.
     */
    public function within(Supervisor $supervisor): self
    {
        return new self($this->store, $this->container, $this->logger, $this->clock, $supervisor->tries, $supervisor->backoff, $supervisor->retryAfter, $this->events);
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
        if ($message->attempts > $this->tries) {
            // It was taken as often as it may be and never reported back: its workers died under it.
            $this->giveUp($message, new \RuntimeException("The job was started {$this->tries} times and never finished; the worker running it stopped or took longer than {$this->retryAfter} seconds."));
            return true;
        }
        $started = hrtime(true);
        try {
            $job = $this->container?->has($message->job) ? $this->container->get($message->job) : self::make($message->job);
            $job->handle(...$message->data);
        } catch (\Throwable $error) {
            $seconds = (hrtime(true) - $started) / 1e9;
            $gaveUp = $message->attempts >= $this->tries;
            if ($gaveUp) {
                $this->giveUp($message, $error);
            } else {
                $wait = $this->backoff === [] ? 0 : $this->backoff[min($message->attempts, count($this->backoff)) - 1];
                $this->store->release($message, $this->now() + $wait);
                $this->logger?->warning('Job {job} failed on try {attempt} of {tries} and will be tried again in {wait} seconds: {message}', [
                    'job' => $message->job, 'id' => $message->id, 'queue' => $message->queue,
                    'attempt' => $message->attempts, 'tries' => $this->tries, 'wait' => $wait,
                    'message' => $error->getMessage(), 'exception' => $error,
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
     * Tells the application's listeners of a try. What a listener throws
     * is logged and goes no further: a job's fate is not theirs to change.
     */
    private function tell(JobRan $event): void
    {
        try {
            $this->events?->dispatch($event);
        } catch (\Throwable $error) {
            $this->logger?->error('A listener for {event} failed: {message}', ['event' => JobRan::class, 'message' => $error->getMessage(), 'exception' => $error]);
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
    public function work(EventLoop $loop, Cancellation $cancellation, array $queues = ['default'], float $sleep = 1.0, bool $stopWhenEmpty = false): int
    {
        $ran = 0;
        while (!$cancellation->isCancelled()) {
            $found = false;
            try {
                $found = $this->runNext($queues);
            } catch (\Throwable $error) {
                $this->logger?->error('The queue could not be read: {message}', ['message' => $error->getMessage(), 'exception' => $error]);
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

    private function giveUp(Message $message, \Throwable $error): void
    {
        $this->store->fail($message, $error::class . ': ' . $error->getMessage(), $this->now());
        $this->logger?->error('Job {job} failed for good after {attempt} tries: {message}', [
            'job' => $message->job, 'id' => $message->id, 'queue' => $message->queue,
            'attempt' => min($message->attempts, $this->tries),
            'message' => $error->getMessage(), 'exception' => $error,
        ]);
    }

    private static function make(string $class): object
    {
        if (!class_exists($class) || !method_exists($class, 'handle')) {
            throw new \LogicException("The job {$class} cannot be run: there is no such class with a handle() method. It may have been renamed or removed since the job was pushed.");
        }
        return new $class();
    }

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }
}
