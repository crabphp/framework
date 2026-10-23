<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Database as DatabaseContract;
use Crab\Contracts\Database\Migrator as MigratorContract;
use Crab\Contracts\Database\Migrators;
use Crab\Contracts\Database\Schema as SchemaContract;
use Crab\Contracts\WorkerProvider;
use Crab\Database\Commands\Fresh;
use Crab\Database\Commands\MakeMigration;
use Crab\Database\Commands\Migrate;
use Crab\Database\Commands\Rollback;
use Crab\Database\Commands\Status;
use Crab\Engine\Process\Worker;
use Psr\Clock\ClockInterface;

/**
 * Binds the default database, its schema builder and its migrator from
 * the "database" configuration section, and adds the migration commands.
 * The section describes each database under "connections", by name, one
 * or several; another than the default is reached with
 * Database::connection(). Each
 * worker opens its own connections when it first runs a statement and
 * closes them when it stops.
 */
final class DatabaseProvider implements WorkerProvider
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults(
            'database',
            [
                // Each database by a name of your choosing; see DatabaseManager for its settings.
                'connections' => [],
                // The name of the one used when none is named; the first when left out.
                'default' => null,
                'migration_lock_timeout' => 60,
            ],
            [
                'connections' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'object',
                        // Null, as env() gives for a variable that is not set, is the same as leaving the key out.
                        'properties' => [
                            'driver' => ['enum' => ['sqlite', 'mysql', null]],
                            'host' => ['type' => ['string', 'null']],
                            'port' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 65_535],
                            'database' => ['type' => ['string', 'null']],
                            'username' => ['type' => ['string', 'null']],
                            'password' => ['type' => ['string', 'null']],
                            'charset' => ['type' => ['string', 'null']],
                            'unix_socket' => ['type' => ['string', 'null']],
                            // In place of the keys above.
                            'dsn' => ['type' => ['string', 'null'], 'examples' => ['mysql:host=127.0.0.1;dbname=app']],
                            'migrations' => ['type' => ['string', 'null']],
                            'options' => ['type' => ['object', 'null']],
                            'pool' => ['type' => ['integer', 'null'], 'minimum' => 1],
                            'timeout' => ['type' => ['number', 'null'], 'exclusiveMinimum' => 0],
                            'async' => ['type' => ['boolean', 'null']],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'default' => ['type' => ['string', 'null'], 'minLength' => 1],
                // Seconds, in whole seconds; zero does not wait.
                'migration_lock_timeout' => ['type' => 'number', 'minimum' => 0],
            ],
        );
        $container->singleton(DatabaseManager::class, static function (Container $container): DatabaseManager {
            $config = $container->get(Config::class);
            $config->check('database');
            $connections = (array) $config->get('database.connections');
            $default = (string) ($config->get('database.default') ?? array_key_first($connections));
            $clock = $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null;
            $lockTimeout = $config->get('database.migration_lock_timeout');
            $base = $config->get('app.path');
            return new DatabaseManager(
                $connections,
                $default,
                $clock,
                (int) $lockTimeout,
                // The default database's migrations are in database/migrations unless its settings say otherwise.
                is_string($base) && $base !== '' ? rtrim($base, '/') . '/database/migrations' : null,
            );
        });
        $container->singleton(DatabaseContract::class, static fn(Container $container): Database => $container->get(
            DatabaseManager::class,
        )->connection());
        $container->singleton(Connection::class, DatabaseContract::class);
        $container->singleton(
            SchemaContract::class,
            static fn(Container $container): Schema => new Schema($container->get(DatabaseContract::class)),
        );
        $container->singleton(MigratorContract::class, static fn(Container $container): Migrator => $container->get(
            DatabaseManager::class,
        )->migrator());
        $container->singleton(Migrators::class, DatabaseManager::class);
        $container->singleton(Database::class, DatabaseContract::class);
        $container->singleton(Schema::class, SchemaContract::class);
        $container->singleton(Migrator::class, MigratorContract::class);
        if ($container->has(Console::class)) {
            $console = $container->get(Console::class);
            $console->add('migrate', Migrate::class);
            $console->add('migrate:rollback', Rollback::class);
            $console->add('migrate:fresh', Fresh::class);
            $console->add('migrate:status', Status::class);
            $console->add('make:migration', MakeMigration::class);
        }
    }

    public function start(Worker $worker, Container $container): void
    {
        $container->get(DatabaseManager::class)->start($worker);
    }

    public function stop(Worker $worker, Container $container): void
    {
        $container->get(DatabaseManager::class)->stop();
    }
}
