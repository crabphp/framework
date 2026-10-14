<?php

declare(strict_types=1);

namespace Crab\Contracts\Routing;

use Psr\Http\Server\MiddlewareInterface;

/**
 * The route a declaration made, to say more about it on the same line:
 *
 *     Route::get('/orders/{id}', ShowOrder::class)->name('orders.show')->middleware(TokenAuth::class);
 *
 * It is said while routes are declared, before any worker starts.
 */
interface DeclaredRoute
{
    /**
     * How code finds the route again: Router::path(), Urls::to(). A route
     * has one name, and no two routes share one.
     */
    public function name(string $name): static;

    /**
     * Middleware around this route alone, inside its groups'. Called
     * again, it adds inside what is there. One given by class
     * name is made by the container in each worker as the worker starts.
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface> ...$middleware
     */
    public function middleware(MiddlewareInterface|string ...$middleware): static;
}
