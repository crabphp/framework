<?php

declare(strict_types=1);

namespace Crab\Broadcast;

use Crab\Engine\EventLoop;
use Crab\Engine\Suspension;

/**
 * Other connections joining while the first Redis confirmation waits
 * share that outcome instead of treating an unconfirmed join as ready.
 *
 * @internal
 */
final class Joining
{
    /**
     * @var list<Suspension>
     */
    private array $waiters = [];

    public function await(): void
    {
        $waiter = EventLoop::current()->getSuspension();
        $this->waiters[] = $waiter;
        $waiter->suspend();
    }

    public function finish(?\Throwable $error): void
    {
        $waiters = $this->waiters;
        $this->waiters = [];
        foreach ($waiters as $waiter) {
            if ($error === null) {
                $waiter->resume();
            } else {
                $waiter->throw($error);
            }
        }
    }
}
