<?php

declare(strict_types=1);

namespace Crab\Engine\WebSocket;

use Closure;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\WriteResult;
use SplQueue;

/**
 * One RFC 6455 server session. Message handlers may suspend; one connection
 * delivers them in order and pauses transport reading until each returns.
 */
final class WebSocketSession
{
    private string $input = '';
    private int $offset = 0;
    private ?MessageType $fragmentType = null;
    private string $fragment = '';
    private int $outgoingFragmentBytes = 0;
    private bool $outgoingFragment = false;
    /**
     * @var SplQueue<string>
     */
    private SplQueue $output;
    /**
     * @var SplQueue<string>
     */
    private SplQueue $control;
    private int $queuedBytes = 0;
    private bool $pumping = false;
    private bool $parsing = false;
    private bool $readPaused = false;
    private bool $pressured = false;
    private bool $closing = false;
    private bool $draining = false;
    private bool $closeSent = false;
    private bool $closeReceived = false;
    private bool $failed = false;
    private int $peerCloseCode = 1006;
    private string $peerCloseReason = '';
    private ?string $parseContinuation = null;
    private ?string $writeContinuation = null;
    private ?string $deadline = null;
    private float $deadlineScheduledAt = 0;
    private float $lastReceivedAt = 0;
    private bool $heartbeatPaused = false;
    private ?float $pingAt = null;
    private ?float $closeAt = null;
    private ?float $drainAt = null;
    private readonly ?float $idleTimeout;
    private ?string $drainNotification = null;
    private readonly Closure $onMessage;
    private readonly ?Closure $onClose;
    /**
     * @var list<Closure>
     */
    private array $drainCallbacks = [];
    /**
     * @var array<int, array{int, Closure}>
     */
    private array $writableWaiters = [];
    private int $nextWaiter = 0;
    private readonly int $outgoingFrameBytes;

    /**
     * @param callable(self, string, MessageType): void $onMessage
     * @param null|callable(self, int, string): void $onClose
     */
    public function __construct(
        private readonly Connection $connection,
        callable $onMessage,
        private readonly Config $config = new Config(),
        ?callable $onClose = null,
        public readonly ?string $subprotocol = null,
    ) {
        $this->onMessage = Closure::fromCallable($onMessage);
        $this->onClose = $onClose === null ? null : Closure::fromCallable($onClose);
        $this->output = new SplQueue();
        $this->control = new SplQueue();
        $this->outgoingFrameBytes = min($config->outgoingFrameBytes, $connection->maxWriteSize() - 10);
        $this->idleTimeout = $config->idleTimeout ?? ($config->pingInterval === null ? null : 2 * $config->pingInterval);
        if ($connection->maxWriteSize() < 135) {
            throw new \InvalidArgumentException('WebSocket requires a transport write limit of at least 135 bytes.');
        }
        $connection->onData(fn (Connection $connection, string $data) => $this->receive($data));
        $connection->onDrain(fn () => $this->flush());
        $connection->onEof(fn () => $connection->close());
        $connection->onClose(fn () => $this->closed());
        $connection->onDrainRequest($this->requestDrain(...), static fn () => false);
        $connection->resumeRead();
        if ($this->idleTimeout !== null) {
            $this->lastReceivedAt = hrtime(true) / 1_000_000_000;
            $this->pingAt = $config->pingInterval === null ? null : $this->lastReceivedAt + $config->pingInterval;
            $this->armDeadline();
        }
    }

    public function isClosed(): bool
    {
        return $this->connection->isClosed();
    }

    public function isClosing(): bool
    {
        return $this->closing || $this->draining;
    }

    public function remoteAddress(): string
    {
        return $this->connection->remoteAddress;
    }

    public function bufferedBytes(): int
    {
        return $this->queuedBytes + $this->connection->bufferedBytes();
    }

    public function onDrain(callable $callback): self
    {
        $this->drainCallbacks[] = Closure::fromCallable($callback);
        return $this;
    }

