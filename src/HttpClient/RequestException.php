<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The request itself cannot be sent, wherever it is sent to: its URL has
 * no host, or a scheme other than http and https.
 */
final class RequestException extends ClientException implements RequestExceptionInterface
{
    public function __construct(
        string $message,
        private readonly RequestInterface $request,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
