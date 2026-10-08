<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Crab\Contracts\HttpClient\Response as ResponseContract;

/**
 * An answer whose status is not a success, thrown by Response::throw().
 * The answer itself is in $response.
 */
final class ResponseException extends ClientException
{
    public function __construct(public readonly ResponseContract $response, string $request)
    {
        $what = $request === '' ? 'The request' : $request;
        parent::__construct("{$what} was answered with {$response->getStatusCode()} {$response->getReasonPhrase()}.", $response->getStatusCode());
    }
}
