<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\PasswordHasher as PasswordHasherContract;
use Crab\Contracts\Auth\Tickets as TicketsContract;
use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Provider;

/**
 * Binds the password hasher and short-lived browser socket tickets.
 *
 * How a request says who makes it is not decided here. It is middleware:
 * whatever puts an Identity on the request's "user" attribute. Crab ships
 * tokens and their ticket fallback behind TokenAuthProvider; another way
 * writes its own and registers neither more nor less than this.
 */
final class AuthProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults('auth', ['ticket_ttl' => 30]);
        $container->singleton(PasswordHasherContract::class, static fn(): PasswordHasher => new PasswordHasher());
        $container->singleton(TicketsContract::class, static function (Container $container): Tickets {
            if (!$container->has(Cache::class) || !$container->has(IdentityResolver::class)) {
                throw new \LogicException(
                    'Tickets need a cache and a user resolver. Register CacheProvider and bind '
                    . IdentityResolver::class
                    . ' in your application provider.',
                );
            }
            $ttl = $container->get(Config::class)->get('auth.ticket_ttl');
            if (!is_int($ttl) || $ttl < 1) {
                throw new \InvalidArgumentException(
                    'Set auth.ticket_ttl to a whole number of seconds greater than zero.',
                );
            }
            return new Tickets($container->get(Cache::class), $container->get(IdentityResolver::class), $ttl);
        });
        $container->singleton(Tickets::class, TicketsContract::class);
    }
}
