<?php

declare(strict_types=1);

namespace Crab\Contracts\WebSocket;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Network\WriteResult;

/**
 * A worker-local recipient. Broadcast writes never wait for a reader;
 * a saturated recipient can be closed without delaying its neighbours.
 */
interface Recipient
{
    public function broadcast(string $message): WriteResult;

    /**
     * Seconds allowed to catch up after the first backpressured broadcast.
     */
    public function broadcastTimeout(): float;

    /**
     * Wait for empty output, or return false when closing. Cancellation
     * removes this wait without discarding previously accepted messages.
     */
    public function awaitBroadcastWritable(Cancellation $cancellation): bool;

    public function close(int $code = 1000, string $reason = ''): void;
}
