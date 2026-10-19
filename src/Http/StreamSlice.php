<?php

declare(strict_types=1);

namespace Crab\Http;

use Psr\Http\Message\StreamInterface;

/**
 * A read-only PSR-7 stream over one part of a seekable resource: an
 * uploaded file where it lies in the request's body. Nothing is copied
 * to make one, and several may share one resource, because each keeps a
 * position of its own and seeks before it reads.
 */
final class StreamSlice implements StreamInterface
{
    private int $position = 0;
    /**
     * @var resource|null
     */
    private mixed $resource;

    /**
     * @param resource $resource Seekable, and not written to while slices of it are read.
     * @param int $offset Where the part starts in the resource.
     * @param int $length How many bytes the part is.
     */
    public function __construct(
        mixed $resource,
        private readonly int $offset,
        private readonly int $length,
    ) {
        if (!is_resource($resource) || $offset < 0 || $length < 0) {
            throw new \InvalidArgumentException(
                'A stream slice needs a stream resource, and an offset and a length that are not negative.',
            );
        }
        $this->resource = $resource;
    }

    public function __toString(): string
    {
        if ($this->resource === null) {
            return '';
        }
        $this->position = 0;
        return $this->getContents();
    }

    /**
     * The resource is shared with the rest of the body, so it is let go of and not closed.
     */
    public function close(): void
    {
        $this->resource = null;
    }

    /**
     * Null, because the resource holds more than this part.
     */
    public function detach(): mixed
    {
        $this->resource = null;
        return null;
    }

    public function getSize(): int
    {
        return $this->length;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= $this->length;
    }

    public function isSeekable(): bool
    {
        return $this->resource !== null;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => $this->length + $offset,
            default => -1,
        };
        if ($this->resource === null || $position < 0) {
            throw new \RuntimeException('Cannot seek in the stream.');
        }
        $this->position = $position;
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
        throw new \RuntimeException('The stream is not writable.');
    }

    public function isReadable(): bool
    {
        return $this->resource !== null;
    }

    public function read(int $length): string
    {
        if ($this->resource === null) {
            throw new \RuntimeException('The stream is not readable.');
        }
        $length = min($length, $this->length - $this->position);
        if ($length < 1) {
            return '';
        }
        if (fseek($this->resource, $this->offset + $this->position) === -1) {
            throw new \RuntimeException('Cannot seek in the stream.');
        }
        $bytes = fread($this->resource, $length);
        if ($bytes === false) {
            throw new \RuntimeException('Cannot read from the stream.');
        }
        $this->position += strlen($bytes);
        return $bytes;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $bytes = $this->read($this->length - $this->position);
            if ($bytes === '') {
                // The resource is shorter than it was said to be.
                break;
            }
            $contents .= $bytes;
        }
        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        if ($this->resource === null) {
            return $key === null ? [] : null;
        }
        $metadata = stream_get_meta_data($this->resource);
        return $key === null ? $metadata : $metadata[$key] ?? null;
    }

    /**
     * Copies the whole part into another resource without holding it in
     * memory, wherever this stream's position is.
     *
     * @internal
     * @param resource $target
     * @return bool Whether every byte was written.
     */
    public function copyTo(mixed $target): bool
    {
        if ($this->resource === null) {
            throw new \RuntimeException('The stream is not readable.');
        }
        if ($this->length === 0) {
            return true;
        }
        if (fseek($this->resource, $this->offset) === -1) {
            throw new \RuntimeException('Cannot seek in the stream.');
        }
        return @stream_copy_to_stream($this->resource, $target, $this->length) === $this->length;
    }
}
