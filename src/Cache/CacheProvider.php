<?php

declare(strict_types=1);

namespace Crab\Cache;

use Crab\Cache\Commands\ClearCache;
use Crab\Cache\Commands\InstallCache;
use Crab\Cache\Commands\PruneCache;
use Crab\Contracts\Cache\Cache as CacheContract;
use Crab\Contracts\Cache\Store;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Database;
use Crab\Contracts\Provider;
use Psr\Clock\ClockInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Binds the cache from the "cache" configuration section, and adds the
 * `cache:clear`, `cache:prune` and `cache:install` commands.
 *
 *     store       "file", "database" or "array"; file unless set
 *     directory   storage/cache in the application; an application with no base path must set it
 *     table       the database store's table; "cache"
 *     database    the name of the database it is in; the default one when null
 *     prefix      put before every key, to keep two applications apart in one store
 *     ttl         seconds an entry lives when none is given; forever when null
 *
 * To keep entries somewhere else, bind Contracts\Cache\Store to a store of
 * your own after registering this provider.
 */
final class CacheProvider implements Provider
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults('cache', [
            'store' => 'file',
            'directory' => null,
            'table' => 'cache',
            'database' => null,
            'prefix' => '',
            'ttl' => null,
        ]);
        $container->singleton(Store::class, static function (Container $container): Store {
            $config = $container->get(Config::class);
            $store = (string) $config->get('cache.store');
            if ($store === 'file') {
                $directory = $config->get('cache.directory');
                if ($directory === null) {
                    // Never a shared temp folder: another account could make it first and fill it.
                    $base = $config->get('app.path');
                    if (!is_string($base)) {
                        throw new \LogicException(
                            'A file cache needs a folder: set cache.directory, or build the application with App::configure($basePath).',
                        );
                    }
                    $directory = $base . '/storage/cache';
                }
                return new FileStore((string) $directory);
            }
            if ($store === 'array') {
                return new ArrayStore();
            }
            if ($store === 'database') {
                if (!$container->has(Database::class)) {
                    throw new \LogicException(
                        'The cache is set to keep its entries in the database ("cache.store" is "database"): register the database provider, DatabaseProvider, as well as CacheProvider.',
                    );
                }
                $database = $container->get(Database::class);
                $name = $config->get('cache.database');
                return new DatabaseStore(
                    is_string($name) ? $database->connection($name) : $database,
                    (string) $config->get('cache.table'),
                );
            }
            throw new \LogicException(
                "\"cache.store\" is \"{$store}\", and the stores are \"file\", \"database\" and \"array\". For \"redis\", install crabphp/redis and register its RedisProvider after this one; for another, bind "
                . Store::class
                . ' to a store of your own.',
            );
        });
        $container->singleton(CacheContract::class, static function (Container $container): Cache {
            $config = $container->get(Config::class);
            $ttl = $config->get('cache.ttl');
            return new Cache(
                $container->get(Store::class),
                (string) $config->get('cache.prefix'),
                $ttl === null ? null : (int) $ttl,
                $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null,
            );
        });
        // The same object for code written against the standard, and by class.
        $container->singleton(CacheInterface::class, CacheContract::class);
        $container->singleton(Cache::class, CacheContract::class);
        if ($container->has(Console::class)) {
            $console = $container->get(Console::class);
            $console->add('cache:clear', ClearCache::class);
            $console->add('cache:prune', PruneCache::class);
            $console->add('cache:install', InstallCache::class);
        }
    }
}
