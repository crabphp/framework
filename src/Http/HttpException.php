<?php

declare(strict_types=1);

namespace Crab\Http;

/**
 * Thrown to answer a request with an error status, for example 404 or 403.
 * Its message is shown to the client.
 */
class HttpException extends \RuntimeException
{
    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        public readonly int $status,
        string $message = '',
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException('An HTTP error status is between 400 and 599.');
        }
        parent::__construct($message, 0, $previous);
    }
}
