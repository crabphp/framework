<?php

declare(strict_types=1);

namespace Crab\Engine\Http;

/**
 * A seekable request body. A body of up to 64 KiB is kept in a string, so
 * the usual small JSON body opens no stream; uploads spill to a temporary
 * file after that.
 */
final class Body implements \Stringable
{
    /**
     * The most bytes kept in memory before the body moves to a stream.
     */
    private const MEMORY = 65_536;

    /**
     * @var resource|null
     */
    private mixed $stream = null;
    private string $memory = '';
    private int $position = 0;
    private int $length = 0;

    /**
     * @internal Parser-only mutation, before delivery to the handler.
     */
    public function append(string $bytes): void
    {
        $length = strlen($bytes);
        if ($length === 0) {
            return;
        }
        if ($this->stream === null && ($this->length + $length) <= self::MEMORY) {
            $this->memory .= $bytes;
            $this->length += $length;
            $this->position = $this->length;
            return;
        }
        $stream = $this->stream ?? $this->spill();
        fseek($stream, 0, SEEK_END);
        if (fwrite($stream, $bytes) !== $length) {
            throw new \RuntimeException('Cannot store request body.');
        }
        $this->length += $length;
    }

    public function size(): int
    {
        return $this->length;
    }

    /**
     * The storage itself, for reading a large body where it lies and not
     * through a copy; null for an empty body. Whoever takes it seeks
     * before each read, and neither writes to it nor closes it. A body
     * kept in memory moves to a stream first.
     *
     * @return resource|null
     */
    public function stream(): mixed
    {
        if ($this->stream === null && $this->length > 0) {
            return $this->spill();
        }
        return $this->stream;
    }

    public function rewind(): void
    {
        if ($this->stream !== null) {
            rewind($this->stream);
            return;
        }
        $this->position = 0;
    }

    public function read(int $length = 8192): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('Read length must be positive.');
        }
        if ($this->stream === null) {
            $bytes = (string) substr($this->memory, $this->position, $length);
            $this->position += strlen($bytes);
            return $bytes;
        }
        $bytes = fread($this->stream, $length);
        if ($bytes === false) {
            throw new \RuntimeException('Cannot read request body.');
        }
        return $bytes;
    }

    /**
     * @return \Generator<int, string> Starts at the beginning of the body.
     */
    public function chunks(int $chunkSize = 8192): \Generator
    {
        $this->rewind();
        while (($chunk = $this->read($chunkSize)) !== '') {
            yield $chunk;
        }
    }

    /**
     * Explicitly materializes the bounded body in memory.
     */
    public function contents(): string
    {
        if ($this->stream === null) {
            return $this->memory;
        }
        $position = ftell($this->stream);
        rewind($this->stream);
        $bytes = stream_get_contents($this->stream);
        fseek($this->stream, $position);
        if ($bytes === false) {
            throw new \RuntimeException('Cannot read request body.');
        }
        return $bytes;
    }

    public function __toString(): string
    {
        return $this->contents();
    }

    /**
     * Moves what is kept in memory to a stream, at the same read position.
     *
     * @return resource
     */
    private function spill(): mixed
    {
        $stream = fopen('php://temp/maxmemory:' . self::MEMORY, 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Cannot allocate request body storage.');
        }
        if ($this->memory !== '' && fwrite($stream, $this->memory) !== strlen($this->memory)) {
            throw new \RuntimeException('Cannot store request body.');
        }
        fseek($stream, $this->position);
        $this->stream = $stream;
        $this->memory = '';
        return $stream;
    }
}
