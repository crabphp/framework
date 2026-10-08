<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Auth\Commands\InstallTokens;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\TokenStore;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Provider;
use Psr\Clock\ClockInterface;

/**
 * Binds authentication by token: tokens kept in the database, the
 * TokenAuth middleware reading "Authorization: Bearer", and the
 * `tokens:install` command, which writes the migration for the tokens
 * table into the application's migrations. Register it for an application
 * that authenticates this way, together with the database provider.
 *
 * The application binds Contracts\Auth\IdentityResolver itself, to say
 * how one of its users is found by the id a token belongs to.
 */
final class TokenAuthProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->singleton(TokenStore::class, static function (Container $container): DatabaseTokenStore {
            if (!$container->has(Database::class)) {
                throw new \LogicException('Tokens are kept in the database: register the database provider, DatabaseProvider, as well as TokenAuthProvider.');
            }
            return new DatabaseTokenStore(
                $container->get(Database::class),
                clock: $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null,
            );
        });
        $container->singleton(TokenAuth::class, static function (Container $container): TokenAuth {
            if (!$container->has(IdentityResolver::class)) {
                throw new \LogicException('To authenticate by token, bind ' . IdentityResolver::class . ' in a provider of your own: it finds one of your users by id.');
            }
            return new TokenAuth($container->get(TokenStore::class), $container->get(IdentityResolver::class));
        });
        if ($container->has(Console::class)) {
            $container->get(Console::class)->add('tokens:install', InstallTokens::class);
        }
    }
}
