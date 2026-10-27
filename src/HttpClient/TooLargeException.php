<?php

declare(strict_types=1);

namespace Crab\HttpClient;

/**
 * An answer's body was longer than the call allows, by the maxBytes:
 * given to request() or the client's own limit. It was given up as soon
 * as that was known, before it was all read: the connection it came on
 * is closed, and a download's file is not left behind.
 */
final class TooLargeException extends ClientException
{
    /**
     * @param int $limit The most bytes of body that were allowed.
     */
    public function __construct(
        string $message,
        public readonly int $limit,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
