<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\Authenticator;
use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\TokenStore;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reads a token from a header of its own, "X-Api-Key" unless another is
 * named, as callers that are programs rather than people often send it.
 */
final readonly class ApiKey implements Authenticator
{
    public function __construct(
        private TokenStore $tokens,
        private IdentityResolver $users,
        private string $header = 'X-Api-Key',
    ) {
    }

    public function authenticate(ServerRequestInterface $request): ?Identity
    {
        $key = trim($request->getHeaderLine($this->header));
        if ($key === '') {
            return null;
        }
        $owner = $this->tokens->owner($key);
        return $owner === null ? null : $this->users->resolve($owner);
    }

    public function challenge(): string
    {
        return 'ApiKey header="' . $this->header . '"';
    }
}
