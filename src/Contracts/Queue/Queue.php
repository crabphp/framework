<?php

declare(strict_types=1);

namespace Crab\Contracts\Queue;

use Crab\Contracts\Database\Connection;

/**
 * Hands work to be done later, outside the request that asked for it. A
 * job is a class with a handle() method: its constructor takes the
 * services it needs, and handle() takes the job's data as typed
 * parameters, which are given by name when the job is pushed:
 *
 *     $queue->push(SendWelcomeEmail::class, userId: 7);
 *
 * The data travels as JSON, so it is numbers, text, booleans, null and
 * arrays of them; pass an id, not the object it belongs to. A job is run
 * at least once: one that fails is tried again, so it must be safe to run
 * twice.
 */
interface Queue
{
    /**
     * Adds a job to be run as soon as a worker is free.
     *
     * @param class-string $job
     * @param mixed ...$data The parameters of the job's handle(), by name.
     * @return int|string The id the job was stored under.
     */
    public function push(string $job, mixed ...$data): int|string;

    /**
     * Adds a job to be run no sooner than after the delay, in seconds or
     * as a DateInterval: later(600, SendReminder::class, orderId: 12).
     *
     * @param class-string $job
     * @return int|string The id the job was stored under.
     */
    public function later(int|\DateInterval $delay, string $job, mixed ...$data): int|string;

    /**
     * The same queue under another name, for work that should not wait
     * behind the rest: $queue->on('emails')->push(...). A worker is told
     * which names it takes from with `queue:work --queue=emails,default`.
     */
    public function on(string $queue): Queue;

    /**
     * The same queue for a write that is being made in a transaction, so
     * that the job exists exactly when the write does:
     *
     *     $this->db->transaction(function (Connection $db) use ($lines): void {
     *         $id = $db->table('orders')->insert([...]);
     *         $this->queue->within($db)->push(SendOrderConfirmation::class, orderId: $id);
     *     });
     *
     * With jobs kept in the same database, the job is stored by the
     * transaction itself: committed with the order, rolled back with it,
     * and nothing in between can lose it. With jobs kept anywhere else it
     * is pushed once the transaction has committed, and not at all when
     * it is rolled back; push() then returns '' in place of an id, and a
     * process that dies between the commit and the push loses the job.
     *
     * @param Connection $transaction The connection the transaction's callback was given.
     */
    public function within(Connection $transaction): Queue;
}
