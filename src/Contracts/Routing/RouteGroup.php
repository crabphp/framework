<?php

declare(strict_types=1);

namespace Crab\Contracts\Routing;

use Psr\Http\Server\MiddlewareInterface;

/**
 * Routes that share a path prefix, middleware, or both, said a step at a
 * time and declared by group():
 *
 *     Route::prefix('/admin')->middleware(TokenAuth::class)->group(function () {
 *         Route::get('/users', ListUsers::class);     // GET /admin/users, behind TokenAuth
 *     });
 *
 * Each step returns a new group and leaves the one it was called on as it
 * was. Groups may be nested.
 */
interface RouteGroup
{
    /**
     * A path in front of every route's pattern: "/admin", not "/admin/".
     * Given twice, the second follows the first.
     */
    public function prefix(string $prefix): static;

    /**
     * Middleware around every route of the group, outside the routes' own;
     * the first given is outermost.
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface> ...$middleware
     */
    public function middleware(MiddlewareInterface|string ...$middleware): static;

    /**
     * Runs the closure, and what it declares takes the group's prefix and
     * middleware. The closure receives the router.
     *
     * @param \Closure(Router): mixed $routes
     */
    public function group(\Closure $routes): void;
}