    /**
     * Wait until queued bytes are strictly below the threshold and a frame slot
     * is free. True permits a retry, without reserving room; false means closing.
     * Cancellation removes this wait only and leaves accepted output intact.
     */
    public function awaitWritable(?Cancellation $cancellation = null, ?int $belowBytes = null): bool
    {
        $belowBytes ??= intdiv($this->config->maxQueuedOutputBytes, 2) + 1;
        if ($belowBytes < 1 || $belowBytes > $this->config->maxQueuedOutputBytes) {
            throw new \InvalidArgumentException('Set the writable threshold between one byte and the WebSocket output limit.');
        }
        $cancellation?->throwIfCancelled();
        if ($this->isClosing() || $this->isClosed()) {
            return false;
        }
        if ($this->isWritableBelow($belowBytes)) {
            return true;
        }
        if (count($this->writableWaiters) >= $this->config->maxWritableWaiters) {
            throw new \OverflowException('Too many WebSocket writers are waiting; reduce producers or increase maxWritableWaiters.');
        }
        $suspension = $this->connection->loop()->getSuspension();
        $id = $this->nextWaiter++;
        $settled = false;
        $unsubscribe = null;
        $finish = function (bool $writable = false, ?\Throwable $error = null) use ($suspension, $id, &$settled, &$unsubscribe): void {
            if ($settled) {
                return;
            }
            $settled = true;
            unset($this->writableWaiters[$id]);
            if ($this->writableWaiters === []) {
                $this->connection->onWriteProgress(null);
            }
            $unsubscribe?->__invoke();
            if ($error !== null) {
                $suspension->throw($error);
            } else {
                $suspension->resume($writable);
            }
        };
        $this->writableWaiters[$id] = [$belowBytes, $finish];
        $this->connection->onWriteProgress($this->notifyWritable(...));
        $unsubscribe = $cancellation?->subscribe(static fn (CancelledException $error) => $finish(false, $error));
        try {
            return $suspension->suspend();
        } finally {
            unset($this->writableWaiters[$id]);
            if ($this->writableWaiters === []) {
                $this->connection->onWriteProgress(null);
            }
            $settled = true;
            $unsubscribe?->__invoke();
        }
    }

    private function isWritableBelow(int $bytes): bool
    {
        return $this->bufferedBytes() < $bytes && $this->output->count() < $this->config->maxQueuedFrames
            && $this->control->count() < 16;
    }

    private function notifyWritable(): void
    {
        foreach ($this->writableWaiters as [$bytes, $finish]) {
            if ($this->isClosing() || $this->isClosed()) {
                $finish(false);
            } elseif ($this->isWritableBelow($bytes)) {
                $finish(true);
            } else {
                // A session flush may have created a new transport output queue.
                $this->connection->onWriteProgress($this->notifyWritable(...));
            }
        }
    }

    public function pauseRead(): void
    {
        $this->readPaused = true;
        $this->pauseTransportRead();
    }

    public function resumeRead(): void
    {
        $this->readPaused = false;
        if ($this->parseContinuation === null && $this->offset < strlen($this->input)) {
            $this->parse();
        }
        if (!$this->parsing && $this->parseContinuation === null && !$this->failed && !$this->closeReceived && !$this->readPaused) {
            $this->resumeTransportRead();
        }
    }

    /**
     * A peer cannot prove life while our own reader is paused. An existing
     * deadline may wake once, but cannot ping or expire until reading resumes.
     */
    private function pauseTransportRead(): void
    {
        $this->connection->pauseRead();
        $this->heartbeatPaused = $this->idleTimeout !== null;
    }

    /**
     * Start a fresh silence interval when input can actually be read again.
     * Retain an earlier timer registration, as for received activity.
     */
    private function resumeTransportRead(): void
    {
        $this->connection->resumeRead();
        if ($this->heartbeatPaused) {
            $this->resumeHeartbeat();
        }
    }

    private function resumeHeartbeat(): void
    {
        $this->heartbeatPaused = false;
        $this->lastReceivedAt = hrtime(true) / 1_000_000_000;
        $this->pingAt = $this->config->pingInterval === null ? null : $this->lastReceivedAt + $this->config->pingInterval;
        $this->armDeadline();
    }

