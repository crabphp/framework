<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

use Psr\Http\Server\MiddlewareInterface;

/**
 * The PSR-15 middleware around every route, and the way a provider adds to
 * it.
 */
interface MiddlewareStack
{
    /**
     * Adds middleware inside what is already there, so the first added is
     * outermost. One given by name is made by the container in each worker
     * as the worker starts.
     */
    public function add(MiddlewareInterface|string ...$middleware): static;

    /**
     * The class of each middleware, outermost first.
     *
     * @return list<string>
     */
    public function layers(): array;
}
