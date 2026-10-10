<?php

declare(strict_types=1);

namespace Crab\Contracts\Queue;

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
}