    public function sendText(string $message): WriteResult
    {
        if (!self::validUtf8($message)) {
            throw new \InvalidArgumentException('WebSocket text must be valid UTF-8.');
        }
        return $this->sendMessage($message, MessageType::Text);
    }

    public function sendBinary(string $message): WriteResult
    {
        return $this->sendMessage($message, MessageType::Binary);
    }

    /**
     * Stream a binary message in bounded chunks; retry the same chunk after backpressure.
     */
    public function sendBinaryChunk(string $chunk, bool $final = false): WriteResult
    {
        if ($this->isClosing() || $this->isClosed()) {
            return WriteResult::Closed;
        }
        if (strlen($chunk) > $this->outgoingFrameBytes
            || $this->outgoingFragmentBytes + strlen($chunk) > $this->config->maxMessageBytes) {
            throw new \LengthException('WebSocket chunk or assembled message exceeds its limit.');
        }
        $frame = self::encodeFrame($this->outgoingFragment ? 0 : 2, $chunk, $final);
        $result = $this->enqueueFrame($frame);
        if ($result === WriteResult::Accepted) {
            $this->outgoingFragment = !$final;
            $this->outgoingFragmentBytes = $final ? 0 : $this->outgoingFragmentBytes + strlen($chunk);
        }
        return $result;
    }

    public function ping(string $payload = ''): WriteResult
    {
        if (strlen($payload) > 125) {
            throw new \LengthException('WebSocket control payloads cannot exceed 125 bytes.');
        }
        if ($this->isClosing() || $this->isClosed()) {
            return WriteResult::Closed;
        }
        if ($this->enqueueControl(9, $payload)) {
            return WriteResult::Accepted;
        }
        $this->pressured = true;
        return WriteResult::Backpressured;
    }

    /**
     * Flush previously accepted application frames, send Close, then await the peer until the deadline.
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        if (!self::validCloseCode($code) || $code === 1010 || strlen($reason) > 123 || !self::validUtf8($reason)) {
            throw new \InvalidArgumentException('Invalid WebSocket close code or reason.');
        }
        if ($this->closing || $this->isClosed()) {
            return;
        }
        $this->beginClosing();
        $frame = self::encodeFrame(8, pack('n', $code) . $reason);
        $this->output->enqueue($frame);
        $this->queuedBytes += strlen($frame);
        $this->flush();
        $this->resumeRead();
    }

    /**
     * HTTP upgrade transfers its unread bytes here exactly once.
     */
    public function receive(string $data): void
    {
        if ($this->isClosed() || $this->failed || $data === '') {
            return;
        }
        if ($this->idleTimeout !== null) {
            $this->lastReceivedAt = hrtime(true) / 1_000_000_000;
            $this->pingAt = $this->config->pingInterval === null ? null : $this->lastReceivedAt + $this->config->pingInterval;
        }
        if (strlen($this->input) - $this->offset + strlen($data) > $this->config->maxBufferedInputBytes) {
            $this->fail(1009, 'Input limit');
            return;
        }
        if ($this->offset > 0) {
            $this->input = substr($this->input, $this->offset);
            $this->offset = 0;
        }
        $this->input .= $data;
        if ($this->parseContinuation === null) {
            $this->parse();
        }
    }

    private function parse(): void
    {
        if ($this->parsing || $this->readPaused || !$this->connection->enterActivity()) {
            return;
        }
        $this->parsing = true;
        // Keep the default parser's direct transport calls; heartbeat work is optional.
        $this->connection->pauseRead();
        $this->heartbeatPaused = $this->idleTimeout !== null;
        try {
            $this->parseFrames();
        } finally {
            $this->parsing = false;
            $this->connection->leaveActivity();
            if ($this->parseContinuation === null && !$this->failed && !$this->closeReceived && !$this->readPaused) {
                $this->connection->resumeRead();
                if ($this->heartbeatPaused) {
                    $this->resumeHeartbeat();
                }
            }
        }
    }

