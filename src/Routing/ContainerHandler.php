<?php

declare(strict_types=1);

namespace Crab\Routing;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A route handler named by its container entry, fetched when a request
 * arrives so that it is made in the worker that serves it.
 */
final readonly class ContainerHandler implements RequestHandlerInterface
{
    public function __construct(
        private ContainerInterface $container,
        public string $id,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handler = $this->container->get($this->id);
        if (!$handler instanceof RequestHandlerInterface) {
            throw new \LogicException("Route handler {$this->id} must implement RequestHandlerInterface.");
        }
        return $handler->handle($request);
    }
}
