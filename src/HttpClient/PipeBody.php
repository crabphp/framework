<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Engine\Async\Task;
use Psr\Http\Message\StreamInterface;

/**
 * The body of a streamed answer, read as it arrives: each read waits
 * until some of it has come. It is read once, from the start. Once
 * nothing holds it, or it is closed, the request stops receiving the rest.
 *
 * @internal
 */
final class PipeBody implements StreamInterface
{
    private int $position = 0;
    private bool $closed = false;

    public function __construct(
        private readonly Pipe $pipe,
        private readonly Task $request,
    ) {}

    public function __destruct()
    {
        $this->close();
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
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (!$this->pipe->ended()) {
            $this->pipe->abandon();
            $this->request->cancel();
        }
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
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->closed || $this->pipe->ended();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('A streamed answer is read once, from its start, and cannot be sought.');
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
        throw new \RuntimeException('A streamed answer cannot be written to.');
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    /**
     * @throws \RuntimeException What ended the request before the body was all there.
     */
    public function read(int $length): string
    {
        if ($this->closed || $length < 1) {
            return '';
        }
        $read = $this->pipe->take($length);
        $this->position += strlen($read);
        return $read;
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(65_536);
        }
        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
