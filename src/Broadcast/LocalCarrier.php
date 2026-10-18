<?php

declare(strict_types=1);

namespace Crab\Broadcast;

use Crab\Contracts\Broadcast\Carrier;

/**
 * Carries messages inside one process. Application wiring supplies its
 * serving context so a console sender cannot silently reach nobody.
 */
final class LocalCarrier implements Carrier
{
    /**
     * @var array<string, \Closure(string, string): void>
     */
    private array $receivers = [];

    public function __construct(
        private readonly ?\Closure $canSend = null,
    ) {}

    public function publish(array $channels, string $message): void
    {
        if ($this->canSend !== null && !($this->canSend)()) {
            throw new \LogicException(
                'The local broadcast carrier cannot reach the server from a console, queue:work or schedule:work process. Set broadcast.carrier to redis and register RedisProvider.',
            );
        }
        foreach ($channels as $channel) {
            if (!isset($this->receivers[':' . $channel])) {
                continue;
            }

            $this->receivers[':' . $channel]($channel, $message);
        }
    }

    public function subscribe(string $channel, \Closure $receive, \Closure $gap): void
    {
        $this->receivers[':' . $channel] = $receive;
    }

    public function unsubscribe(string $channel): void
    {
        unset($this->receivers[':' . $channel]);
    }

    public function close(): void
    {
        $this->receivers = [];
    }
}
