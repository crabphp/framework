<?php

declare(strict_types=1);

namespace Crab\Contracts\Routing;

/**
 * One route as it was declared: where it answers, what answers it and
 * what runs around it. This is what the route list is written from.
 */
final readonly class RouteInfo
{
    /**
     * @param string $handler A name for the handler: "Closure", its class, or "Class::method".
     * @param list<string> $middleware The classes of the middleware around this route, its groups' first; what is around every request is not among them.
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public string $handler,
        public array $middleware = [],
    ) {
    }
}
