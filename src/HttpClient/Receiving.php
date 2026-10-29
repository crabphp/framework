<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Psr\Http\Message\StreamInterface;

/**
 * How the answers to one call are received: how many bytes of body each
 * may have, whether a compressed one is decompressed, and where the body
 * of a success goes.
 *
 * @internal
 */
final class Receiving
{
    /**
     * Whether any of a body went into a stream destination, which cannot
     * be taken back, so that the request is not made again into it.
     */
    public bool $wrote = false;

    /**
     * @param int $limit The most bytes of body an answer may have, as received and as decompressed.
     * @param bool $decode Whether a body sent compressed with gzip or deflate is decompressed.
     * @param string|StreamInterface|null $sink Where the body of a 2xx answer goes: a file, or a stream; null to keep it.
     */
    public function __construct(
        public readonly int $limit,
        public readonly bool $decode = true,
        public readonly string|StreamInterface|null $sink = null,
    ) {}

    /**
     * Refuses an answer that says, before its body, that it is longer than allowed.
     *
     * @throws TooLarge
     */
    public function expect(int $length): void
    {
        if ($length > $this->limit) {
            throw new TooLarge($this->limit, $length);
        }
    }

    /**
     * Where an answer's body is to be kept.
     *
     * @param string $coding Its Content-Encoding.
     * @param bool $bodiless Whether it has no body whatever its headers say.
     * @param array<string, list<string>> $headers Its headers, for a streamed call to hand on as soon as they arrive.
     * @param string $version The HTTP version it came by.
     */
    public function sink(
        int $status,
        string $coding,
        bool $bodiless,
        array $headers = [],
        string $version = '1.1',
    ): BodySink {
        $sink = new BodySink($this, $bodiless ? '' : $coding, $status >= 200 && $status < 300);
        if ($this->sink instanceof Pipe && $status >= 200 && $status < 300) {
            $this->sink->open($status, $headers, $sink->decoding, $version);
        }
        return $sink;
    }
}
