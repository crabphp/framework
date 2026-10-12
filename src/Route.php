<?php

declare(strict_types=1);

namespace Crab;

use Crab\Contracts\Routing\Router;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Declares routes on the current application's router, for route files:
 *
 *     Route::get('/users/{id}', [UserController::class, 'show']);
 *     Route::get('/me', [UserController::class, 'me'], middleware: [TokenAuth::class]);
 *
 * It is $app->router under a shorter name and nothing more; see the
 * Router contract for what a pattern and an action are. Routes are
 * declared once, before any worker starts, which is why a static name for
 * them is safe where one for the request would not be.
 */
final class Route
{
    /**
     * An action with handle(Socket $socket, ...) that runs after its HTTP
     * handshake has passed through every middleware.
     */
    public static function websocket(string $pattern, \Closure|string|array $action, array $middleware = []): Router
    {
        return App::current()->router->websocket($pattern, $action, $middleware);
    }

    /**
     * @param RequestHandlerInterface|\Closure|string|array{class-string, string} $action
     * @param list<\Psr\Http\Server\MiddlewareInterface|class-string<\Psr\Http\Server\MiddlewareInterface>> $middleware Around this route alone.
     */
    public static function get(string $pattern, RequestHandlerInterface|\Closure|string|array $action, array $middleware = []): Router
    {
        return App::current()->router->get($pattern, $action, $middleware);
    }

    /**
     * @param RequestHandlerInterface|\Closure|string|array{class-string, string} $action
     * @param list<\Psr\Http\Server\MiddlewareInterface|class-string<\Psr\Http\Server\MiddlewareInterface>> $middleware Around this route alone.
     */
    public static function post(string $pattern, RequestHandlerInterface|\Closure|string|array $action, array $middleware = []): Router
    {
        return App::current()->router->post($pattern, $action, $middleware);
    }

    /**
     * @param RequestHandlerInterface|\Closure|string|array{class-string, string} $action
     * @param list<\Psr\Http\Server\MiddlewareInterface|class-string<\Psr\Http\Server\MiddlewareInterface>> $middleware Around this route alone.
     */
    public static function put(string $pattern, RequestHandlerInterface|\Closure|string|array $action, array $middleware = []): Router
    {
        return App::current()->router->put($pattern, $action, $middleware);
    }

    /**
     * @param RequestHandlerInterface|\Closure|string|array{class-string, string} $action
     * @param list<\Psr\Http\Server\MiddlewareInterface|class-string<\Psr\Http\Server\MiddlewareInterface>> $middleware Around this route alone.
     */
    public static function patch(string $pattern, RequestHandlerInterface|\Closure|string|array $action, array $middleware = []): Router
    {
        return App::current()->router->patch($pattern, $action, $middleware);
    }

    /**
     * @param RequestHandlerInterface|\Closure|string|array{class-string, string} $action
     * @param list<\Psr\Http\Server\MiddlewareInterface|class-string<\Psr\Http\Server\MiddlewareInterface>> $middleware Around this route alone.
     */
    public static function delete(string $pattern, RequestHandlerInterface|\Closure|string|array $action, array $middleware = []): Router
    {
        return App::current()->router->delete($pattern, $action, $middleware);
    }

    /**
     * Routes that share a path prefix, middleware, or both:
     *
     *     Route::group(prefix: '/admin', middleware: [TokenAuth::class], routes: function () {
     *         Route::get('/users', [AdminController::class, 'users']);
     *     });
     *
     * @param \Closure(Router): mixed $routes
     * @param list<\Psr\Http\Server\MiddlewareInterface|class-string<\Psr\Http\Server\MiddlewareInterface>> $middleware
     */
    public static function group(\Closure $routes, string $prefix = '', array $middleware = []): Router
    {
        return App::current()->router->group($routes, $prefix, $middleware);
    }

    /**
     * A route for any other method, such as OPTIONS.
     *
     * @param RequestHandlerInterface|\Closure|string|array{class-string, string} $action
     * @param list<\Psr\Http\Server\MiddlewareInterface|class-string<\Psr\Http\Server\MiddlewareInterface>> $middleware Around this route alone.
     */
    public static function add(string $method, string $pattern, RequestHandlerInterface|\Closure|string|array $action, array $middleware = []): Router
    {
        return App::current()->router->add($method, $pattern, $action, $middleware);
    }
}
