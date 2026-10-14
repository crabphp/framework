<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

use Psr\Http\Server\MiddlewareInterface;

/**
 * Code that runs around a request: process() receives the request and the
 * rest of the chain as $handler, and returns the response. It answers by
 * itself to stop the request there, or calls $handler->handle($request)
 * to let it through, with a changed request or a changed response when it
 * has something to add.
 *
 * It is PSR-15's middleware under Crab's own name. The same class is used
 * around every request, in App::configure()->withMiddleware(), or around
 * some routes, with ->middleware() on a route or a group.
 */
interface Middleware extends MiddlewareInterface
{
}
