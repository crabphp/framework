<?php

declare(strict_types=1);

namespace Crab\Broadcast;

use Crab\Contracts\Broadcast\Carrier;

/**
 * Worker context supplied by glue, plus resources actually used. Stopping
 * an unused provider must not resolve a carrier just to close it.
 *
 * @internal
 */
final class Lifecycle
{
    public bool $serving = false;
    public ?Carrier $carrier = null;
    public ?Channels $channels = null;

    public function close(): void
    {
        if ($this->channels !== null) {
            $this->channels->close();
        } else {
            $this->carrier?->close();
        }
        $this->serving = false;
    }
}
