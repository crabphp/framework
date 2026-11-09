<?php

declare(strict_types=1);

namespace Crab\Engine\Network;

use Closure;

/**
 * Carries bytes to a connection for a producer that cannot simply stop,
 * such as a proxy passing on what another connection sent. What the
 * destination's queue refuses is held here, in order, and while anything
 * is held the source connection is not read, so that a slow reader costs
 * one read's worth of memory and not the whole stream.
 *
 *     $toClient = new Relay($client, $upstream);
 *     $upstream->onData(static fn (Connection $upstream, string $bytes) => $toClient->write($bytes));
 *     $upstream->onClose(static fn () => $toClient->end());
 *
 * The owner reads the source itself, so it may inspect or rewrite the
 * bytes on their way. Bytes written while the source is paused, such as
 * a message the owner made itself, are held too: the owner bounds them.
 */
final class Relay
{
    /**
     * The most bytes offered to the destination in one write.
     */
    private const CHUNK_SIZE = 65_536;

    /**
     * @var array<int, string>
     */
    private array $held = [];
    private int $head = 0;
    private int $tail = 0;
    private int $heldBytes = 0;
    private bool $ending = false;
    private bool $paused = false;
    private ?Closure $onProgress = null;

    /**
     * @param Connection $destination Where the bytes go.
     * @param Connection|null $source What is paused while the destination is behind; none for bytes made here.
     */
    public function __construct(
        private readonly Connection $destination,
        private ?Connection $source = null,
    ) {
        $destination->onDrain($this->flush(...));
        $destination->onClose($this->abandon(...));
    }

    /**
     * Names the connection whose bytes are relayed from now on, such as
     * an outbound connection that has just been made. The previous
     * source, if this relay paused it, is read again.
     */
    public function from(?Connection $source): void
    {
        if ($this->source === $source) {
            return;
        }
        $this->release();
        $this->source = $source;
        $this->hold();
    }

    /**
     * Called, with this relay, each time the destination took bytes that
     * had been held: a proxy's sign that a slow reader is still reading.
     *
     * @param (callable(self): void)|null $handler Must not suspend.
     */
    public function onProgress(?callable $handler): self
    {
        $this->onProgress = $handler === null ? null : $handler(...);
        return $this;
    }

    /**
     * Passes the bytes on, holding what the destination will not take yet.
     *
     * @return bool False when the destination is closed or ending, and the bytes went nowhere.
     */
    public function write(string $bytes): bool
    {
        if ($this->ending || $this->destination->isClosed()) {
            return false;
        }
        if ($bytes === '') {
            return true;
        }
        if ($this->heldBytes === 0) {
            $bytes = $this->offer($bytes);
            if ($bytes === null) {
                return false;
            }
            if ($bytes === '') {
                return true;
            }
        }
        $this->held[$this->tail++] = $bytes;
        $this->heldBytes += strlen($bytes);
        $this->hold();
        return true;
    }

    /**
     * Ends the destination once everything held has been passed on. Until
     * then the destination stays open; nothing more is accepted.
     */
    public function end(): void
    {
        if ($this->ending) {
            return;
        }
        $this->ending = true;
        if ($this->heldBytes === 0) {
            $this->destination->end();
        }
    }

    /**
     * Bytes accepted here that the destination has not taken yet.
     */
    public function heldBytes(): int
    {
        return $this->heldBytes;
    }

    public function isEnding(): bool
    {
        return $this->ending;
    }

    /**
     * Writes as much as the destination takes.
     *
     * @return string|null What it did not take, or null when it is closed.
     */
    private function offer(string $bytes): ?string
    {
        $limit = min(self::CHUNK_SIZE, $this->destination->maxWriteSize());
        $length = strlen($bytes);
        $offset = 0;
        while ($offset < $length) {
            $chunk = $offset === 0 && $length <= $limit ? $bytes : substr($bytes, $offset, $limit);
            $result = $this->destination->tryWrite($chunk);
            if ($result === WriteResult::Closed) {
                return null;
            }
            if ($result === WriteResult::Backpressured) {
                break;
            }
            $offset += strlen($chunk);
        }
        return $offset === 0 ? $bytes : substr($bytes, $offset);
    }

    private function flush(): void
    {
        if ($this->heldBytes === 0) {
            return;
        }
        $before = $this->heldBytes;
        while ($this->head < $this->tail) {
            $bytes = $this->held[$this->head];
            $left = $this->offer($bytes);
            if ($left === null) {
                $this->abandon();
                return;
            }
            $this->heldBytes -= strlen($bytes) - strlen($left);
            if ($left !== '') {
                $this->held[$this->head] = $left;
                break;
            }
            unset($this->held[$this->head++]);
        }
        if ($this->heldBytes === 0) {
            $this->held = [];
            $this->head = $this->tail = 0;
            if ($this->ending) {
                $this->destination->end();
            } else {
                $this->release();
            }
        }
        if ($this->heldBytes < $before && $this->onProgress !== null) {
            ($this->onProgress)($this);
        }
    }

    /**
     * Forgets what was held for a destination that has gone. The source
     * is left as it is: whoever owns both decides what becomes of it.
     */
    private function abandon(): void
    {
        $this->held = [];
        $this->head = $this->tail = 0;
        $this->heldBytes = 0;
        $this->paused = false;
        $this->onProgress = null;
    }

    private function hold(): void
    {
        if ($this->heldBytes > 0 && !$this->paused && $this->source !== null && !$this->source->isClosed()) {
            $this->paused = true;
            $this->source->pauseRead();
        }
    }

    private function release(): void
    {
        if ($this->paused) {
            $this->paused = false;
            if ($this->source !== null && !$this->source->isClosed()) {
                $this->source->resumeRead();
            }
        }
    }
}
