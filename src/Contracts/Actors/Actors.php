<?php

declare(strict_types=1);

namespace Crab\Contracts\Actors;

use Crab\Engine\Async\Cancellation;

/**
 * Sends messages to actors. An actor is a class with
 * handle(Inbox $inbox, string $key), of which one runs for each key at a
 * time, taking its messages one by one, in the order they were sent:
 *
 *     $actors->tell(Assistant::class, "user:{$id}", ['text' => 'hi']);
 *     $answer = $actors->ask(Assistant::class, "user:{$id}", ['text' => 'hi']);
 *
 * It is started by its first message and stops once its inbox has been
 * empty for "actors.idle" seconds. Messages travel as JSON, and each is
 * delivered at most once.
 */
interface Actors
{
    /**
     * Sends a message and returns at once.
     *
     * @param class-string $actor
     */
    public function tell(string $actor, string $key, mixed $message): void;

    /**
     * Sends a message and waits for the actor's reply to it.
     *
     * @param class-string $actor
     * @throws NoReply When none came within $timeout seconds, or the actor ended or failed before it replied.
     * @throws \Crab\Engine\Async\CancelledException When $cancellation was cancelled first.
     */
    public function ask(
        string $actor,
        string $key,
        mixed $message,
        float $timeout = 30.0,
        ?Cancellation $cancellation = null,
    ): mixed;
}
