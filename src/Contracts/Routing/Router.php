<?php

declare(strict_types=1);

namespace Crab\Contracts\Routing;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 handler that dispatches to routes, and the way routes are added.
 *
 * A pattern is a path with {name} or {name:expression} parameters.
 *
 * An action is a closure, the name of a class with handle(), or a class
 * and the name of its method, [Users::class, 'show']. It is
 * called with what its signature asks for: the request, the pattern's
 * parameters by name as string, int, float or bool, and the request's
 * data, either as further typed parameters or as one input class, checked
 * before the action runs. What it returns is the response: a PSR-7
 * response, a string as text, nothing as 204, and anything else as JSON.
 *
 * A PSR-15 handler, or the name of a container entry that is one, is also
 * accepted; it receives the request, with the parameters as attributes.
 *
 * Each route is declared in one statement, which returns the route so
 * that it can be named and given middleware of its own, which runs
 * inside that of the groups it is declared in:
 *
 *     $router->get('/orders/{id}', ShowOrder::class)->name('orders.show')->middleware(TokenAuth::class);
 *
 * A name is how code finds the route again, to write its path with
 * path() or a full URL with Urls. Two routes may not share one.
 *
 * @phpstan-type Action RequestHandlerInterface|\Closure|string|array{class-string, string}
 */
interface Router extends RequestHandlerInterface
{
    public function add(
        string $method,
        string $pattern,
        RequestHandlerInterface|\Closure|string|array $action,
    ): DeclaredRoute;

    /**
     * Starts a group of routes under a path prefix; see RouteGroup.
     */
    public function prefix(string $prefix): RouteGroup;

    /**
     * Starts a group of routes behind middleware; see RouteGroup.
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface> ...$middleware
     */
    public function middleware(MiddlewareInterface|string ...$middleware): RouteGroup;

    /**
     * An action taking a WebSocket Socket, the user, pattern parameters
     * and typed query fields; all are resolved before the upgrade.
     */
    public function websocket(string $pattern, \Closure|string|array $action): DeclaredRoute;

    public function get(string $pattern, RequestHandlerInterface|\Closure|string|array $action): DeclaredRoute;

    public function post(string $pattern, RequestHandlerInterface|\Closure|string|array $action): DeclaredRoute;

    public function put(string $pattern, RequestHandlerInterface|\Closure|string|array $action): DeclaredRoute;

    public function patch(string $pattern, RequestHandlerInterface|\Closure|string|array $action): DeclaredRoute;

    public function delete(string $pattern, RequestHandlerInterface|\Closure|string|array $action): DeclaredRoute;

    /**
     * @return list<RouteInfo> Every route, in the order added.
     */
    public function routes(): array;

    /**
     * The route of that name, or null when there is none.
     */
    public function route(string $name): ?RouteInfo;

    /**
     * What a route's action takes, as JSON Schema: "parameters", those of
     * its pattern; "query" or "body", the fields of the request's data; and
     * "user", the class of the logged-in user, with whether one is required.
     * Null when the action is a handler of its own, whose input is not declared.
     *
     * @return array{parameters: array<string, mixed>, query?: array<string, mixed>, body?: array<string, mixed>, user: array{class: string, required: bool}|null}|null
     */
    public function input(RouteInfo $route): ?array;

    /**
     * The path of the named route, with its parameters filled in and
     * encoded: path('orders.show', ['id' => 12]) is "/orders/12". A
     * parameter missing, one the pattern does not have, or a value its
     * expression refuses throws, naming the route.
     *
     * @param array<string, string|int|\Stringable|\BackedEnum> $parameters The pattern's parameters, by name.
     * @param array<string, mixed> $query Added as the query string.
     */
    public function path(string $name, array $parameters = [], array $query = []): string;
}
