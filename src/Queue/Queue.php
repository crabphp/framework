<?php

declare(strict_types=1);

namespace Crab\Queue;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Queue\Queue as QueueContract;
use Crab\Contracts\Queue\Store;
use Crab\Contracts\Queue\TransactionalStore;
use Psr\Clock\ClockInterface;

/**
 * Puts jobs into a store. What is pushed is checked against the job's
 * handle() there and then, so a wrong name or a missing argument is
 * reported by the line that pushed it, not minutes later by a worker.
 */
final class Queue implements QueueContract
{
    /**
     * @param string $queue The name jobs are pushed under.
     * @param ClockInterface|null $clock The real time when left out.
     * @param Connection|null $transaction The transaction a job is pushed as part of; see within().
     */
    public function __construct(
        private readonly Store $store,
        private readonly string $queue = 'default',
        private readonly ?ClockInterface $clock = null,
        private readonly ?Connection $transaction = null,
    ) {
        if ($queue === '') {
            throw new \InvalidArgumentException('A queue needs a name.');
        }
    }

    public function push(string $job, mixed ...$data): int|string
    {
        return $this->add(0, $job, $data);
    }

    public function later(int|\DateInterval $delay, string $job, mixed ...$data): int|string
    {
        return $this->add($delay, $job, $data);
    }

    public function on(string $queue): QueueContract
    {
        return new self($this->store, $queue, $this->clock, $this->transaction);
    }

    public function within(Connection $transaction): QueueContract
    {
        return new self($this->store, $this->queue, $this->clock, $transaction);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function add(int|\DateInterval $delay, string $job, array $data): int|string
    {
        self::check($job, $data);
        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        $available = $delay instanceof \DateInterval ? $now->add($delay) : $now->modify("+{$delay} seconds");
        $push = fn(Store $store): int|string => $store->push(
            $this->queue,
            $job,
            $data,
            max($available->getTimestamp(), $now->getTimestamp()),
            $now->getTimestamp(),
        );
        if ($this->transaction === null) {
            return $push($this->store);
        }
        $joined = $this->store instanceof TransactionalStore ? $this->store->through($this->transaction) : null;
        if ($joined !== null) {
            return $push($joined);
        }
        // Kept elsewhere than the transaction writes to: it can only follow the commit.
        $this->transaction->afterCommit(fn() => $push($this->store));
        return '';
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private static function check(string $job, array $data): void
    {
        $signature = Signature::job($job);
        // Read once the class is known to be a job, so that a mistake in either is refused where it is pushed.
        Retry::for($job);
        Timeout::for($job);
        $signature->check($data);
    }
}
