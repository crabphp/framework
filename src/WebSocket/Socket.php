<?php

declare(strict_types=1);

namespace Crab\WebSocket;

use Crab\Contracts\Http\Request;
use Crab\Contracts\WebSocket\Binary;
use Crab\Contracts\WebSocket\Channels;
use Crab\Contracts\WebSocket\Recipient;
use Crab\Contracts\WebSocket\Socket as SocketContract;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\WriteResult;
use Crab\Engine\Suspension;
use Crab\Engine\WebSocket\MessageType;
use Crab\Engine\WebSocket\WebSocketSession;
use Psr\Log\LoggerInterface;

/**
 * A connection-local inbox. Protocol callbacks enqueue and wake without
 * suspending; the action consumes messages in its own connection-owned fiber.
 */
final class Socket implements SocketContract, Recipient
{
    /**
     * The spike delivered 200/200 ordered messages at a peak of 16. Its
     * 64 KiB messages would make that 1 MiB, hence the additional byte cap.
     */
    private const INBOX_MESSAGES = 16;
    private const INBOX_BYTES = 262144;

    /**
     * Raw messages are decoded only when the action asks for one.
     *
     * @var \SplQueue<array{string, MessageType}>
     */
    private \SplQueue $inbox;
    private int $bytes = 0;
    private ?Suspension $reader = null;
    private bool $receiving = false;
    private bool $closed = false;
    private bool $paused = false;

    private ?WebSocketSession $session = null;
    private ?Suspension $accepting = null;
    private ?\Closure $offer;
    private readonly Cancellation $cancellation;
    private ?CancelledException $endReason = null;
    private ?string $pauseTimer = null;
    private ?Channels $channels = null;

    public function __construct(
        private readonly EventLoop $loop,
        public readonly Request $request,
        \Closure $offer,
        private readonly Settings $settings = new Settings(),
        private readonly string $route = 'WS',
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\Closure $memberships = null,
    ) {
        $this->inbox = new \SplQueue();
        $this->offer = $offer;
        $this->cancellation = new Cancellation();
    }

    public function accept(): void
    {
        $this->requireOpen();
        if ($this->session !== null) {
            return;
        }
        if ($this->accepting !== null) {
            throw new \LogicException('This socket is already waiting for acceptance. Use it from the action until acceptance finishes.');
        }
        $this->accepting = $this->loop->getSuspension();
        ($this->offer)();
        $this->offer = null;
        try {
            $this->accepting->suspend();
            $this->requireOpen();
        } finally {
            $this->accepting = null;
        }
    }

    /**
     * Handover resumes the action only after HTTP has accepted the wire 101.
     *
     * @internal
     */
    public function attach(WebSocketSession $session, Connection $connection): void
    {
        $this->session = $session;
        $connection->onClose($this->closed(...));
        $this->accepting?->resume();
    }

    /**
     * Used by admission to distinguish HTTP completion from failed handover.
     *
     * @internal
     */
    public function isAccepted(): bool
    {
        return $this->session !== null;
    }

    public function cancellation(): Cancellation
    {
        return $this->cancellation;
    }

    /**
     * Only this lifetime's exception is an ordinary cancellation end.
     *
     * @internal
     */
    public function causedCancellation(\Throwable $error): bool
    {
        return $error === $this->endReason;
    }

    private function requireOpen(): void
    {
        if ($this->isClosed()) {
            throw new SocketClosed('The WebSocket is closed; end the action.');
        }
    }

    /**
     * Called by the protocol, never by application code.
     *
     * @internal
     */
    public function deliver(string $message, MessageType $type): void
    {
        if ($this->closed) {
            return;
        }
        $this->inbox->enqueue([$message, $type]);
        $this->bytes += strlen($message);
        if ($this->inbox->count() >= self::INBOX_MESSAGES
            || $this->bytes > self::INBOX_BYTES - $this->settings->maxMessage) {
            $this->pauseRead();
        }
        $reader = $this->reader;
        $this->reader = null;
        $reader?->resume();
    }

    private function pauseRead(): void
    {
        if ($this->paused) {
            return;
        }
        $this->paused = true;
        $this->session->pauseRead();
        if ($this->settings->ping > 0) {
            $allowance = 2 * $this->settings->ping;
            $this->pauseTimer = $this->loop->delay($allowance, fn () => $this->pauseExpired($allowance));
        }
    }

    private function pauseExpired(float $allowance): void
    {
        $this->pauseTimer = null;
        $this->close(1011, 'Inbox not consumed');
        $message = "{$this->route}: the action did not take a message for {$allowance} seconds while its inbox was full.";
        if ($this->logger !== null) {
            $this->logger->warning($message);
        } else {
            FailureReporter::report('WARNING ' . $message);
        }
    }

    public function receive(): array|string|Binary|null
    {
        if ($this->isClosed()) {
            return null;
        }
        $this->accept();
        if ($this->receiving) {
            throw new \LogicException('This socket already has a fiber waiting for a message. Receive from one consumer only.');
        }
        $this->receiving = true;
        try {
            while ($this->inbox->isEmpty() && !$this->isClosed()) {
                $this->reader = $this->loop->getSuspension();
                $this->reader->suspend();
            }
            if ($this->closed || $this->inbox->isEmpty()) {
                return null;
            }
            [$message, $type] = $this->inbox->dequeue();
            $this->bytes -= strlen($message);
            if ($this->paused && $this->inbox->count() <= intdiv(self::INBOX_MESSAGES, 2)
                && $this->bytes <= intdiv(self::INBOX_BYTES, 2)) {
                $this->paused = false;
                $this->cancelPauseTimer();
                $this->session->resumeRead();
            }
            return self::decode($message, $type);
        } finally {
            $this->receiving = false;
            $this->reader = null;
        }
    }

