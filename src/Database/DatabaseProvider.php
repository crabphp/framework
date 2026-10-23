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
        $container->get(Config::class)->defaults('database', [
            // Each database by a name of your choosing; see DatabaseManager for its settings.
            'connections' => [],
            // The name of the one used when none is named; the first when left out.
            'default' => null,
            'migration_lock_timeout' => 60,
        ]);
        $container->singleton(DatabaseManager::class, static function (Container $container): DatabaseManager {
            $config = $container->get(Config::class);
            $connections = $config->get('database.connections');
            if ($config->get('database.dsn') !== null) {
                throw new DatabaseException(
                    '"database.dsn" is not read. A database is described under "database.connections", by name: \'connections\' => [\'main\' => [\'dsn\' => ...]].',
                );
            }
            if (!is_array($connections)) {
                throw new DatabaseException(
                    '"database.connections" holds each database by name: \'connections\' => [\'main\' => [\'dsn\' => \'sqlite:/path/app.sqlite\']].',
                );
            }
            $default = (string) ($config->get('database.default') ?? array_key_first($connections));
            $clock = $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null;
            $lockTimeout = $config->get('database.migration_lock_timeout');
            if (!is_numeric($lockTimeout) || !is_finite((float) $lockTimeout) || (float) $lockTimeout < 0) {
                throw new \InvalidArgumentException(
                    'Set "database.migration_lock_timeout" to a nonnegative number of whole seconds; zero does not wait.',
                );
            }
            return new DatabaseManager($connections, $default, $clock, (int) $lockTimeout);
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
