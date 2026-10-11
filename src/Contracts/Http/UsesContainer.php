<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

use Psr\Container\ContainerInterface;

/**
 * Middleware that is written with options, as an object, and needs a
 * service as well:
 *
 *     Route::post('/login', Login::class, middleware: [new RateLimit(5, per: 60)]);
 *
 * The options are all `new` can give it, where no container is at hand.
 * So before the first request it is asked for itself with what it needs
 * taken from the container, and that is the middleware that runs.
 */
interface UsesContainer
{
    public function withContainer(ContainerInterface $container): static;
}
