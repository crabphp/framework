<?php

declare(strict_types=1);

namespace Crab\Contracts\Routing;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 handler that dispatches to routes, and the way routes are added.
 *
 * A pattern is a path with {name} or {name:expression} parameters, which
 * reach the route's handler as request attributes. A handler is a PSR-15
 * handler, a closure, or the name of a container entry that is one.
 */
interface Router extends RequestHandlerInterface
{
    public function add(string $method, string $pattern, RequestHandlerInterface|\Closure|string $handler): static;

    public function get(string $pattern, RequestHandlerInterface|\Closure|string $handler): static;

    public function post(string $pattern, RequestHandlerInterface|\Closure|string $handler): static;

    public function put(string $pattern, RequestHandlerInterface|\Closure|string $handler): static;

    public function patch(string $pattern, RequestHandlerInterface|\Closure|string $handler): static;

    public function delete(string $pattern, RequestHandlerInterface|\Closure|string $handler): static;

    /**
     * @return list<array{string, string, string}> Method, pattern and handler name of each route, in the order added.
     */
    public function routes(): array;
}
