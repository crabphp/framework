<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Http\Stream;
use Psr\Http\Message\StreamInterface;

/**
 * Where an answer's body is kept as it arrives: in memory while it is
 * small, on disk beyond that, and as what it says when it was sent
 * compressed. The body of a success goes where the call said instead,
 * when it said: into a stream as it arrives, or into a file that appears
 * at its path only once the body is all there.
 *
 * Every byte is counted as it arrives, and as it is decompressed, so a
 * body longer than allowed is given up before more of it is kept.
 *
 * @internal
 */
final class BodySink
{
    /**
     * How much is held in memory before it goes to disk.
     */
    private const IN_MEMORY = 1_048_576;

    /**
     * How much compressed input is decompressed at once, so that a small
     * input that expands enormously is caught before it fills memory.
     */
    private const INFLATE_STEP = 8_192;

    /**
     * Whether the body is being decompressed, in which case what described its compressed form no longer holds.
     */
    public readonly bool $decoding;
    /**
     * @var resource|null
     */
    private mixed $storage = null;
    /**
     * The stream the body goes into, when the call gave one.
     */
    private ?StreamInterface $destination = null;
    /**
     * The file beside the call's path that the body is written to, until it is all there and moved to that path.
     */
    private ?string $part = null;
    private ?\InflateContext $inflate = null;
    private readonly string $coding;
    private int $received = 0;
    private int $kept = 0;

    /**
     * @param string $coding The answer's Content-Encoding.
     * @param bool $destined Whether the body goes where the call said, when it said.
     */
    public function __construct(
        private readonly Receiving $receiving,
        string $coding = '',
        bool $destined = false,
    ) {
        $this->coding = strtolower(trim($coding));
        $this->decoding =
            $receiving->decode
            && ($this->coding === 'gzip' || $this->coding === 'deflate')
            && function_exists('inflate_init');
        $sink = $destined ? $receiving->sink : null;
        if ($sink instanceof StreamInterface) {
            $this->destination = $sink;
            return;
        }
        if ($sink !== null) {
            // Beside the file, so that moving it into place is one rename on one disk.
            $part = @tempnam(dirname($sink), '.' . basename($sink) . '.');
            $storage = $part === false ? false : @fopen($part, 'w+b');
            if ($storage === false) {
                if ($part !== false) {
                    @unlink($part);
                }
                throw new \RuntimeException("the answer cannot be written beside {$sink}");
            }
            // tempnam() makes a file only its owner may read; a download is as readable as any other file made here.
            @chmod($part, 0o666 & ~umask());
            $this->part = $part;
            $this->storage = $storage;
            return;
        }
        $storage = fopen('php://temp/maxmemory:' . self::IN_MEMORY, 'w+b');
        if ($storage === false) {
            throw new \RuntimeException('there is no room to keep the answer');
        }
        $this->storage = $storage;
    }

    public function __destruct()
    {
        // A body that never got all the way leaves nothing at the path, nor beside it.
        if ($this->part !== null) {
            if (is_resource($this->storage)) {
                fclose($this->storage);
            }
            @unlink($this->part);
        }
    }

    /**
     * @throws TooLarge When the body has become longer than allowed.
     */
    public function write(string $bytes): void
    {
        $this->received += strlen($bytes);
        if ($this->received > $this->receiving->limit) {
            throw new TooLarge($this->receiving->limit);
        }
        if (!$this->decoding) {
            $this->keep($bytes);
            return;
        }
        foreach (str_split($bytes, self::INFLATE_STEP) as $piece) {
            if ($piece === '') {
                continue;
            }
            // "deflate" is sent both with and without its two-byte header; the first bytes tell which.
            $this->inflate ??= inflate_init(match (true) {
                $this->coding === 'gzip' => ZLIB_ENCODING_GZIP,
                strlen($piece) > 1
                    && (ord($piece[0]) & 0x0F) === 8
                    && (((ord($piece[0]) << 8) | ord($piece[1])) % 31) === 0
                    => ZLIB_ENCODING_DEFLATE,
                default => ZLIB_ENCODING_RAW,
            });
            $inflated = @inflate_add($this->inflate, $piece);
            if ($inflated === false) {
                throw new \RuntimeException('the answer says it is compressed and is not');
            }
            $this->keep($inflated);
        }
    }

    /**
     * The body, at its start; or the call's stream, where the body ended
     * in it. A file is moved to the call's path now, the body being all
     * there.
     */
    public function body(): StreamInterface
    {
        if ($this->destination !== null) {
            return $this->destination;
        }
        if ($this->part !== null) {
            $path = (string) $this->receiving->sink;
            $flushed = fflush($this->storage);
            fclose($this->storage);
            if (!$flushed || !@rename($this->part, $path)) {
                throw new \RuntimeException("the answer could not be moved to {$path}");
            }
            $this->part = null;
            $file = @fopen($path, 'rb');
            if ($file === false) {
                throw new \RuntimeException("{$path} could not be opened after the answer was written to it");
            }
            return new Stream($file);
        }
        rewind($this->storage);
        return new Stream($this->storage);
    }

    private function keep(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $this->kept += strlen($bytes);
        if ($this->kept > $this->receiving->limit) {
            throw new TooLarge($this->receiving->limit);
        }
        if ($this->destination === null) {
            if (fwrite($this->storage, $bytes) !== strlen($bytes)) {
                throw new \RuntimeException('there is no room to keep the answer');
            }
            return;
        }
        $this->receiving->wrote = true;
        while ($bytes !== '') {
            $written = $this->destination->write($bytes);
            if ($written <= 0) {
                throw new \RuntimeException('the stream the answer goes into took no more of it');
            }
            $bytes = substr($bytes, $written);
        }
    }
}
