<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Auth\Commands\InstallTokens;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\TokenStore;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Plugin;
use Psr\Clock\ClockInterface;

/**
 * Binds authentication by token: tokens kept in the database, the
 * TokenAuth middleware reading "Authorization: Bearer", and the
 * `token:install` command, which writes the migration for the tokens
 * table into the application's migrations. Register it for an application
 * that authenticates this way, together with the database plugin.
 *
 * The application binds Contracts\Auth\IdentityResolver itself, to say
 * how one of its users is found by the id a token belongs to.
 */
final class TokenAuthPlugin implements Plugin
{
    public function register(Container $container): void
    {
        $container->singleton(TokenStore::class, static function (Container $container): DatabaseTokenStore {
            if (!$container->has(Database::class)) {
                throw new \LogicException(
                    'Tokens are kept in the database: register the database plugin, DatabasePlugin, as well as TokenAuthPlugin.',
                );
            }
            return new DatabaseTokenStore(
                $container->get(Database::class),
                clock: $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null,
            );
        });
        $container->singleton(DatabaseTokenStore::class, TokenStore::class);
        $container->singleton(TokenAuth::class, static function (Container $container): TokenAuth {
            if (!$container->has(IdentityResolver::class)) {
                throw new \LogicException(
                    'To authenticate by token, bind '
                    . IdentityResolver::class
                    . ' in a plugin of your own: it finds one of your users by id.',
                );
            }
            return new TokenAuth($container->get(TokenStore::class), $container->get(IdentityResolver::class));
        });
        $container->singleton(CookieAuth::class, static function (Container $container): CookieAuth {
            $config = $container->get(Config::class);
            $config->check('auth');
            $cookie = (array) $config->get('auth.cookie', []);
            $url = $config->get('app.url');
            $origins = array_values((array) ($cookie['origins'] ?? []));
            if (is_string($url) && $url !== '') {
                $origins[] = $url;
            }
            return new CookieAuth(
                login: $cookie['login'] ?? null,
                tokens: $container->get(TokenStore::class),
                users: $container->has(IdentityResolver::class)
                    ? $container->get(IdentityResolver::class)
                    : throw new \LogicException(
                        'To authenticate by cookie, bind '
                        . IdentityResolver::class
                        . ' in a plugin of your own: it finds one of your users by id.',
                    ),
                name: (string) ($cookie['name'] ?? 'auth'),
                origins: $origins,
                // Secure unless the application says it is served over plain HTTP, as while developing.
                secure: $cookie['secure'] ?? !(is_string($url) && str_starts_with(strtolower($url), 'http://')),
                maxAge: $cookie['max_age'] ?? null,
            );
        });
        if ($container->has(Console::class)) {
            $container->get(Console::class)->add('token:install', InstallTokens::class);
        }
        $container->singleton(Ticket::class, static fn(Container $container): Ticket => new Ticket()->withContainer(
            $container,
        ));
    }
}
