<?php

declare(strict_types=1);

namespace Crab\Contracts\WebSocket;

/**
 * Membership supplied by application wiring. WebSocket owns connection
 * lifetime and need not know which component carries channel messages.
 */
interface Channels
{
    public function join(Recipient $socket, string $channel): void;

    public function leave(Recipient $socket, string $channel): void;

    public function forget(Recipient $socket): void;
}
