<?php

declare(strict_types=1);

namespace Crab\Engine\Async;

use Closure;
use LogicException;
use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;
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

    private function __construct(private readonly EventLoop $loop)
    {
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
        $suspension = $this->loop->getSuspension();
        $observerId = $this->nextObserver++;
        $settled = false;
        $unsubscribe = null;
        $finish = function (?Throwable $error = null) use ($suspension, $observerId, &$settled, &$unsubscribe): void {
            if ($settled) {
                return;
            }
            $settled = true;
            unset($this->observers[$observerId]);
            $unsubscribe?->__invoke();
            if ($error !== null) {
                $suspension->throw($error);
                return;
            }
            try {
                $value = $this->result();
            } catch (Throwable $failure) {
                $suspension->throw($failure);
                return;
            }
            $suspension->resume($value);
        };
        $this->observers[$observerId] = static fn () => $finish();
        $unsubscribe = $cancellation?->subscribe(static fn (CancelledException $error) => $finish($error));
        try {
            return $suspension->suspend();
        } finally {
            $settled = true;
            unset($this->observers[$observerId]);
            $unsubscribe?->__invoke();
        }
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
