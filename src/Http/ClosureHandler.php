<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * A closure as a Handler. It receives the request and returns a
 * PSR-7 response, or a string that is sent as plain text.
 */
final readonly class ClosureHandler implements Handler
{
    public function __construct(
        private \Closure $closure,
    ) {}

    public function handle(Request $request): ResponseInterface
    {
        $result = ($this->closure)($request);
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        if (is_string($result)) {
            return Response::text($result);
        }
        throw new \LogicException(
            'A handler closure must return a response or a string; it returned ' . get_debug_type($result) . '.',
        );
    }
}
