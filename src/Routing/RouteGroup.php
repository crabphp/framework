<?php

declare(strict_types=1);

namespace Crab\Routing;

use Crab\Contracts\Routing\RouteGroup as RouteGroupContract;
use Psr\Http\Server\MiddlewareInterface;

/**
 * A group being described. It holds what was said and hands it to the
 * router, which alone declares routes, when group() is called.
 */
final readonly class RouteGroup implements RouteGroupContract
{
    /**
     * @param \Closure(string, list<MiddlewareInterface|string>, \Closure): void $declare
     * @param list<MiddlewareInterface|string> $middleware
     */
    public function __construct(
        private \Closure $declare,
        private string $prefix = '',
        private array $middleware = [],
    ) {}

    public function prefix(string $prefix): static
    {
        if ($prefix === '' || $prefix[0] !== '/' || str_ends_with($prefix, '/')) {
            throw new \InvalidArgumentException(
                "A group's prefix starts with \"/\" and does not end with one: \"/admin\", not \"{$prefix}\".",
            );
        }
        return new static($this->declare, $this->prefix . $prefix, $this->middleware);
    }

    public function middleware(MiddlewareInterface|string ...$middleware): static
    {
        return new static($this->declare, $this->prefix, [...$this->middleware, ...array_values($middleware)]);
    }

    public function group(\Closure $routes): void
    {
        ($this->declare)($this->prefix, $this->middleware, $routes);
    }
}
