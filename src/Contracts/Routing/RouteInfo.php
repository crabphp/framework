<?php

declare(strict_types=1);

namespace Crab\Contracts\Routing;

/**
 * One route as it was declared: where it answers, and what its handler's
 * signature says goes in and comes out. This is what the route list and
 * the OpenAPI document are written from.
 */
final readonly class RouteInfo
{
    /**
     * @param string $handler A name for the handler: "Closure", its class, or "Class::method".
     * @param array<string, string> $parameters The pattern's parameters and their types: string, int, float or bool.
     * @param class-string|null $input The class the handler receives the request's data as, when it takes one.
     * @param array<string, mixed>|null $fields The JSON Schema of the request's data when the handler takes it as separate parameters.
     * @param string|null $inputFrom Where that data is read from, "query" or "body"; null when the handler takes none.
     * @param string|null $output What the handler declares it returns: a class, a built-in type, or null when it does not say.
     * @param bool $outputList Whether the handler returns a list of $output and not one.
     */
    public function __construct(
        public string $method,
        public string $pattern,
        public string $handler,
        public array $parameters = [],
        public ?string $input = null,
        public ?array $fields = null,
        public ?string $inputFrom = null,
        public ?string $output = null,
        public bool $outputList = false,
    ) {
    }
}
