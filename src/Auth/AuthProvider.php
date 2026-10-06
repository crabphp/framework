<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\Authenticator;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\PasswordHasher as PasswordHasherContract;
use Crab\Contracts\Auth\TokenStore;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Database\Migrator;
use Crab\Contracts\Provider;
use Psr\Clock\ClockInterface;

/**
 * Binds authentication: tokens kept in the database, the password hasher,
 * and bearer tokens as the default way a request says who makes it. It
 * adds the migration for the tokens table, so register it after the
 * database provider.
 *
 * The application binds Contracts\Auth\IdentityResolver itself, to say
 * how a user is found by id. Bind Contracts\Auth\Authenticator after this
 * provider to make another way the default.
 */
final class AuthProvider implements Provider
{
    public function register(Container $container): void
    {
        if (!$container->has(Migrator::class)) {
            throw new \LogicException('AuthProvider keeps tokens in the database: register the database provider, DatabaseProvider, before it.');
        }
        $container->get(Migrator::class)->path(__DIR__ . '/migrations');
        $container->singleton(TokenStore::class, static fn (Container $container): DatabaseTokenStore => new DatabaseTokenStore(
            $container->get(Database::class),
            clock: $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null,
        ));
        $container->singleton(PasswordHasherContract::class, static fn (): PasswordHasher => new PasswordHasher());
        $container->singleton(Authenticator::class, static function (Container $container): BearerToken {
            if (!$container->has(IdentityResolver::class)) {
                throw new \LogicException('To authenticate requests, bind ' . IdentityResolver::class . ' in a provider of your own: it finds one of your users by id.');
            }
            return new BearerToken($container->get(TokenStore::class), $container->get(IdentityResolver::class));
        });
        $container->singleton(Authenticate::class, static fn (Container $container): Authenticate => new Authenticate($container->get(Authenticator::class)));
    }
}
