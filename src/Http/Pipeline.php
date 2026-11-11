<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * Middleware in front of a handler, the first middleware outermost.
 * The chain is linked once, so a request passes through it without a
 * dispatcher object of its own.
 */
final readonly class Pipeline implements Handler
{
    private Handler $first;

    public function __construct(Handler $handler, Middleware ...$middleware)
    {
        foreach (array_reverse($middleware) as $layer) {
            $handler = new class($layer, $handler) implements Handler {
                public function __construct(
                    private readonly Middleware $middleware,
                    private readonly Handler $next,
                ) {}

                public function handle(Request $request): ResponseInterface
                {
                    return $this->middleware->handle($request, $this->next);
                }
            };
        }
        $this->first = $handler;
    }

    public function handle(Request $request): ResponseInterface
    {
        return $this->first->handle($request);
    }
}
