<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Answers a request: the application, the router, an action's handler, and
 * the rest of the chain that middleware is given as $next.
 *
 * It is Crab's own, not PSR-15's, so that what it is given is Crab's
 * Request, with its user(), ip() and the rest.
 */
interface Handler
{
    public function handle(Request $request): ResponseInterface;
}
