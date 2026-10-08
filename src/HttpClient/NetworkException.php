<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The other server could not be reached or did not answer in time: its
 * name was not found, the connection was refused or lost, or the time
 * allowed ran out.
 */
final class NetworkException extends ClientException implements NetworkExceptionInterface
{
    public function __construct(string $message, private readonly RequestInterface $request, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
