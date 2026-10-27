<?php

declare(strict_types=1);

namespace Crab\Contracts\Queue;

/**
 * A job as a store holds it: which class to run, with what data, and how
 * it has fared so far.
 */
final readonly class Message
{
    /**
     * @param int|string $id The id the store gave it.
     * @param string $queue The name of the queue it waits in.
     * @param string $job The job's class.
     * @param array<string, mixed> $data The arguments of its handle(), by name.
     * @param int $attempts How many times it has been started, this time included once a worker has taken it.
     * @param string|null $error Why it failed for good, when it has.
     * @param int|null $failedAt When it failed for good, as a Unix timestamp.
     * @param int|null $availableAt Since when, or from when, it may run; where the store says.
     * @param int|null $reservedAt When a worker took it or last renewed it, while one has it; where the store says.
     */
    public function __construct(
        public int|string $id,
        public string $queue,
        public string $job,
        public array $data,
        public int $attempts = 0,
        public ?string $error = null,
        public ?int $failedAt = null,
        public ?int $availableAt = null,
        public ?int $reservedAt = null,
    ) {}
}
