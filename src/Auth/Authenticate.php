<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\Authenticator;
use Crab\Contracts\Auth\Identity;
use Crab\Http\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets a request through only when its authenticator knows who makes it,
 * and passes that user on as the request's "user" attribute. Any other
 * request is answered with 401.
 *
 * Put it around the routes that need a user. Given by class name, it
 * uses the application's default authenticator; made with `new`, any
 * other.
 */
final readonly class Authenticate implements MiddlewareInterface
{
    public function __construct(private Authenticator $authenticator)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $this->authenticator->authenticate($request);
        if ($user === null) {
            return ErrorResponse::make($request, 401, 'Authentication is required.', ['WWW-Authenticate' => $this->authenticator->challenge()]);
        }
        return $handler->handle($request->withAttribute(Identity::ATTRIBUTE, $user));
    }
}
