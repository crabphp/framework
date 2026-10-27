<?php

declare(strict_types=1);

namespace Crab\HttpClient;

/**
 * One request and its answer on an HTTP/2 connection, as far as they
 * have come.
 *
 * @internal
 */
final class Http2Stream
{
    public ?int $status = null;
    /**
     * @var array<string, list<string>>
     */
    public array $headers = [];
    public ?BodySink $body = null;
    /**
     * Whether the answer is all here.
     */
    public bool $done = false;
    /**
     * Why there will be no answer.
     */
    public ?\Throwable $error = null;
    /**
     * Whether the server has sent anything in answer.
     */
    public bool $answered = false;
    /**
     * Bytes of body received that the server has not yet been told it may send again.
     */
    public int $unacknowledged = 0;
    /**
     * Resumes whoever waits for this stream to change.
     */
    public ?\Closure $wake = null;

    /**
     * @param int $window How many bytes of body the server will take on this stream for now.
     * @param bool $bodiless Whether the answer has no body whatever its headers say, as that of a HEAD request.
     */
    public function __construct(
        public readonly int $id,
        public int $window,
        public readonly bool $bodiless,
        public readonly Receiving $receiving,
    ) {}
}
