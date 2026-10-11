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
use Crab\Database\Commands\MakeMigration;
use Crab\Database\Commands\Migrate;
use Crab\Database\Commands\Rollback;
use Crab\Database\Commands\Status;
use Crab\Engine\Process\Worker;
use Psr\Clock\ClockInterface;

/**
 * Binds the default database, its schema builder and its migrator from
 * the "database" configuration section, and adds the migration commands.
 * The section describes one database, or several under "connections";
 * another than the default is reached with Database::connection(). Each
 * worker opens its own connections when it first runs a statement and
 * closes them when it stops.
 */
final class DatabaseProvider implements WorkerProvider
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults('database', [
            // One database: its settings go here. See DatabaseManager for each of them.
            'dsn' => null,
            'username' => null,
            'password' => null,
            'options' => [],
            'pool' => null,
            'timeout' => 5.0,
            'migrations' => null,
            'migration_lock_timeout' => 60,
            // Several databases: the same settings for each, by a name of your choosing, and which one is the default.
            'connections' => [],
            'default' => null,
        ]);
        $container->singleton(DatabaseManager::class, static function (Container $container): DatabaseManager {
            $config = $container->get(Config::class);
            $connections = (array) $config->get('database.connections');
            if ($connections === []) {
                $connections = ['default' => [
                    'dsn' => $config->get('database.dsn'),
                    'username' => $config->get('database.username'),
                    'password' => $config->get('database.password'),
                    'options' => $config->get('database.options'),
                    'pool' => $config->get('database.pool'),
                    'timeout' => $config->get('database.timeout'),
                    'migrations' => $config->get('database.migrations'),
                ]];
            }
            $default = (string) ($config->get('database.default') ?? array_key_first($connections));
            $clock = $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null;
            $lockTimeout = $config->get('database.migration_lock_timeout');
            if (!is_numeric($lockTimeout) || !is_finite((float) $lockTimeout) || (float) $lockTimeout < 0) {
                throw new \InvalidArgumentException('Set "database.migration_lock_timeout" to a nonnegative number of whole seconds; zero does not wait.');
            }
            return new DatabaseManager($connections, $default, $clock, (int) $lockTimeout);
        });
        $container->singleton(DatabaseContract::class, static fn (Container $container): Database => $container->get(DatabaseManager::class)->connection());
        $container->singleton(Connection::class, DatabaseContract::class);
        $container->singleton(SchemaContract::class, static fn (Container $container): Schema => new Schema($container->get(DatabaseContract::class)));
        $container->singleton(MigratorContract::class, static fn (Container $container): Migrator => $container->get(DatabaseManager::class)->migrator());
        $container->singleton(Migrators::class, DatabaseManager::class);
        if ($container->has(Console::class)) {
            $console = $container->get(Console::class);
            $console->add('migrate', Migrate::class);
            $console->add('migrate:rollback', Rollback::class);
            $console->add('migrate:status', Status::class);
            $console->add('make:migration', MakeMigration::class);
        }
    }

    public function start(Worker $worker, Container $container): void
    {
    }

    public function stop(Worker $worker, Container $container): void
    {
        $container->get(DatabaseManager::class)->close();
    }
}
