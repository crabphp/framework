<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\Authenticator;
use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\TokenStore;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads a token from "Authorization: Bearer <token>".
 */
final readonly class BearerToken implements Authenticator
{
    public function __construct(private TokenStore $tokens, private IdentityResolver $users)
    {
    }

    public function authenticate(ServerRequestInterface $request): ?Identity
    {
        $header = $request->getHeaderLine('Authorization');
        if (strncasecmp($header, 'Bearer ', 7) !== 0) {
            return null;
        }
        $token = trim(substr($header, 7));
        if ($token === '') {
            return null;
        }
        $owner = $this->tokens->owner($token);
        return $owner === null ? null : $this->users->resolve($owner);
    }

    public function challenge(): string
    {
        return 'Bearer';
    }
}
