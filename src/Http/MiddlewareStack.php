<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Middleware;
use Crab\Contracts\Http\MiddlewareStack as MiddlewareStackContract;
use Crab\Contracts\Http\Request;
use Crab\Contracts\Http\UsesContainer;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Collects middleware in front of a handler and links it into a Pipeline
 * once, on assemble() or on the first request.
 */
final class MiddlewareStack implements MiddlewareStackContract, Handler
{
    /**
     * @var list<Middleware|string>
     */
    private array $middleware = [];
    private ?Pipeline $pipeline = null;

    /**
     * The container makes middleware that is given by name.
     */
    public function __construct(
        private readonly Handler $handler,
        private readonly ?ContainerInterface $container = null,
    ) {}

    public function add(Middleware|string ...$middleware): static
    {
        foreach ($middleware as $layer) {
            if (is_string($layer) && $this->container === null) {
                throw new \LogicException(
                    "Middleware {$layer} is given by name, which needs a stack with a container.",
                );
            }
            $this->middleware[] = $layer;
        }
        // What was linked no longer matches the list.
        $this->pipeline = null;
        return $this;
    }

    public function layers(): array
    {
        return array_map(static fn(Middleware|string $layer): string => is_string($layer)
            ? $layer
            : $layer::class, $this->middleware);
    }

    /**
     * The middleware given by name, which the container makes.
     *
     * @return list<string>
     */
    public function named(): array
    {
        return array_values(array_filter($this->middleware, is_string(...)));
    }

    public function handle(Request $request): ResponseInterface
    {
        return ($this->pipeline ?? $this->assemble())->handle($request);
    }

    /**
     * Checks a declaration without constructing middleware in the parent,
     * so startup can reject settings that cannot serve it across workers.
     *
     * @param class-string<Middleware> $class
     */
    public function has(string $class): bool
    {
        foreach ($this->middleware as $layer) {
            if (is_string($layer) ? $layer === $class : $layer instanceof $class) {
                return true;
            }
        }
        return false;
    }

    /**
     * Makes the named middleware and links the chain now, so that a name
     * that cannot be made fails here and not on a request.
     */
    public function assemble(): Pipeline
    {
        $middleware = [];
        foreach ($this->middleware as $layer) {
            if (is_string($layer)) {
                $name = $layer;
                $layer = $this->container->get($name);
                if (!$layer instanceof Middleware) {
                    throw new \LogicException("{$name} must implement " . Middleware::class . '.');
                }
            }
            if ($layer instanceof UsesContainer && $this->container !== null) {
                // A named one made by autowiring has none of its services yet, like an object on a route.
                $layer = $layer->withContainer($this->container);
            }
            $middleware[] = $layer;
        }
        return $this->pipeline = new Pipeline($this->handler, ...$middleware);
    }
}
