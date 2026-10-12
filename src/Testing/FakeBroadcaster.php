<?php

declare(strict_types=1);

namespace Crab\Testing;

use Crab\Contracts\Broadcast\Broadcaster;
use Crab\Contracts\Broadcast\Channel;
use Crab\Contracts\Database\Connection;

/**
 * Records broadcasts without a socket or carrier. Immutable selections
 * share their record so an assertion sees sends from every selection.
 */
final readonly class FakeBroadcaster implements Broadcaster
{
    private \ArrayObject $sent;

    /**
     * @param list<string> $channels
     */
    public function __construct(
        ?\ArrayObject $sent = null,
        private array $channels = [],
        private ?Connection $transaction = null,
    ) {
        $this->sent = $sent ?? new \ArrayObject();
    }

    public function to(string ...$channels): Broadcaster
    {
        if ($channels === []) {
            throw new \InvalidArgumentException('Choose at least one broadcast channel with to("orders.42").');
        }
        foreach ($channels as $channel) {
            new Channel($channel);
        }
        return new self($this->sent, array_values(array_unique($channels)), $this->transaction);
    }

    public function within(Connection $transaction): Broadcaster
    {
        return new self($this->sent, $this->channels, $transaction);
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
        $record = function () use ($message): void {
            foreach ($this->channels as $channel) {
                $this->sent[] = [$channel, $message];
            }
        };
        if ($this->transaction === null) {
            $record();
        } else {
            $this->transaction->afterCommit($record);
        }
    }

    public function assertSent(string $channel, array|string $message): void
    {
        $found = false;
        foreach ($this->sent as $sent) {
            if ($sent === [$channel, $message]) {
                $found = true;
                break;
            }
        }
        $this->assert($found, "No matching broadcast was sent to {$channel}.");
    }

    public function assertNothingSent(): void
    {
        $this->assert($this->sent->count() === 0, 'A broadcast was sent when none was expected.');
    }

    private function assert(bool $condition, string $message): void
    {
        if (class_exists(\PHPUnit\Framework\Assert::class)) {
            \PHPUnit\Framework\Assert::assertTrue($condition, $message);
        } elseif (!$condition) {
            throw new \LogicException($message);
        }
    }
}
