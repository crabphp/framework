<?php

declare(strict_types=1);

namespace Crab\Actors;

use Crab\Contracts\Actors\Inbox;
use Crab\Contracts\Actors\Message;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\EventLoop;
use Crab\Engine\Suspension;

/**
 * The inbox of one actor while it runs. It ends once it has been empty
 * for the idle time, or when told to, and then takes nothing more: what
 * comes after goes to the actor's next run.
 *
 * @internal
 */
final class Mailbox implements Inbox
{
    /**
     * @var \SplQueue<array{mixed, (\Closure(bool, mixed): void)|null}>
     */
    private \SplQueue $queue;

    /**
     * Messages taken and asked, not yet replied to, by object id.
     *
     * @var array<int, Message>
     */
    private array $open = [];

    private ?Suspension $reader = null;
    private bool $ended = false;
    private int $taken = 0;
    private Cancellation $cancellation;

    /**
     * @param \Closure(): void $onEnd Called once, when it ends.
     */
    public function __construct(
        private readonly string $key,
        private readonly EventLoop $loop,
        private readonly float $idle,
        private readonly \Closure $onEnd,
    ) {
        $this->queue = new \SplQueue();
        $this->cancellation = new Cancellation();
    }

    public function key(): string
    {
        return $this->key;
    }

    public function cancellation(): Cancellation
    {
        return $this->cancellation;
    }

    public function getIterator(): \Generator
    {
        while (($message = $this->receive()) !== null) {
            yield $message;
        }
    }

    public function receive(): ?Message
    {
        if ($this->reader !== null) {
            throw new \LogicException(
                'This inbox already has a fiber waiting for a message. Receive from one place only.',
            );
        }
        while ($this->queue->isEmpty() && !$this->ended) {
            $this->reader = $this->loop->getSuspension();
            $timer = $this->loop->delay($this->idle, fn() => $this->end());
            try {
                $this->reader->suspend();
            } finally {
                $this->reader = null;
                $this->loop->cancel($timer);
            }
        }
        if ($this->ended) {
            return null;
        }
        [$data, $answer] = $this->queue->dequeue();
        $this->taken++;
        if ($answer === null) {
            return new Message($data);
        }
        $open = &$this->open;
        $message = new Message($data, static function (bool $replied, mixed $value) use (
            &$open,
            &$message,
            $answer,
        ): void {
            unset($open[spl_object_id($message)]);
            $answer($replied, $value);
        });
        $this->open[spl_object_id($message)] = $message;
        return $message;
    }

    /**
     * @param (\Closure(bool, mixed): void)|null $answer
     */
    public function put(mixed $data, ?\Closure $answer): void
    {
        $this->queue->enqueue([$data, $answer]);
        $this->wake();
    }

    public function size(): int
    {
        return $this->queue->count();
    }

    /**
     * How many messages the actor took.
     */
    public function taken(): int
    {
        return $this->taken;
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    /**
     * Takes no more, and wakes the actor if it waits. With a reason, the
     * process is stopping, and what the actor waits on is cancelled.
     */
    public function end(?string $stopping = null): void
    {
        if ($stopping !== null) {
            $this->cancellation->cancel();
        }
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        ($this->onEnd)();
        $this->wake();
    }

    private function wake(): void
    {
        $reader = $this->reader;
        $this->reader = null;
        $reader?->resume();
    }

    /**
     * After the actor has returned: what it never took, to be given to its next run.
     *
     * @return list<array{mixed, (\Closure(bool, mixed): void)|null}>
     */
    public function left(): array
    {
        $left = [];
        while (!$this->queue->isEmpty()) {
            $left[] = $this->queue->dequeue();
        }
        return $left;
    }

    /**
     * After the actor has returned: tells whoever still waits for a reply that none is coming.
     */
    public function refuseOpen(string $why): void
    {
        foreach ($this->open as $message) {
            $message->refuse($why);
        }
        $this->open = [];
    }
}
