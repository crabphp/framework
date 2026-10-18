<?php

declare(strict_types=1);

namespace Crab\Engine\Async;

use Closure;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\WriteResult;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Exclusive coroutine consumer of a connection, with at most one reader and writer.
 * Do not also install protocol/data/EOF handlers or write directly to the connection.
 * Read-ahead is one transport chunk (at most 64 KiB); reads resume only on demand.
 */
final class ConnectionStream
{
    private string $buffer = '';
    private bool $eof = false;
    private bool $reading = false;
    private bool $writing = false;
    private ?Closure $reader = null;
    private ?Closure $writer = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
        $connection->pauseRead();
        $connection->onData(function (Connection $connection, string $bytes): void {
            $connection->pauseRead();
            $this->buffer = $bytes;
            $this->reader?->__invoke();
        });
        $connection->onEof(function (): void {
            $this->eof = true;
            $this->reader?->__invoke();
        });
        $connection->onClose(function (): void {
            $error = new RuntimeException('Connection closed.');
            $this->reader?->__invoke($error);
            $this->writer?->__invoke($error);
        });
        $connection->onDrain(function (): void {
            $this->writer?->__invoke();
        });
    }

    /**
     * Returns up to $maxBytes, or null at read EOF. Empty data is never returned.
     */
    public function read(int $maxBytes = 65_536, ?Cancellation $cancellation = null): ?string
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('Read size must be positive.');
        }
        if ($this->reading) {
            throw new LogicException('Only one coroutine may read a connection at a time.');
        }
        $cancellation?->throwIfCancelled();
        if ($this->buffer !== '') {
            return $this->consume($maxBytes);
        }
        if ($this->eof) {
            return null;
        }
        if ($this->connection->isClosed()) {
            throw new RuntimeException('Connection closed.');
        }
        $this->reading = true;
        $suspension = $this->connection->loop()->getSuspension();
        $settled = false;
        $unsubscribe = null;
        $finish = function (?Throwable $error = null) use ($suspension, $maxBytes, &$settled, &$unsubscribe): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $this->reader = null;
            $this->connection->pauseRead();
            $unsubscribe?->__invoke();
            if ($error !== null) {
                $suspension->throw($error);
            } else {
                $suspension->resume($this->buffer === '' ? null : $this->consume($maxBytes));
            }
        };
        $this->reader = $finish;
        $unsubscribe = $cancellation?->subscribe(static fn(CancelledException $error) => $finish($error));
        $this->connection->resumeRead();
        try {
            return $suspension->suspend();
        } finally {
            $settled = true;
            $this->reading = false;
            $this->reader = null;
            $this->connection->pauseRead();
            $unsubscribe?->__invoke();
        }
    }

    /**
     * Returns once every byte reaches the local socket; this is not peer acknowledgement.
     */
    public function write(string $bytes, ?Cancellation $cancellation = null): void
    {
        if ($this->writing) {
            throw new LogicException('Only one coroutine may write a connection at a time.');
        }
        $cancellation?->throwIfCancelled();
        if ($this->connection->isClosed()) {
            throw new RuntimeException('Connection closed.');
        }
        $this->writing = true;
        $submitted = false;
        try {
            $offset = 0;
            $length = strlen($bytes);
            $chunkSize = min(65_536, $this->connection->maxWriteSize());
            while ($offset < $length) {
                $cancellation?->throwIfCancelled();
                $chunk = substr($bytes, $offset, $chunkSize);
                $result = $this->connection->tryWrite($chunk);
                if ($result === WriteResult::Closed) {
                    throw new RuntimeException('Connection is closed for writing.');
                }
                if ($result === WriteResult::Accepted) {
                    $submitted = true;
                    $offset += strlen($chunk);
                }
                if ($result === WriteResult::Backpressured || $this->connection->bufferedBytes() > 0) {
                    $this->waitForCapacity($cancellation, $submitted);
                }
            }
            while ($this->connection->bufferedBytes() > 0) {
                $this->waitForCapacity($cancellation, $submitted);
            }
        } catch (CancelledException $error) {
            if ($submitted) {
                $this->connection->close();
            }
            throw $error;
        } finally {
            $this->writing = false;
        }
    }

    public function close(): void
    {
        $this->connection->close();
    }

    public function end(): void
    {
        $this->connection->end();
    }

    public function shutdownWrite(): void
    {
        $this->connection->shutdownWrite();
    }

    private function consume(int $maxBytes): string
    {
        if (strlen($this->buffer) <= $maxBytes) {
            $bytes = $this->buffer;
            $this->buffer = '';
            return $bytes;
        }
        $bytes = substr($this->buffer, 0, $maxBytes);
        $this->buffer = substr($this->buffer, $maxBytes);
        return $bytes;
    }

    private function waitForCapacity(?Cancellation $cancellation, bool $submitted): void
    {
        $cancellation?->throwIfCancelled();
        $suspension = $this->connection->loop()->getSuspension();
        $settled = false;
        $unsubscribe = null;
        $finish = function (?Throwable $error = null) use ($suspension, $submitted, &$settled, &$unsubscribe): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $this->writer = null;
            $unsubscribe?->__invoke();
            if ($error instanceof CancelledException && $submitted) {
                $this->connection->close();
            }
            if ($error !== null) {
                $suspension->throw($error);
            } else {
                $suspension->resume();
            }
        };
        $this->writer = $finish;
        $unsubscribe = $cancellation?->subscribe(static fn(CancelledException $error) => $finish($error));
        try {
            $suspension->suspend();
        } finally {
            $settled = true;
            $this->writer = null;
            $unsubscribe?->__invoke();
        }
    }
}
