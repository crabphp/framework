<?php

declare(strict_types=1);

namespace Crab\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A closure as a PSR-15 handler. It receives the request and returns a
 * PSR-7 response, or a string that is sent as plain text.
 */
final readonly class ClosureHandler implements RequestHandlerInterface
{
    public function __construct(private \Closure $closure)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = ($this->closure)($request);
        return is_string($result) ? Response::text($result) : $result;
    }
}
