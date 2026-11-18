<?php

declare(strict_types=1);

namespace Crab\Routing;

use Crab\Contracts\Routing\Actions as ActionsContract;
use Crab\Contracts\Routing\PreparedAction;
use Crab\Contracts\Validation\Validator;
use Psr\Container\ContainerInterface;

/**
 * Prepares actions for packages with the router's own ActionHandler, so
 * that what an action may take, and how a request that does not fit is
 * refused, is decided in one place.
 */
final readonly class Actions implements ActionsContract
{
    private const array METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * The container makes action classes; the validator builds their inputs.
     */
    public function __construct(
        private Validator $validator,
        private ?ContainerInterface $container = null,
    ) {}

    public function prepare(string $method, string $pattern, \Closure|string|array $action): PreparedAction
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException(
                "An action is prepared for GET, HEAD, POST, PUT, PATCH or DELETE requests, not {$method}.",
            );
        }
        return new ActionHandler(
            $method,
            $pattern,
            Router::names($pattern),
            $action,
            $this->validator,
            $this->container,
        );
    }
}
