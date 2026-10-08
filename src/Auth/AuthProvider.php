<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\PasswordHasher as PasswordHasherContract;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Provider;

/**
 * Binds what every way of authenticating shares: the password hasher.
 *
 * How a request says who makes it is not decided here. It is middleware:
 * whatever puts an Identity on the request's "user" attribute. Crab ships
 * one, tokens, behind TokenAuthProvider; an application with another way
 * writes its own and registers neither more nor less than this.
 */
final class AuthProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->singleton(PasswordHasherContract::class, static fn (): PasswordHasher => new PasswordHasher());
    }
}
