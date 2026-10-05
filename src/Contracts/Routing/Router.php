<?php

declare(strict_types=1);

namespace Crab\Contracts\Routing;

use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 handler that dispatches to routes, and the way routes are added.
 *
 * A pattern is a path with {name} or {name:expression} parameters.
 *
 * A handler is a closure, the name of a class with __invoke(), or a class
 * and the name of its method, [UserController::class, 'show']. It is
 * called with what its signature asks for: the request, the pattern's
 * parameters by name as string, int, float or bool, and the request's
 * data, either as further typed parameters or as one input class, checked
 * before the handler runs. What it returns is the response: a PSR-7
 * response, a string as text, nothing as 204, and anything else as JSON.
 *
 * A PSR-15 handler, or the name of a container entry that is one, is also
 * accepted; it receives the request, with the parameters as attributes.
 *
 * @phpstan-type Handler RequestHandlerInterface|\Closure|string|array{class-string, string}
 */
interface Router extends RequestHandlerInterface
{
    public function add(string $method, string $pattern, RequestHandlerInterface|\Closure|string|array $handler): static;

    public function get(string $pattern, RequestHandlerInterface|\Closure|string|array $handler): static;

    public function post(string $pattern, RequestHandlerInterface|\Closure|string|array $handler): static;

    public function put(string $pattern, RequestHandlerInterface|\Closure|string|array $handler): static;

    public function patch(string $pattern, RequestHandlerInterface|\Closure|string|array $handler): static;

    public function delete(string $pattern, RequestHandlerInterface|\Closure|string|array $handler): static;

    /**
     * @return list<RouteInfo> Every route, in the order added.
     */
    public function routes(): array;
}
