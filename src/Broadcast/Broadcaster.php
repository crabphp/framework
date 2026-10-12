<?php

declare(strict_types=1);

namespace Crab\Broadcast;

use Crab\Contracts\Broadcast\Broadcaster as BroadcasterContract;
use Crab\Contracts\Broadcast\Carrier;
use Crab\Contracts\Broadcast\Channel;
use Crab\Contracts\Database\Connection;
use Crab\Engine\FailureReporter;

/**
 * Encodes once before handing text to the carrier. Connection::afterCommit
 * owns nested rollback and retry semantics, as it does for the queue.
 */
final readonly class Broadcaster implements BroadcasterContract
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        private Carrier $carrier,
        private array $channels = [],
        private ?Connection $transaction = null,
    ) {
    }

    public function to(string ...$channels): BroadcasterContract
    {
        if ($channels === []) {
            throw new \InvalidArgumentException('Choose at least one broadcast channel with to("orders.42").');
        }
        foreach ($channels as $channel) {
            new Channel($channel);
        }
        return new self($this->carrier, array_values(array_unique($channels)), $this->transaction);
    }

    public function within(Connection $transaction): BroadcasterContract
    {
        return new self($this->carrier, $this->channels, $transaction);
    }

    public function send(array|string $message): void
    {
        if ($this->channels === []) {
            throw new \LogicException('Choose broadcast channels with to("orders.42") before send().');
        }
        $encoded = is_array($message) ? json_encode($message, JSON_THROW_ON_ERROR) : $message;
        if (!mb_check_encoding($encoded, 'UTF-8')) {
            throw new \InvalidArgumentException('Broadcast text must be UTF-8; binary broadcasting is not supported.');
        }
        if ($this->transaction === null) {
            $this->carrier->publish($this->channels, $encoded);
        } else {
            $this->transaction->afterCommit(function () use ($encoded): void {
                try {
                    $this->carrier->publish($this->channels, $encoded);
                } catch (\Throwable $error) {
                    FailureReporter::report('Broadcast failed after commit; the write is committed and clients must refetch state', $error);
                }
            });
        }
    }
}