    private function parseFrames(): void
    {
        $frames = 0;
        while (!$this->isClosed() && !$this->failed && !$this->closeReceived && !$this->readPaused) {
            $available = strlen($this->input) - $this->offset;
            if ($available < 2) {
                break;
            }
            $first = ord($this->input[$this->offset]);
            $second = ord($this->input[$this->offset + 1]);
            $fin = ($first & 128) !== 0;
            $opcode = $first & 15;
            $length = $second & 127;
            $headerBytes = 2;
            if (($first & 112) !== 0 || ($second & 128) === 0
                || !in_array($opcode, [0, 1, 2, 8, 9, 10], true)
                || ($opcode >= 8 && (!$fin || $length > 125))) {
                $this->fail(1002, 'Invalid frame');
                return;
            }
            if ($length === 126) {
                if ($available < 4) {
                    break;
                }
                $length = unpack('n', $this->input, $this->offset + 2)[1];
                $headerBytes = 4;
                if ($length < 126) {
                    $this->fail(1002, 'Noncanonical length');
                    return;
                }
            } elseif ($length === 127) {
                if ($available < 10) {
                    break;
                }
                $extended = unpack('Nhigh/Nlow', $this->input, $this->offset + 2);
                if (($extended['high'] & 0x80000000) !== 0
                    || ($extended['high'] === 0 && $extended['low'] < 65536)) {
                    $this->fail(1002, 'Invalid length');
                    return;
                }
                if ($extended['high'] > 0) {
                    $this->fail(1009, 'Frame limit');
                    return;
                }
                $length = $extended['low'];
                $headerBytes = 10;
            }
            if ($length > $this->config->maxFrameBytes) {
                $this->fail(1009, 'Frame limit');
                return;
            }
            if ($opcode < 8 && !$this->closing) {
                if (($opcode === 0) !== ($this->fragmentType !== null)) {
                    $this->fail(1002, 'Invalid continuation');
                    return;
                }
                if (strlen($this->fragment) + $length > $this->config->maxMessageBytes) {
                    $this->fail(1009, 'Message limit');
                    return;
                }
            }
            if ($available < $headerBytes + 4 + $length) {
                break;
            }
            $mask = substr($this->input, $this->offset + $headerBytes, 4);
            $payload = substr($this->input, $this->offset + $headerBytes + 4, $length);
            $this->offset += $headerBytes + 4 + $length;
            $payload ^= str_repeat($mask, intdiv($length + 3, 4));
            $this->handleFrame($opcode, $payload, $fin);
            if (++$frames >= $this->config->framesPerTurn) {
                break;
            }
        }

        if ($this->offset > 0) {
            $this->input = substr($this->input, $this->offset);
            $this->offset = 0;
        }
        if (!$this->isClosed() && !$this->failed && !$this->closeReceived && !$this->readPaused
            && $frames >= $this->config->framesPerTurn && $this->input !== '') {
            $this->connection->pauseRead();
            $this->parseContinuation = $this->connection->loop()->defer(function (): void {
                $this->parseContinuation = null;
                $this->parse();
                if ($this->parseContinuation === null && !$this->failed && !$this->closeReceived && !$this->readPaused) {
                    $this->connection->resumeRead();
                }
            });
        }
    }

    private function handleFrame(int $opcode, string $payload, bool $fin): void
    {
        if ($opcode === 8) {
            $this->receiveClose($payload);
            return;
        }
        if ($opcode === 9) {
            if (!$this->closeSent && !$this->enqueueControl(10, $payload)) {
                $this->fail(1008, 'Control queue limit');
            }
            return;
        }
        if ($opcode === 10 || $this->closing) {
            return;
        }
        $type = $opcode === 0 ? $this->fragmentType : MessageType::from($opcode);
        if (!$fin) {
            $this->fragmentType = $type;
            $this->fragment .= $payload;
            return;
        }
        if ($opcode === 0) {
            $payload = $this->fragment . $payload;
        }
        $this->fragment = '';
        $this->fragmentType = null;
        if ($type === MessageType::Text && !self::validUtf8($payload)) {
            $this->fail(1007, 'Invalid UTF-8');
            return;
        }
        try {
            ($this->onMessage)($this, $payload, $type);
        } catch (\Throwable $error) {
            $this->connection->reportFailure('WebSocket message handler failed', $error);
            $this->fail(1011, 'Handler failed');
        }
    }

