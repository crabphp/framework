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
     * @param string $action A name for the action: "Closure", its class, or "Class::method".
     * @param list<string> $middleware The classes of the middleware around this route, its groups' first; what is around every request is not among them.
     * @param string|null $name The name it was given, to find it by.
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public string $action,
        public array $middleware = [],
        public ?string $name = null,
    ) {
    }
}
