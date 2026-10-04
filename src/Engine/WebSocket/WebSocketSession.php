<?php

declare(strict_types=1);

namespace Crab\Engine\WebSocket;

use Closure;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\WriteResult;
use SplQueue;

/**
 * One RFC 6455 server session. Application callbacks must not suspend.
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
    private bool $closeSent = false;
    private bool $closeReceived = false;
    private bool $failed = false;
    private int $peerCloseCode = 1006;
    private string $peerCloseReason = '';
    private ?string $parseContinuation = null;
    private ?string $writeContinuation = null;
    private ?string $closeTimer = null;
    private ?string $drainNotification = null;
    private readonly Closure $onMessage;
    private readonly ?Closure $onClose;
    /**
     * @var list<Closure>
     */
    private array $drainCallbacks = [];
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
        if ($connection->maxWriteSize() < 135) {
            throw new \InvalidArgumentException('WebSocket requires a transport write limit of at least 135 bytes.');
        }
        $connection->onData(fn (Connection $connection, string $data) => $this->receive($data));
        $connection->onDrain(fn () => $this->flush());
        $connection->onEof(fn () => $connection->close());
        $connection->onClose(fn () => $this->closed());
        $connection->onDrainRequest(fn () => $this->close(1001, 'Server shutdown'));
        $connection->resumeRead();
    }

    public function isClosed(): bool
    {
        return $this->connection->isClosed();
    }

    public function isClosing(): bool
    {
        return $this->closing;
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

    public function pauseRead(): void
    {
        $this->readPaused = true;
        $this->connection->pauseRead();
    }

    public function resumeRead(): void
    {
        $this->readPaused = false;
        if ($this->parseContinuation === null) {
            $this->parse();
        }
        if ($this->parseContinuation === null && !$this->failed && !$this->closeReceived && !$this->readPaused) {
            $this->connection->resumeRead();
        }
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
        if ($this->closing || $this->isClosed()) {
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
        if ($this->closing || $this->isClosed()) {
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
        if ($this->parsing || $this->readPaused) {
            return;
        }
        $this->parsing = true;
        try {
            $this->parseFrames();
        } finally {
            $this->parsing = false;
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
        if ($this->closing || $this->isClosed()) {
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
        }
        if ($this->pressured && $this->bufferedBytes() <= intdiv($this->config->maxQueuedOutputBytes, 2)
            && $this->output->count() < $this->config->maxQueuedFrames && $this->control->count() < 16) {
            $this->pressured = false;
            if ($this->drainNotification === null) {
                $this->drainNotification = $this->connection->loop()->defer(function (): void {
                    $this->drainNotification = null;
                    foreach ($this->drainCallbacks as $callback) {
                        if ($this->closing || $this->isClosed()) {
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
        $this->connection->pauseRead();
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
        $this->connection->pauseRead();
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
        if ($this->closeTimer === null && !$this->isClosed()) {
            $this->closeTimer = $this->connection->loop()->delay(
                $this->config->closeTimeout,
                function (): void {
                    $this->closeTimer = null;
                    $this->connection->close();
                },
            );
        }
    }

    private function discardPendingFrames(): void
    {
        $this->output = new SplQueue();
        $this->control = new SplQueue();
        $this->queuedBytes = 0;
    }

    private function closed(): void
    {
        foreach ([$this->parseContinuation, $this->writeContinuation, $this->closeTimer, $this->drainNotification] as $id) {
            if ($id !== null) {
                $this->connection->loop()->cancel($id);
            }
        }
        $this->parseContinuation = $this->writeContinuation = $this->closeTimer = $this->drainNotification = null;
        $this->input = $this->fragment = '';
        $this->offset = 0;
        $this->discardPendingFrames();
        $this->drainCallbacks = [];
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
