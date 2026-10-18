<?php

declare(strict_types=1);

namespace Crab\HttpClient;

/**
 * Where an answer's body is kept as it arrives: in memory while it is
 * small, on disk beyond that, and as what it says when it was sent
 * compressed.
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
     * Whether the body is being decompressed, in which case what described its compressed form no longer holds.
     */
    public readonly bool $decoding;
    /**
     * @var resource
     */
    private mixed $storage;
    private ?\InflateContext $inflate = null;
    private readonly string $coding;

    /**
     * @param string $coding The answer's Content-Encoding.
     */
    public function __construct(string $coding = '')
    {
        $this->coding = strtolower(trim($coding));
        $this->decoding = ($this->coding === 'gzip' || $this->coding === 'deflate') && function_exists('inflate_init');
        $storage = fopen('php://temp/maxmemory:' . self::IN_MEMORY, 'w+b');
        if ($storage === false) {
            throw new \RuntimeException('there is no room to keep the answer');
        }
        $this->storage = $storage;
    }

    public function write(string $bytes): void
    {
        if ($this->decoding && $bytes !== '') {
            // "deflate" is sent both with and without its two-byte header; the first bytes tell which.
            $this->inflate ??= inflate_init(match (true) {
                $this->coding === 'gzip' => ZLIB_ENCODING_GZIP,
                strlen($bytes) > 1
                    && (ord($bytes[0]) & 0x0F) === 8
                    && (((ord($bytes[0]) << 8) | ord($bytes[1])) % 31) === 0
                    => ZLIB_ENCODING_DEFLATE,
                default => ZLIB_ENCODING_RAW,
            });
            $bytes = @inflate_add($this->inflate, $bytes);
            if ($bytes === false) {
                throw new \RuntimeException('the answer says it is compressed and is not');
            }
        }
        if ($bytes !== '' && fwrite($this->storage, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('there is no room to keep the answer');
        }
    }

    /**
     * The body, at its start.
     *
     * @return resource
     */
    public function stream(): mixed
    {
        rewind($this->storage);
        return $this->storage;
    }
}
