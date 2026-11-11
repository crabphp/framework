<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Code that runs around a request: handle() receives the request and the
 * rest of the chain as $next, and returns the response. It answers by
 * itself to stop the request there, or calls $next->handle($request) to
 * let it through, with a changed request or a changed response when it
 * has something to add.
 *
 *     public function handle(Request $request, Handler $next): ResponseInterface
 *     {
 *         if ($request->user(User::class)?->isAdmin() !== true) {
 *             throw new HttpException(403, 'This is for administrators.');
 *         }
 *         return $next->handle($request);
 *     }
 *
 * It is Crab's own, not PSR-15's, so that the request is Crab's Request.
 * The same class is used around every request, in
 * App::configure()->withMiddleware(), or around some routes, with
 * ->middleware() on a route or a group.
 */
interface Middleware
{
    public function handle(Request $request, Handler $next): ResponseInterface;
}
