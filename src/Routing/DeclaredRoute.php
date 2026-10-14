<?php

declare(strict_types=1);

namespace Crab\Routing;

use Crab\Contracts\Routing\DeclaredRoute as DeclaredRouteContract;
use Psr\Http\Server\MiddlewareInterface;

/**
 * A route the router has just added. It hands what is said about the
 * route back to the router, which alone holds the route.
 */
final readonly class DeclaredRoute implements DeclaredRouteContract
{
    /**
     * @param \Closure(string): void $name
     * @param \Closure(list<MiddlewareInterface|string>): void $middleware
     */
    public function __construct(private \Closure $name, private \Closure $middleware)
    {
    }

    public function name(string $name): static
    {
        ($this->name)($name);
        return $this;
    }

    public function middleware(MiddlewareInterface|string ...$middleware): static
    {
        ($this->middleware)(array_values($middleware));
        return $this;
    }
}
