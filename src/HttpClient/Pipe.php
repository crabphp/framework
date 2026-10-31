<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Engine\EventLoop;
use Crab\Engine\Suspension;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Where a streamed answer's body passes from the request that receives it
 * to the code that reads it, as it arrives. The request waits while the
 * reader is behind by more than a little, so a slow reader never makes
 * the body pile up in memory; the reader waits while nothing has come.
 *
 * The request writes to the pipe, the call's sink, and the reader holds a
 * PipeBody; once nothing holds that, the request is given up.
 *
 * @internal
 */
final class Pipe implements StreamInterface
{
    /**
     * How far the reader may be behind before the request waits for it.
     */
    private const AHEAD = 262_144;

    /**
     * The request, by method, host and path, for the response's throw() to name.
     */
    public string $request = '';

    /**
     * The request as sent, for a timeout to be reported against.
     */
    public RequestInterface $sent;

    private string $buffer = '';
    private bool $finished = false;
    private ?\Throwable $failure = null;
    private bool $abandoned = false;
    private ?Suspension $reader = null;
    private ?Suspension $writer = null;
    private ?Suspension $head = null;
    /**
     * @var array{int, array<string, list<string>>, bool, string}|null
     */
    private ?array $opened = null;

    public function __construct(
        private readonly EventLoop $loop,
    ) {}

    /**
     * Called as a 2xx answer's head arrives, before any of its body.
     *
     * @param array<string, list<string>> $headers
     * @param bool $decoding Whether the body is being decompressed, so that what described its compressed form no longer holds.
     * @param string $version The HTTP version it came by.
     */
    public function open(int $status, array $headers, bool $decoding, string $version): void
    {
        // A request made again after its first answer broke off answers the same way.
        $this->opened ??= [$status, $headers, $decoding, $version];
        $this->wake($this->head);
    }

    /**
     * The status and headers of the answer, once its head has arrived, or
     * null when the request ended first: then its own result says the rest.
     *
     * @return array{int, array<string, list<string>>, bool, string}|null
     */
    public function opened(): ?array
    {
        return $this->opened;
    }

    /**
     * Waits for the answer's head, or for the request to end without one.
     *
     * @param float|null $idle Seconds to wait at most, or null for as long as the request takes.
     * @throws TimedOut When the head has not arrived within $idle seconds.
     */
    public function waitForHead(?float $idle = null): void
    {
        if ($this->opened !== null || $this->finished) {
            return;
        }
        $this->wait($this->head, $idle, 'waiting for the answer to begin');
    }

    public function write(string $string): int
    {
        if ($this->abandoned) {
            throw new \RuntimeException('no one reads the answer any longer');
        }
        $this->buffer .= $string;
        $this->wake($this->reader);
        while (strlen($this->buffer) > self::AHEAD && !$this->abandoned) {
            $this->writer = $this->loop->getSuspension();
            $this->writer->suspend();
        }
        return strlen($string);
    }

    /**
     * The request has ended: with the whole body, or with what went wrong.
     */
    public function finish(?\Throwable $failure = null): void
    {
        $this->finished = true;
        $this->failure ??= $failure;
        $this->wake($this->reader);
        $this->wake($this->head);
    }

    /**
     * Nothing will read the rest; the request is to stop sending it.
     */
    public function abandon(): void
    {
        $this->abandoned = true;
        $this->buffer = '';
        $this->wake($this->writer);
    }

    /**
     * Up to $length bytes, waiting until some arrive; '' once the body has ended.
     *
     * @param float|null $idle Seconds to wait at most for something to arrive, or null for as long as the request takes.
     * @throws TimedOut When nothing has arrived within $idle seconds.
     * @throws \Throwable What ended the request, once what came before it is read.
     */
    public function take(int $length, ?float $idle = null): string
    {
        while ($this->buffer === '' && !$this->finished) {
            $this->wait($this->reader, $idle, 'waiting for more of the answer');
        }
        if ($this->buffer === '') {
            if ($this->failure !== null) {
                throw $this->failure;
            }
            return '';
        }
        $taken = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, strlen($taken));
        if (strlen($this->buffer) <= self::AHEAD) {
            $this->wake($this->writer);
        }
        return $taken;
    }

    public function ended(): bool
    {
        return $this->buffer === '' && $this->finished;
    }

    public function __toString(): string
    {
        return '';
    }

    public function close(): void
    {
        $this->abandon();
    }

    public function detach(): mixed
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return $this->ended();
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new \RuntimeException('A streamed answer cannot be sought.');
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return !$this->abandoned;
    }

    public function isReadable(): bool
    {
        return false;
    }

    public function read(int $length): string
    {
        throw new \RuntimeException('A streamed answer is read through its response.');
    }

    public function getContents(): string
    {
        return $this->read(0);
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }

    /**
     * Suspends in $slot until woken, or throws once $idle seconds pass first.
     *
     * @throws TimedOut
     */
    private function wait(?Suspension &$slot, ?float $idle, string $doing): void
    {
        $suspension = $this->loop->getSuspension();
        $slot = $suspension;
        if ($idle === null) {
            $suspension->suspend();
            return;
        }
        $expired = false;
        $timer = $this->loop->delay($idle, static function () use (&$slot, $suspension, &$expired): void {
            if ($slot !== $suspension) {
                return;
            }
            $slot = null;
            $expired = true;
            $suspension->resume();
        });
        try {
            $suspension->suspend();
        } finally {
            $this->loop->cancel($timer);
        }
        if ($expired) {
            throw new TimedOut($doing);
        }
    }

    private function wake(?Suspension &$waiting): void
    {
        $suspension = $waiting;
        $waiting = null;
        $suspension?->resume();
    }
}
