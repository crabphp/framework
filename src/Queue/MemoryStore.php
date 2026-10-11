<?php

declare(strict_types=1);

namespace Crab\Queue;

use Crab\Contracts\Queue\Message;
use Crab\Contracts\Queue\Store;

/**
 * Jobs kept in this object's memory, for tests and for trying things out:
 * nothing outlives the process and no other process sees them. A test
 * pushes through the application and then reads waiting().
 */
final class MemoryStore implements Store
{
    /**
     * @var array<int, array{queue: string, job: string, data: array<string, mixed>, attempts: int, available: int, reserved: ?int, error: ?string, failed: ?int}>
     */
    private array $jobs = [];
    private int $lastId = 0;

    public function push(string $queue, string $job, array $data, int $availableAt, int $now): int|string
    {
        $this->jobs[++$this->lastId] = [
            'queue' => $queue, 'job' => $job, 'data' => $data, 'attempts' => 0,
            'available' => $availableAt, 'reserved' => null, 'error' => null, 'failed' => null,
        ];
        return $this->lastId;
    }

    public function reserve(array $queues, int $now, int $retryAfter): ?Message
    {
        foreach ($queues as $queue) {
            foreach ($this->jobs as $id => $job) {
                $held = $job['reserved'] !== null && $job['reserved'] > $now - $retryAfter;
                if ($job['queue'] !== $queue || $job['failed'] !== null || $job['available'] > $now || $held) {
                    continue;
                }
                $this->jobs[$id]['reserved'] = $now;
                ++$this->jobs[$id]['attempts'];
                return $this->message($id);
            }
        }
        return null;
    }

    public function complete(Message $message): void
    {
        unset($this->jobs[$message->id]);
    }

    public function release(Message $message, int $availableAt): void
    {
        if (isset($this->jobs[$message->id])) {
            $this->jobs[$message->id]['reserved'] = null;
            $this->jobs[$message->id]['available'] = $availableAt;
        }
    }

    public function fail(Message $message, string $error, int $now): void
    {
        if (isset($this->jobs[$message->id])) {
            $this->jobs[$message->id]['reserved'] = null;
            $this->jobs[$message->id]['error'] = $error;
            $this->jobs[$message->id]['failed'] = $now;
        }
    }

    public function failed(?int $latest = null): array
    {
        $failed = [];
        foreach ($this->jobs as $id => $job) {
            if ($job['failed'] !== null) {
                $failed[] = $this->message($id);
            }
        }
        return $latest === null ? $failed : array_slice($failed, -max(0, $latest), max(0, $latest));
    }

    public function retry(int|string|null $id, int $now): int
    {
        $count = 0;
        foreach ($this->jobs as $key => $job) {
            if ($job['failed'] !== null && ($id === null || (string) $id === (string) $key)) {
                $this->jobs[$key] = ['attempts' => 0, 'available' => $now, 'reserved' => null, 'error' => null, 'failed' => null] + $job;
                ++$count;
            }
        }
        return $count;
    }

    public function forget(int|string|null $id): int
    {
        $count = 0;
        foreach ($this->jobs as $key => $job) {
            if ($job['failed'] !== null && ($id === null || (string) $id === (string) $key)) {
                unset($this->jobs[$key]);
                ++$count;
            }
        }
        return $count;
    }

    public function size(string $queue): int
    {
        return count($this->waiting($queue));
    }

    public function counts(string $queue, int $now): array
    {
        $counts = ['pending' => 0, 'delayed' => 0, 'processing' => 0, 'oldest' => null];
        foreach ($this->jobs as $job) {
            if ($job['queue'] !== $queue || $job['failed'] !== null) {
                continue;
            }
            $state = $job['reserved'] !== null ? 'processing' : ($job['available'] > $now ? 'delayed' : 'pending');
            ++$counts[$state];
            if ($state === 'pending') {
                $counts['oldest'] = min($counts['oldest'] ?? $job['available'], $job['available']);
            }
        }
        return $counts;
    }

    public function queues(): array
    {
        return array_values(array_unique(array_column($this->jobs, 'queue')));
    }

    public function jobs(string $queue, string $state, int $now, int $limit = 50): array
    {
        $found = [];
        foreach ($this->jobs as $id => $job) {
            if ($job['queue'] !== $queue || $job['failed'] !== null) {
                continue;
            }
            if (($job['reserved'] !== null ? 'processing' : ($job['available'] > $now ? 'delayed' : 'pending')) === $state) {
                $found[] = $this->message($id);
            }
        }
        // By the time that matters in each state: since when it waits, when it comes due, when it was taken.
        usort($found, static fn (Message $a, Message $b): int => $state === 'processing' ? $a->reservedAt <=> $b->reservedAt : $a->availableAt <=> $b->availableAt);
        return array_slice($found, 0, max(0, $limit));
    }

    /**
     * The jobs that have not been done or given up on, in the order they
     * were pushed; those of one queue when it is named.
     *
     * @return list<Message>
     */
    public function waiting(?string $queue = null): array
    {
        $waiting = [];
        foreach ($this->jobs as $id => $job) {
            if ($job['failed'] === null && ($queue === null || $job['queue'] === $queue)) {
                $waiting[] = $this->message($id);
            }
        }
        return $waiting;
    }

    private function message(int $id): Message
    {
        $job = $this->jobs[$id];
        return new Message($id, $job['queue'], $job['job'], $job['data'], $job['attempts'], $job['error'], $job['failed'], $job['available'], $job['reserved']);
    }
}