    private function sendMessage(string $message, MessageType $type): WriteResult
    {
        if ($this->isClosing() || $this->isClosed()) {
            return WriteResult::Closed;
        }
        if ($this->outgoingFragment) {
            throw new \LogicException('Finish the streamed binary message before sending another message.');
        }
        $length = strlen($message);
        if ($length > $this->config->maxMessageBytes) {
            throw new \LengthException('WebSocket message exceeds its limit.');
        }
        if ($length <= $this->outgoingFrameBytes) {
            return $this->enqueueFrame(self::encodeFrame($type->value, $message));
        }
        $frameCount = max(1, intdiv($length + $this->outgoingFrameBytes - 1, $this->outgoingFrameBytes));
        if ($frameCount > $this->config->maxQueuedFrames
            || $length + 2 * $frameCount > $this->config->maxQueuedOutputBytes) {
            throw new \LengthException('Message exceeds output capacity; use bounded binary chunks.');
        }
        $frames = [];
        $offset = 0;
        do {
            $chunk = substr($message, $offset, $this->outgoingFrameBytes);
            $last = $offset + strlen($chunk) >= $length;
            $frames[] = self::encodeFrame($offset === 0 ? $type->value : 0, $chunk, $last);
            $offset += strlen($chunk);
        } while (!$last);
        return $this->enqueue($frames);
    }

    /**
     * @param list<string> $frames
     */
    private function enqueue(array $frames): WriteResult
    {
        $bytes = 0;
        foreach ($frames as $frame) {
            $bytes += strlen($frame);
        }
        if ($bytes > $this->config->maxQueuedOutputBytes || count($frames) > $this->config->maxQueuedFrames) {
            throw new \LengthException('Message exceeds output capacity; use bounded binary chunks.');
        }
        if ($this->bufferedBytes() + $bytes > $this->config->maxQueuedOutputBytes
            || $this->output->count() + count($frames) > $this->config->maxQueuedFrames) {
            $this->pressured = true;
            return WriteResult::Backpressured;
        }
        foreach ($frames as $frame) {
            $this->output->enqueue($frame);
        }
        $this->queuedBytes += $bytes;
        $this->flush();
        return WriteResult::Accepted;
    }

    private function enqueueFrame(string $frame): WriteResult
    {
        $bytes = strlen($frame);
        if ($bytes > $this->config->maxQueuedOutputBytes) {
            throw new \LengthException('Frame exceeds output capacity.');
        }
        if ($this->bufferedBytes() + $bytes > $this->config->maxQueuedOutputBytes
            || $this->output->count() >= $this->config->maxQueuedFrames) {
            $this->pressured = true;
            return WriteResult::Backpressured;
        }

        // A complete frame can go straight to transport; it owns any partial write.
        if (!$this->pumping && $this->output->isEmpty() && $this->control->isEmpty()) {
            $result = $this->connection->tryWrite($frame);
            if ($result !== WriteResult::Backpressured) {
                return $result;
            }
            $this->output->enqueue($frame);
            $this->queuedBytes += $bytes;
            return WriteResult::Accepted;
        }
        $this->output->enqueue($frame);
        $this->queuedBytes += $bytes;
        $this->flush();
        return WriteResult::Accepted;
    }

    private function enqueueControl(int $opcode, string $payload): bool
    {
        if ($this->control->count() >= 16) {
            return false;
        }
        $frame = self::encodeFrame($opcode, $payload);
        $this->control->enqueue($frame);
        $this->queuedBytes += strlen($frame);
        $this->flush();
        return true;
    }

