<?php

declare(strict_types=1);

namespace Crab\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Answers that the application is up: 200 and {"status":"up"}. It is what
 * a load balancer or a deploy script asks before sending requests here.
 *
 * It says only that this worker takes requests and answers them. It asks
 * nothing of the database or of anything else, so that a store being slow
 * does not take every server out of rotation at once. An application
 * that wants more routes the path to an action of its own.
 */
final readonly class Health implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return Response::json(['status' => 'up']);
    }
}
