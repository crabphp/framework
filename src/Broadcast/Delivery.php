<?php

declare(strict_types=1);

namespace Crab\Broadcast;

use Crab\Contracts\WebSocket\Recipient;

/**
 * One immutable recipient snapshot, advanced across loop turns. PHP's
 * copy-on-write array keeps taking the snapshot constant time.
 *
 * @internal
 */
final class Delivery
{
    private \Generator $recipients;

    /**
     * @param array<int, Recipient> $sockets
     */
    public function __construct(
        array $sockets,
        public readonly string $message,
        public readonly string $channelKey,
    ) {
        $this->recipients = $this->iterate($sockets);
    }

    /**
     * @param array<int, Recipient> $sockets
     * @return \Generator<int, Recipient>
     */
    private function iterate(array $sockets): \Generator
    {
        yield from $sockets;
    }

    public function take(): ?Recipient
    {
        if (!$this->recipients->valid()) {
            return null;
        }
        $socket = $this->recipients->current();
        $this->recipients->next();
        return $socket;
    }
}
