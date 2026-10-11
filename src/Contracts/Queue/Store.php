<?php

declare(strict_types=1);

namespace Crab\Contracts\Queue;

/**
 * Where jobs wait. A store keeps each job until a worker says it is done,
 * and gives a job to one worker at a time. Times are Unix timestamps.
 */
interface Store
{
    /**
     * @param array<string, mixed> $data
     * @param int $availableAt The earliest time a worker may take it.
     * @return int|string The id it was stored under.
     */
    public function push(string $queue, string $job, array $data, int $availableAt, int $now): int|string;

    /**
     * Takes the next job that may run from the first of the queues that
     * has one, and counts the attempt. No other worker is given the same
     * job until it is released, or until it has been held longer than
     * $retryAfter seconds, which is taken to mean its worker died.
     *
     * @param list<string> $queues Queue names, the most urgent first.
     */
    public function reserve(array $queues, int $now, int $retryAfter): ?Message;

    /**
     * The job is done and is forgotten.
     */
    public function complete(Message $message): void;

    /**
     * The job is to be tried again, no sooner than $availableAt.
     */
    public function release(Message $message, int $availableAt): void;

    /**
     * The job is given up on. It is kept, with the reason, until it is
     * retried or forgotten.
     */
    public function fail(Message $message, string $error, int $now): void;

    /**
     * The jobs that were given up on, oldest first; only the latest so
     * many when a number is given, for a list that may have grown long.
     *
     * @return list<Message>
     */
    public function failed(?int $latest = null): array;

    /**
     * Makes a failed job wait to be run again, as if new; every failed job
     * when no id is given.
     *
     * @return int How many were put back.
     */
    public function retry(int|string|null $id, int $now): int;

    /**
     * Deletes a failed job; every failed job when no id is given.
     *
     * @return int How many were deleted.
     */
    public function forget(int|string|null $id): int;

    /**
     * @return int How many jobs wait in a queue or are being run, the failed ones not counted.
     */
    public function size(string $queue): int;

    /**
     * A queue's jobs by where they stand now: "pending" may be taken by a
     * worker, "delayed" wait for their time, and "processing" have been
     * taken and not yet reported on. Together they are size(). "oldest"
     * is since when the pending job that has waited longest could have
     * run, null when none is pending: how far behind the workers are.
     *
     * @return array{pending: int, delayed: int, processing: int, oldest: int|null}
     */
    public function counts(string $queue, int $now): array;

    /**
     * @return list<string> The names of the queues that hold a job, waiting, being run or failed, in no particular order.
     */
    public function queues(): array;

    /**
     * The jobs of a queue that stand in one state now, to be looked at:
     * "pending" the one that has waited longest first, "delayed" the next
     * to come due first, "processing" the one taken longest ago first.
     * Looking takes nothing: no job is reserved by it.
     *
     * @param 'pending'|'delayed'|'processing' $state
     * @return list<Message>
     */
    public function jobs(string $queue, string $state, int $now, int $limit = 50): array;
}