    private function flush(): void
    {
        if ($this->pumping || $this->isClosed()) {
            return;
        }
        $this->pumping = true;
        try {
            for ($count = 0; $count < $this->config->framesPerTurn; ++$count) {
                $queue = $this->control->isEmpty() ? $this->output : $this->control;
                if ($queue->isEmpty()) {
                    break;
                }
                $frame = $queue->bottom();
                $result = $this->connection->tryWrite($frame);
                if ($result !== WriteResult::Accepted) {
                    return;
                }
                $queue->dequeue();
                $this->queuedBytes -= strlen($frame);
                if ((ord($frame[0]) & 15) === 8) {
                    $this->closeSent = true;
                    if ($this->closeReceived || $this->failed) {
                        $this->connection->end();
                        return;
                    }
                }
            }
            if ((!$this->output->isEmpty() || !$this->control->isEmpty()) && $this->writeContinuation === null) {
                $this->writeContinuation = $this->connection->loop()->defer(function (): void {
                    $this->writeContinuation = null;
                    $this->flush();
                });
            }
        } finally {
            $this->pumping = false;
            $this->notifyWritable();
        }
        if ($this->pressured && $this->bufferedBytes() <= intdiv($this->config->maxQueuedOutputBytes, 2)
            && $this->output->count() < $this->config->maxQueuedFrames && $this->control->count() < 16) {
            $this->pressured = false;
            if ($this->drainNotification === null) {
                $this->drainNotification = $this->connection->loop()->defer(function (): void {
                    $this->drainNotification = null;
                    foreach ($this->drainCallbacks as $callback) {
                        if ($this->isClosing() || $this->isClosed()) {
                            break;
                        }
                        try {
                            $callback($this);
                        } catch (\Throwable $error) {
                            $this->connection->reportFailure('WebSocket drain callback failed', $error);
                            $this->fail(1011, 'Handler failed');
                        }
                    }
                });
            }
        }
    }

    private function receiveClose(string $payload): void
    {
        if (strlen($payload) === 1) {
            $this->fail(1002, 'Invalid close');
            return;
        }
        $code = $payload === '' ? 1005 : unpack('n', $payload)[1];
        $reason = substr($payload, 2);
        if ($payload !== '' && !self::validCloseCode($code)) {
            $this->fail(1002, 'Invalid close code');
            return;
        }
        if (!self::validUtf8($reason)) {
            $this->fail(1007, 'Invalid close reason');
            return;
        }
        $this->peerCloseCode = $code;
        $this->peerCloseReason = $reason;
        $this->closeReceived = true;
        $this->beginClosing();
        $this->pauseTransportRead();
        $this->discardPendingFrames();
        if ($this->closeSent) {
            $this->connection->end();
        } else {
            $this->enqueueControl(8, $payload);
        }
    }

    private function fail(int $code, string $reason): void
    {
        if ($this->failed || $this->isClosed()) {
            return;
        }
        $this->failed = true;
        $this->beginClosing();
        $this->pauseTransportRead();
        $this->input = $this->fragment = '';
        $this->offset = 0;
        $this->discardPendingFrames();
        if ($this->closeSent) {
            $this->connection->end();
        } else {
            $this->enqueueControl(8, pack('n', $code) . $reason);
        }
    }

    private function beginClosing(): void
    {
        $this->closing = true;
        $this->drainAt = null;
        $this->notifyWritable();
        if ($this->closeAt === null && !$this->isClosed()) {
            $this->closeAt = hrtime(true) / 1_000_000_000 + $this->config->closeTimeout;
            $this->armDeadline();
        }
    }

