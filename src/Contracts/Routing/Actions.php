<?php

declare(strict_types=1);

namespace Crab\Contracts\Routing;

/**
 * Prepares an action the way the router does for a route, for a package
 * that calls actions of its own: a page made of several loaders, a
 * registry of handlers. What the action takes is read from its signature
 * by the same rules, once, when it is prepared; the package is given what
 * the action returned instead of a response, and decides what to send.
 *
 *     $prepared = $actions->prepare('GET', '/posts/{id}', [EditPost::class, 'load']);
 *     $outcome = $prepared->invoke($request);
 *
 * Nothing is added to the router: the package adds the route that calls
 * it, with a Handler of its own.
 */
interface Actions
{
    /**
     * Reads the action's signature against the method and pattern, as
     * adding a route does, and throws a LogicException naming what cannot
     * be honoured.
     *
     * @param string $method The method of the requests it will be invoked for: GET, POST, PUT, PATCH or DELETE.
     * @param string $pattern The pattern whose parameters the request carries as attributes.
     * @param \Closure|class-string|array{class-string, string} $action A closure, a class with handle(), or a class and the name of its method.
     */
    public function prepare(string $method, string $pattern, \Closure|string|array $action): PreparedAction;
}
