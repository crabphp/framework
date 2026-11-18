<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\Errors as ErrorsContract;
use Crab\Contracts\Http\Problem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * ErrorResponse behind the contract a package asks for.
 */
final readonly class Errors implements ErrorsContract
{
    public function make(
        ServerRequestInterface $request,
        int $status,
        string $message = '',
        array $headers = [],
        array $fields = [],
        array $query = [],
        array $path = [],
        array $context = [],
    ): ResponseInterface {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException("An error response has a status from 400 to 599, not {$status}.");
        }
        return ErrorResponse::make($request, $status, $message, $headers, $fields, $query, $path, context: $context);
    }

    public function problem(ResponseInterface $response): ?Problem
    {
        return $response instanceof Response ? $response->problem() : null;
    }
}
