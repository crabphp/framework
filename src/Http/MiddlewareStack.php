<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\MiddlewareStack as MiddlewareStackContract;
use Crab\Contracts\Http\UsesContainer;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Collects middleware in front of a handler and links it into a Pipeline
 * once, on assemble() or on the first request.
 */
final class MiddlewareStack implements MiddlewareStackContract, RequestHandlerInterface
{
    /**
     * @var list<MiddlewareInterface|string>
     */
    private array $middleware = [];
    private ?Pipeline $pipeline = null;

    /**
     * The container makes middleware that is given by name.
     */
    public function __construct(
        private readonly RequestHandlerInterface $handler,
        private readonly ?ContainerInterface $container = null,
    ) {}

    public function add(MiddlewareInterface|string ...$middleware): static
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
        return array_map(static fn(MiddlewareInterface|string $layer): string => is_string($layer)
            ? $layer
            : $layer::class, $this->middleware);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->pipeline ?? $this->assemble())->handle($request);
    }

    /**
     * Checks a declaration without constructing middleware in the parent,
     * so startup can reject settings that cannot serve it across workers.
     *
     * @param class-string<MiddlewareInterface> $class
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
                if (!$layer instanceof MiddlewareInterface) {
                    throw new \LogicException("{$name} must implement MiddlewareInterface.");
                }
            } elseif ($layer instanceof UsesContainer && $this->container !== null) {
                $layer = $layer->withContainer($this->container);
            }
            $middleware[] = $layer;
        }
        return $this->pipeline = new Pipeline($this->handler, ...$middleware);
    }
}
