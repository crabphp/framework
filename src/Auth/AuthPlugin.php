<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\PasswordHasher as PasswordHasherContract;
use Crab\Contracts\Auth\Tickets as TicketsContract;
use Crab\Contracts\Cache\Cache;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Plugin;

/**
 * Binds the password hasher and short-lived browser socket tickets.
 *
 * How a request says who makes it is not decided here. It is middleware:
 * whatever puts an Identity on the request's "user" attribute. Crab ships
 * tokens and their ticket fallback behind TokenAuthPlugin; another way
 * writes its own and registers neither more nor less than this.
 */
final class AuthPlugin implements Plugin
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults(
            'auth',
            [
                'ticket_ttl' => 30,
                'cookie' => ['name' => null, 'max_age' => null, 'login' => null, 'origins' => [], 'secure' => null],
            ],
            [
                'ticket_ttl' => ['type' => 'integer', 'minimum' => 1],
                'cookie' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => ['string', 'null'], 'pattern' => '^[A-Za-z0-9_.-]+$'],
                        'max_age' => ['type' => ['integer', 'null'], 'minimum' => 1],
                        'login' => ['type' => ['string', 'null'], 'minLength' => 1],
                        'origins' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^https?://']],
                        'secure' => ['type' => ['boolean', 'null']],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        );
        $container->singleton(PasswordHasherContract::class, static fn(): PasswordHasher => new PasswordHasher());
        $container->singleton(PasswordHasher::class, PasswordHasherContract::class);
        $container->singleton(TicketsContract::class, static function (Container $container): Tickets {
            if (!$container->has(Cache::class) || !$container->has(IdentityResolver::class)) {
                throw new \LogicException(
                    'Tickets need a cache and a user resolver. Register CachePlugin and bind '
                    . IdentityResolver::class
                    . ' in your application plugin.',
                );
            }
            $config = $container->get(Config::class);
            $config->check('auth');
            return new Tickets(
                $container->get(Cache::class),
                $container->get(IdentityResolver::class),
                (int) $config->get('auth.ticket_ttl'),
            );
        });
        $container->singleton(Tickets::class, TicketsContract::class);
    }
}
