<?php

declare(strict_types=1);

namespace Crab\Contracts\WebSocket;

/**
 * Bytes whose WebSocket frame type must survive a receive and send.
 * The value lives beside its contract so no contract depends on a component.
 */
final readonly class Binary implements \Stringable
{
    public function __construct(public string $bytes)
    {
    }

    public function __toString(): string
    {
        return $this->bytes;
    }
}
