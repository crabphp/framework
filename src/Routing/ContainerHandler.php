<?php

declare(strict_types=1);

namespace Crab\Routing;

use Crab\Contracts\Http\Handler;
use Crab\Contracts\Http\Request;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A route handler named by its container entry, fetched when a request
 * arrives so that it is made in the worker that serves it.
 */
final readonly class ContainerHandler implements Handler
{
    public function __construct(
        private ContainerInterface $container,
        public string $id,
    ) {}

    public function handle(Request $request): ResponseInterface
    {
        $handler = $this->container->get($this->id);
        if (!$handler instanceof Handler) {
            throw new \LogicException("Route handler {$this->id} must implement " . Handler::class . '.');
        }
        return $handler->handle($request);
    }
}
