<?php

declare(strict_types=1);

namespace Crab\Http;

use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 stream over chunks made as they are sent, such as a generator
 * that yields an answer while it is still being worked out. It is read
 * once, from the start, and its size is not known until it ends.
 *
 * Sent by the server, each chunk goes out as soon as it is made, and the
 * generator waits while the client is slower than it.
 */
final class ChunkStream implements StreamInterface
{
    /**
     * The largest piece the server is handed at once.
     */
    private const PIECE = 65_536;

    private ?\Iterator $chunks;
    private string $buffer = '';
    private int $position = 0;
    private bool $started = false;

    /**
     * @param iterable<string> $chunks
     */
    public function __construct(iterable $chunks)
    {
        $this->chunks = match (true) {
            $chunks instanceof \Iterator => $chunks,
            is_array($chunks) => new \ArrayIterator($chunks),
            default => new \IteratorIterator($chunks),
        };
    }

    /**
     * The chunks for the server to send, each no longer than it takes, once.
     *
     * @return \Generator<int, string>
     * @internal
     */
    public function pieces(): \Generator
    {
        $this->begin();
        if ($this->buffer !== '') {
            yield from self::split($this->buffer);
            $this->buffer = '';
        }
        while ($this->chunks !== null && $this->chunks->valid()) {
            $chunk = $this->chunks->current();
            if (!is_string($chunk)) {
                throw new \UnexpectedValueException(
                    'A streamed body is made of strings; ' . get_debug_type($chunk) . ' was given.',
                );
            }
            $this->position += strlen($chunk);
            yield from self::split($chunk);
            $this->chunks->next();
        }
        $this->chunks = null;
    }

    public function __toString(): string
    {
        try {
            return $this->getContents();
        } catch (\RuntimeException) {
            return '';
        }
    }

    public function close(): void
    {
        $this->chunks = null;
        $this->buffer = '';
    }

    public function detach(): mixed
    {
        $this->close();
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position - strlen($this->buffer);
    }

    public function eof(): bool
    {
        if ($this->buffer !== '') {
            return false;
        }
        $this->begin();
        return $this->chunks === null || !$this->chunks->valid();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('A streamed body is read once, from its start, and cannot be sought.');
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('A streamed body is made by its chunks and cannot be written to.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($length < 1) {
            return '';
        }
        $this->begin();
        while ($this->buffer === '' && $this->chunks !== null && $this->chunks->valid()) {
            $chunk = $this->chunks->current();
            if (!is_string($chunk)) {
                throw new \UnexpectedValueException(
                    'A streamed body is made of strings; ' . get_debug_type($chunk) . ' was given.',
                );
            }
            $this->buffer = $chunk;
            $this->position += strlen($chunk);
            $this->chunks->next();
        }
        $read = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($read));
        return $read;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(self::PIECE);
        }
        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }

    /**
     * Starts a generator's work on first use, not when the response is made.
     */
    private function begin(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->chunks?->rewind();
    }

    /**
     * @return \Generator<int, string>
     */
    private static function split(string $chunk): \Generator
    {
        for ($offset = 0; $offset < strlen($chunk); $offset += self::PIECE) {
            yield substr($chunk, $offset, self::PIECE);
        }
    }
}
