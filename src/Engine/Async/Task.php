<?php

declare(strict_types=1);

namespace Crab\Engine\Async;

use Closure;
use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;
use LogicException;
use Throwable;

/**
 * A tracked task, executed by the worker's event loop.
 */
final class Task
{
    private readonly Cancellation $cancellation;
    private ?string $deferred = null;
    private bool $started = false;
    private bool $complete = false;
    private mixed $value = null;
    private ?Throwable $error = null;
    /**
     * @var array<int, Closure>
     */
    private array $observers = [];
    private int $nextObserver = 0;

    private function __construct(
        private readonly EventLoop $loop,
    ) {
        $this->cancellation = new Cancellation();
    }

    /**
     * @param callable(Cancellation): mixed $body
     */
    public static function spawn(EventLoop $loop, callable $body): self
    {
        $task = new self($loop);
        $task->deferred = $loop->defer(static function () use ($task, $body): void {
            $task->deferred = null;
            $task->started = true;
            try {
                $task->cancellation->throwIfCancelled();
                $value = $body($task->cancellation);
            } catch (Throwable $error) {
                $task->finish(null, $error);
                return;
            }
            $task->finish($value, null);
        });
        return $task;
    }

    public function cancel(?Throwable $reason = null): void
    {
        if ($this->complete) {
            return;
        }
        $this->cancellation->cancel($reason);
        if (!$this->started) {
            $this->loop->cancel($this->deferred);
            $this->deferred = null;
            try {
                $this->cancellation->throwIfCancelled();
            } catch (CancelledException $error) {
                $this->finish(null, $error);
            }
        }
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * Returns the result or throws its failure; the task must have completed.
     */
    public function result(): mixed
    {
        if (!$this->complete) {
            throw new LogicException('Task has not completed.');
        }
        if ($this->error !== null) {
            throw $this->error;
        }
        return $this->value;
    }

    /**
     * @param callable(self): void $observer
     */
    public function onComplete(callable $observer): self
    {
        $observer = Closure::fromCallable($observer);
        if ($this->complete) {
            $this->notify($observer);
        } else {
            $this->observers[$this->nextObserver++] = $observer;
        }
        return $this;
    }

    /**
     * Cancelling the wait does not cancel the independently owned target task.
     */
    public function await(?Cancellation $cancellation = null): mixed
    {
        $cancellation?->throwIfCancelled();
        if ($this->complete) {
            return $this->result();
        }
        $observerId = $this->nextObserver++;
        return Async::await(
            $this->loop,
            function (\Closure $resume, \Closure $fail) use ($observerId): \Closure {
                // Told when the task completes: its result, or what it failed with, settles the wait.
                $this->observers[$observerId] = function () use ($resume, $fail): void {
                    try {
                        $value = $this->result();
                    } catch (Throwable $failure) {
                        $fail($failure);
                        return;
                    }
                    $resume($value);
                };
                return function () use ($observerId): void {
                    unset($this->observers[$observerId]);
                };
            },
            $cancellation,
        );
    }

    private function finish(mixed $value, ?Throwable $error): void
    {
        if ($this->complete) {
            return;
        }
        $this->value = $value;
        $this->error = $error;
        $this->complete = true;
        $observers = $this->observers;
        $this->observers = [];
        foreach ($observers as $observer) {
            $this->notify($observer);
        }
    }

    private function notify(Closure $observer): void
    {
        try {
            $observer($this);
        } catch (Throwable $error) {
            FailureReporter::report('Task completion callback failed', $error);
        }
    }
}
