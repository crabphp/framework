<?php

declare(strict_types=1);

namespace Crab\Contracts\Actors;

use Crab\Engine\Async\Cancellation;

/**
 * An actor's messages, one at a time, in the order they were sent.
 * Iterating waits for each; it ends once none has come for "actors.idle"
 * seconds, or when the process stops.
 *
 * @extends \IteratorAggregate<int, Message>
 */
interface Inbox extends \IteratorAggregate
{
    public function key(): string;

    /**
     * The next message, waiting for it; null once the inbox has ended.
     */
    public function receive(): ?Message;

    /**
     * Cancelled when the process stops: pass it to what the actor waits on.
     */
    public function cancellation(): Cancellation;
}
