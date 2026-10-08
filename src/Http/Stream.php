<?php

declare(strict_types=1);

namespace Crab\Http;

use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 stream over a string or a PHP stream resource. A string stays a
 * string, with no resource allocated, until it is read, written or sought.
 */
final class Stream implements StreamInterface
{
    private ?string $text = null;
    /**
     * @var resource|null
     */
    private mixed $resource = null;

    /**
     * @param string|resource $content
     */
    public function __construct(mixed $content = '')
    {
        if (is_string($content)) {
            $this->text = $content;
        } elseif (is_resource($content) && get_resource_type($content) === 'stream') {
            $this->resource = $content;
        } else {
            throw new \InvalidArgumentException('A stream needs a string or a stream resource.');
        }
    }

    /**
     * The whole content while it is still an untouched string.
     *
     * @internal
     */
    public function text(): ?string
    {
        return $this->text;
    }

    public function __toString(): string
    {
        if ($this->text !== null) {
            return $this->text;
        }
        if ($this->resource === null) {
            return '';
        }
        if ($this->isSeekable()) {
            rewind($this->resource);
        }
        return (string) stream_get_contents($this->resource);
    }

    public function close(): void
    {
        $resource = $this->detach();
        if ($resource !== null) {
            fclose($resource);
        }
    }

    public function detach()
    {
        if ($this->text === null && $this->resource === null) {
            return null;
        }
        $resource = $this->resource();
        $this->resource = null;
        return $resource;
    }

    public function getSize(): ?int
    {
        if ($this->text !== null) {
            return strlen($this->text);
        }
        if ($this->resource === null) {
            return null;
        }
        $stat = fstat($this->resource);
        // Only a file has a size; a pipe or a socket reports none that means anything.
        return $stat !== false && ($stat['mode'] & 0170000) === 0100000 ? $stat['size'] : null;
    }

    public function tell(): int
    {
        $position = ftell($this->resource());
        if ($position === false) {
            throw new \RuntimeException('Cannot determine the stream position.');
        }
        return $position;
    }

    public function eof(): bool
    {
        if ($this->text === null && $this->resource === null) {
            return true;
        }
        return feof($this->resource());
    }

    public function isSeekable(): bool
    {
        if ($this->text !== null) {
            return true;
        }
        return $this->resource !== null && stream_get_meta_data($this->resource)['seekable'];
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        if (!$this->isSeekable() || fseek($this->resource(), $offset, $whence) === -1) {
            throw new \RuntimeException('Cannot seek in the stream.');
        }
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        if ($this->text !== null) {
            return true;
        }
        return $this->resource !== null && strpbrk(stream_get_meta_data($this->resource)['mode'], 'waxc+') !== false;
    }

    public function write(string $string): int
    {
        if (!$this->isWritable()) {
            throw new \RuntimeException('The stream is not writable.');
        }
        $written = fwrite($this->resource(), $string);
        if ($written === false) {
            throw new \RuntimeException('Cannot write to the stream.');
        }
        return $written;
    }

    public function isReadable(): bool
    {
        if ($this->text !== null) {
            return true;
        }
        return $this->resource !== null && strpbrk(stream_get_meta_data($this->resource)['mode'], 'r+') !== false;
    }

    public function read(int $length): string
    {
        if (!$this->isReadable()) {
            throw new \RuntimeException('The stream is not readable.');
        }
        if ($length < 1) {
            return '';
        }
        $bytes = fread($this->resource(), $length);
        if ($bytes === false) {
            throw new \RuntimeException('Cannot read from the stream.');
        }
        return $bytes;
    }

    public function getContents(): string
    {
        if (!$this->isReadable()) {
            throw new \RuntimeException('The stream is not readable.');
        }
        $bytes = stream_get_contents($this->resource());
        if ($bytes === false) {
            throw new \RuntimeException('Cannot read from the stream.');
        }
        return $bytes;
    }

    public function getMetadata(?string $key = null)
    {
        if ($this->text === null && $this->resource === null) {
            return $key === null ? [] : null;
        }
        $metadata = stream_get_meta_data($this->resource());
        return $key === null ? $metadata : ($metadata[$key] ?? null);
    }

    /**
     * The resource behind the stream, still owned by it, for reading its
     * content where it lies.
     *
     * @internal
     * @return resource
     */
    public function handle(): mixed
    {
        return $this->resource();
    }

    /**
     * The underlying resource; a string moves into a temporary stream,
     * positioned at its start.
     *
     * @return resource
     */
    private function resource(): mixed
    {
        if ($this->text !== null) {
            $resource = fopen('php://temp', 'w+b');
            if ($resource === false) {
                throw new \RuntimeException('Cannot allocate stream storage.');
            }
            fwrite($resource, $this->text);
            rewind($resource);
            $this->resource = $resource;
            $this->text = null;
        }
        if ($this->resource === null) {
            throw new \RuntimeException('The stream is detached.');
        }
        return $this->resource;
    }
}
