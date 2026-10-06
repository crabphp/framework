<?php

declare(strict_types=1);

namespace Crab\Contracts\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * One way of telling who makes a request: a bearer token, an API key, a
 * session cookie, a signed address. Implement it to add a way of your
 * own, and give it to the Authenticate middleware.
 */
interface Authenticator
{
    /**
     * @return Identity|null Who the request is made by, or null when it does not say, or says so wrongly.
     */
    public function authenticate(ServerRequestInterface $request): ?Identity;

    /**
     * What a request that was refused is told to send, as the value of
     * the WWW-Authenticate header: "Bearer", for example.
     */
    public function challenge(): string;
}
