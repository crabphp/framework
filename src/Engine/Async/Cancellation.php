<?php

declare(strict_types=1);

namespace Crab\Engine\Async;

use Closure;
use Throwable;

/**
 * Cooperative cancellation; subscribers must not suspend.
 * Default exceptions are created when observed, so their trace starts there.
 */
final class Cancellation
{
    private bool $cancelled = false;
    private ?CancelledException $reason = null;
    /**
     * @var array<int, Closure>
     */
    private array $subscribers = [];
    private int $nextId = 0;

    public function cancel(?Throwable $reason = null): void
    {
        if ($this->cancelled) {
            return;
        }
        $this->cancelled = true;
        if ($reason !== null) {
            $this->reason = $reason instanceof CancelledException
                ? $reason
                : new CancelledException('Operation cancelled.', previous: $reason);
        }
        if ($this->subscribers === []) {
            return;
        }
        $subscribers = $this->subscribers;
        $this->subscribers = [];
        foreach ($subscribers as $subscriber) {
            $this->notify($subscriber);
        }
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw ($this->reason ??= new CancelledException('Operation cancelled.'));
        }
    }

    /**
     * @param callable(CancelledException): void $subscriber
     * @return Closure(): void Unsubscribe.
     */
    public function subscribe(callable $subscriber): Closure
    {
        $subscriber = Closure::fromCallable($subscriber);
        if ($this->cancelled) {
            $this->notify($subscriber);
            return static function (): void {};
        }
        $id = $this->nextId++;
        $this->subscribers[$id] = $subscriber;
        return function () use ($id): void { unset($this->subscribers[$id]); };
    }

    private function notify(Closure $subscriber): void
    {
        try {
            $subscriber($this->reason ??= new CancelledException('Operation cancelled.'));
        } catch (Throwable $error) {
            error_log('[Crab] Cancellation callback failed: '.$error->getMessage());
        }
    }
}
