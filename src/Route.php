<?php

declare(strict_types=1);

namespace Crab;

use Crab\Contracts\Http\Handler;
use Crab\Contracts\Routing\DeclaredRoute;
use Crab\Contracts\Routing\RouteGroup;

/**
 * Declares routes on the current application's router, for route files:
 *
 *     Route::get('/users/{id}', [UserController::class, 'show']);
 *     Route::get('/me', [UserController::class, 'me'])->middleware(TokenAuth::class);
 *     Route::get('/orders/{id}', ShowOrder::class)->name('orders.show');
 *
 * Each declares one route and returns it, to be named and given
 * middleware on the same line. It is $app->router under a shorter name
 * and nothing more; see the Router contract for what a pattern and an
 * action are. Routes are
 * declared once, before any worker starts, which is why a static name for
 * them is safe where one for the request would not be.
 */
final class Route
{
    /**
     * An action with handle(Socket $socket, ...) that runs after its HTTP
     * handshake has passed through every middleware.
     */
    public static function websocket(string $pattern, \Closure|string|array $action): DeclaredRoute
    {
        return App::current()->router->websocket($pattern, $action);
    }

    /**
     * @param Handler|\Closure|string|array{class-string, string} $action
     */
    public static function get(string $pattern, Handler|\Closure|string|array $action): DeclaredRoute
    {
        return App::current()->router->get($pattern, $action);
    }

    /**
     * @param Handler|\Closure|string|array{class-string, string} $action
     */
    public static function post(string $pattern, Handler|\Closure|string|array $action): DeclaredRoute
    {
        return App::current()->router->post($pattern, $action);
    }

    /**
     * @param Handler|\Closure|string|array{class-string, string} $action
     */
    public static function put(string $pattern, Handler|\Closure|string|array $action): DeclaredRoute
    {
        return App::current()->router->put($pattern, $action);
    }

    /**
     * @param Handler|\Closure|string|array{class-string, string} $action
     */
    public static function patch(string $pattern, Handler|\Closure|string|array $action): DeclaredRoute
    {
        return App::current()->router->patch($pattern, $action);
    }

    /**
     * @param Handler|\Closure|string|array{class-string, string} $action
     */
    public static function delete(string $pattern, Handler|\Closure|string|array $action): DeclaredRoute
    {
        return App::current()->router->delete($pattern, $action);
    }

    /**
     * Starts a group of routes under a path prefix:
     *
     *     Route::prefix('/admin')->middleware(TokenAuth::class)->group(function () {
     *         Route::get('/users', ListUsers::class);
     *     });
     */
    public static function prefix(string $prefix): RouteGroup
    {
        return App::current()->router->prefix($prefix);
    }

    /**
     * Starts a group of routes behind middleware.
     *
     * @param \Crab\Contracts\Http\Middleware|class-string<\Crab\Contracts\Http\Middleware> ...$middleware
     */
    public static function middleware(\Crab\Contracts\Http\Middleware|string ...$middleware): RouteGroup
    {
        return App::current()->router->middleware(...$middleware);
    }

    /**
     * A route for any other method, such as OPTIONS.
     *
     * @param Handler|\Closure|string|array{class-string, string} $action
     */
    public static function add(string $method, string $pattern, Handler|\Closure|string|array $action): DeclaredRoute
    {
        return App::current()->router->add($method, $pattern, $action);
    }
}
