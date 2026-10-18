<?php

declare(strict_types=1);

namespace Crab\Engine\Http;

/**
 * A seekable request body. Empty bodies need no stream; uploads spill to a
 * temporary file after 64 KiB.
 */
final class Body implements \Stringable
{
    /**
     * @var resource|null
     */
    private mixed $stream = null;
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
        if ($this->stream === null) {
            $stream = fopen('php://temp/maxmemory:65536', 'w+b');
            if ($stream === false) {
                throw new \RuntimeException('Cannot allocate request body storage.');
            }
            $this->stream = $stream;
        }
        if (fwrite($this->stream, $bytes) !== $length) {
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
     * before each read, and neither writes to it nor closes it.
     *
     * @return resource|null
     */
    public function stream(): mixed
    {
        return $this->stream;
    }

    public function rewind(): void
    {
        if ($this->stream !== null) {
            rewind($this->stream);
        }
    }

    public function read(int $length = 8192): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('Read length must be positive.');
        }
        if ($this->stream === null) {
            return '';
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
            return '';
        }
        $position = ftell($this->stream);
        $this->rewind();
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
}