    /**
     * Spread Close frames across at most one second, using a connection's
     * object id rather than random state. Transport still owns the worker's
     * hard deadline; reserve half its remaining allowance for the handshake.
     * Physical close cancels a pending stagger timer.
     */
    private function requestDrain(): void
    {
        if ($this->closing || $this->isClosed()) {
            return;
        }
        $this->draining = true;
        $this->notifyWritable();
        $fraction = ((spl_object_id($this->connection) * 2654435761) & 0xffff) / 65536;
        $now = hrtime(true) / 1_000_000_000;
        $window = min(1.0, $this->config->closeTimeout);
        $cutoff = $this->connection->drainDeadline();
        if ($cutoff !== null) {
            $window = min($window, max(0, $cutoff - $now) / 2);
        }
        $this->drainAt = $now + $fraction * $window;
        $this->armDeadline();
    }

    /**
     * Keep an earlier wake-up as activity moves the expiry later, like HTTP.
     * Heartbeat and close share one registration; no repeat timer is installed.
     */
    private function armDeadline(): void
    {
        if ($this->isClosed()) {
            return;
        }
        $at = $this->closeAt;
        if ($this->drainAt !== null) {
            $at = $this->drainAt;
        } elseif (!$this->closing && !$this->heartbeatPaused) {
            $idleAt = $this->idleTimeout === null ? null : $this->lastReceivedAt + $this->idleTimeout;
            $at = $this->pingAt === null ? $idleAt : ($idleAt === null ? $this->pingAt : min($this->pingAt, $idleAt));
        }
        if ($at === null || ($this->deadline !== null && $this->deadlineScheduledAt <= $at)) {
            return;
        }
        if ($this->deadline !== null) {
            $this->connection->loop()->cancel($this->deadline);
        }
        $this->deadlineScheduledAt = $at;
        $this->deadline = $this->connection->loop()->delay(max(0, $at - hrtime(true) / 1_000_000_000), $this->checkDeadline(...));
    }

    private function checkDeadline(): void
    {
        $this->deadline = null;
        if ($this->isClosed()) {
            return;
        }
        $now = hrtime(true) / 1_000_000_000;
        if ($this->drainAt !== null) {
            if ($now >= $this->drainAt) {
                $this->close(1001, 'Server shutdown');
            }
        } elseif ($this->closing) {
            if ($this->closeAt !== null && $now >= $this->closeAt) {
                $this->connection->close();
                return;
            }
        } elseif ($this->heartbeatPaused) {
            return;
        } elseif ($this->idleTimeout !== null && $now >= $this->lastReceivedAt + $this->idleTimeout) {
            $this->close(1001, 'Idle timeout');
        } elseif ($this->pingAt !== null && $now >= $this->pingAt) {
            $this->ping();
            $this->pingAt = $now + $this->config->pingInterval;
        }
        $this->armDeadline();
    }

    private function discardPendingFrames(): void
    {
        $this->output = new SplQueue();
        $this->control = new SplQueue();
        $this->queuedBytes = 0;
    }

    private function closed(): void
    {
        foreach ([$this->parseContinuation, $this->writeContinuation, $this->deadline, $this->drainNotification] as $id) {
            if ($id !== null) {
                $this->connection->loop()->cancel($id);
            }
        }
        $this->parseContinuation = $this->writeContinuation = $this->deadline = $this->drainNotification = null;
        $this->input = $this->fragment = '';
        $this->offset = 0;
        $this->discardPendingFrames();
        $this->drainCallbacks = [];
        $this->notifyWritable();
        if ($this->onClose !== null) {
            ($this->onClose)($this, $this->peerCloseCode, $this->peerCloseReason);
        }
    }

    private static function encodeFrame(int $opcode, string $payload, bool $fin = true): string
    {
        $length = strlen($payload);
        $header = chr(($fin ? 128 : 0) | $opcode);
        if ($length < 126) {
            return $header . chr($length) . $payload;
        }
        if ($length <= 65535) {
            return $header . chr(126) . pack('n', $length) . $payload;
        }
        return $header . chr(127) . pack('NN', 0, $length) . $payload;
    }

    private static function validUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    private static function validCloseCode(int $code): bool
    {
        return in_array($code, [1000, 1001, 1002, 1003, 1007, 1008, 1009, 1010, 1011, 1012, 1013, 1014], true)
            || ($code >= 3000 && $code <= 4999);
    }
}
