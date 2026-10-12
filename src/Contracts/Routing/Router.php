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
 * A route's middleware: runs around that route alone, inside the
 * middleware of the groups it is declared in. One given by class name is
 * made by the container in each worker as the worker starts.
 *
 * @phpstan-type Action RequestHandlerInterface|\Closure|string|array{class-string, string}
 */
interface Router extends RequestHandlerInterface
{
    public function add(string $method, string $pattern, RequestHandlerInterface|\Closure|string|array $action, array $middleware = []): static;

    /**
     * Routes that share a path prefix, middleware, or both. The closure
     * declares them; what it adds gets the prefix in front of its pattern
     * and the middleware around its action, the first given outermost:
     *
     *     $router->group(prefix: '/admin', middleware: [TokenAuth::class], routes: function () {
     *         Route::get('/users', [AdminController::class, 'users']);     // GET /admin/users
     *     });
     *
     * Groups may be nested; the closure receives the router.
     *
     * @param \Closure(static): mixed $routes
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware PSR-15 middleware; one given by class name is made by the container.
     */
    public function group(\Closure $routes, string $prefix = '', array $middleware = []): static;

    /**
     * An action taking a WebSocket Socket, the user, pattern parameters
     * and typed query fields; all are resolved before the upgrade.
     */
    public function websocket(string $pattern, \Closure|string|array $action, array $middleware = []): static;

    public function get(string $pattern, RequestHandlerInterface|\Closure|string|array $action, array $middleware = []): static;

    public function post(string $pattern, RequestHandlerInterface|\Closure|string|array $action, array $middleware = []): static;

    public function put(string $pattern, RequestHandlerInterface|\Closure|string|array $action, array $middleware = []): static;

    public function patch(string $pattern, RequestHandlerInterface|\Closure|string|array $action, array $middleware = []): static;

    public function delete(string $pattern, RequestHandlerInterface|\Closure|string|array $action, array $middleware = []): static;

    /**
     * @return list<RouteInfo> Every route, in the order added.
     */
    public function routes(): array;
}
