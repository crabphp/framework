<?php

declare(strict_types=1);

namespace Crab\Contracts\Broadcast;

/**
 * Carries already encoded text to workers. Subscribing confirms readiness;
 * leaving and stopping never suspend protocol callbacks or worker shutdown.
 */
interface Carrier
{
    /**
     * @param list<string> $channels Unique, validated channel names.
     */
    public function publish(array $channels, string $message): void;

    /**
     * @param \Closure(string, string): void $receive Channel and encoded text, in sender order.
     * @param \Closure(): void $gap Signals lost updates; the subscription reports the outage once.
     */
    public function subscribe(string $channel, \Closure $receive, \Closure $gap): void;

    public function unsubscribe(string $channel): void;

    public function close(): void;
}