    public function send(array|string|Binary $message): void
    {
        $this->requireOpen();
        $this->accept();
        if ($message instanceof Binary) {
            $this->write($message->bytes, true);
            return;
        }
        $this->write(is_array($message) ? json_encode($message, JSON_THROW_ON_ERROR) : $message, false);
    }

    public function sendBinary(string $bytes): void
    {
        $this->requireOpen();
        $this->accept();
        $this->write($bytes, true);
    }

    public function join(string $channel): void
    {
        $this->requireOpen();
        $channels = $this->memberships();
        $this->accept();
        $channels->join($this, $channel);
    }

    public function leave(string $channel): void
    {
        $this->memberships()->leave($this, $channel);
    }

    private function memberships(): Channels
    {
        if ($this->channels === null) {
            if ($this->memberships === null) {
                throw new \LogicException('To join WebSocket channels, register BroadcastProvider or give Connections a channel membership resolver.');
            }
            $this->channels = ($this->memberships)();
        }
        return $this->channels;
    }

    /**
     * Already encoded text, offered once without waiting for output room.
     * Both transport and session queues count against the broadcast bound.
     *
     * @internal
     */
    public function broadcast(string $message): WriteResult
    {
        if ($this->isClosed() || $this->session === null) {
            return WriteResult::Closed;
        }
        if (strlen($message) > $this->settings->maxMessage) {
            throw new \LengthException('The broadcast exceeds websocket.max_message; send a smaller update.');
        }
        $frameBytes = 10 * max(1, (int) ceil(strlen($message) / $this->settings->engine->outgoingFrameBytes));
        if ($this->session->bufferedBytes() + strlen($message) + $frameBytes > $this->settings->engine->maxQueuedOutputBytes) {
            return WriteResult::Backpressured;
        }
        return $this->session->sendText($message);
    }

    public function broadcastTimeout(): float
    {
        return $this->settings->sendTimeout;
    }

    public function awaitBroadcastWritable(Cancellation $cancellation): bool
    {
        return !$this->isClosed() && $this->session->awaitWritable($cancellation, belowBytes: 1);
    }

    /**
     * One deadline covers every retry of this message; writable wakeups
     * grant a retry, never a reservation in the engine's output queue.
     */
    private function write(string $message, bool $binary): void
    {
        $cancellation = null;
        $timer = null;
        try {
            while (true) {
                $result = $binary ? $this->session->sendBinary($message) : $this->session->sendText($message);
                if ($result === WriteResult::Accepted) {
                    return;
                }
                if ($result === WriteResult::Closed) {
                    throw new SocketClosed('The WebSocket is closed; end the send loop.');
                }
                if ($cancellation === null) {
                    $cancellation = new Cancellation();
                    $timer = $this->loop->delay($this->settings->sendTimeout, static fn () => $cancellation->cancel());
                }
                // Empty output always has room for a maximum-sized message.
                if (!$this->session->awaitWritable($cancellation, belowBytes: 1)) {
                    throw new SocketClosed('The WebSocket is closing; end the send loop.');
                }
            }
        } catch (CancelledException $error) {
            $this->close(1008, 'Read messages faster');
            throw new SocketClosed('The client did not read within websocket.send_timeout; the socket was closed.', previous: $error);
        } finally {
            if ($timer !== null) {
                $this->loop->cancel($timer);
            }
        }
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->session === null) {
            $this->accept();
        }
        $this->session->close($code, $reason);
        $this->closed();
    }

    public function isClosed(): bool
    {
        return $this->closed || ($this->session?->isClosing() ?? false) || ($this->session?->isClosed() ?? false);
    }

    /**
     * Wakes a parked consumer after physical close, including peer loss.
     *
     * @internal
     */
    public function closed(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->channels?->forget($this);
        $this->cancelPauseTimer();
        $this->endReason = new CancelledException('The WebSocket lifetime ended.');
        $this->cancellation->cancel($this->endReason);
        if ($this->session === null) {
            $this->accepting?->throw(new SocketClosed('The WebSocket upgrade was refused or its connection ended.'));
        }
        $this->inbox = new \SplQueue();
        $this->bytes = 0;
        $reader = $this->reader;
        $this->reader = null;
        $reader?->resume();
    }

    private function cancelPauseTimer(): void
    {
        if ($this->pauseTimer !== null) {
            $this->loop->cancel($this->pauseTimer);
            $this->pauseTimer = null;
        }
    }

    public function getIterator(): \Traversable
    {
        while (($message = $this->receive()) !== null) {
            yield $message;
        }
    }

    /**
     * JSON scalars and invalid JSON remain text. Binary never gets decoded.
     */
    private static function decode(string $message, MessageType $type): array|string|Binary
    {
        if ($type === MessageType::Binary) {
            return new Binary($message);
        }
        if ($type === MessageType::Text) {
            $decoded = json_decode($message, true);
            if (is_array($decoded) && json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return $message;
    }
}
