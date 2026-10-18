<?php

declare(strict_types=1);

namespace Crab\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware in front of a handler, the first middleware outermost.
 * The chain is linked once, so a request passes through it without a
 * dispatcher object of its own.
 */
final readonly class Pipeline implements RequestHandlerInterface
{
    private RequestHandlerInterface $first;

    public function __construct(RequestHandlerInterface $handler, MiddlewareInterface ...$middleware)
    {
        foreach (array_reverse($middleware) as $layer) {
            $handler = new class($layer, $handler) implements RequestHandlerInterface {
                public function __construct(
                    private readonly MiddlewareInterface $middleware,
                    private readonly RequestHandlerInterface $next,
                ) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }
        $this->first = $handler;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->first->handle($request);
    }
}
