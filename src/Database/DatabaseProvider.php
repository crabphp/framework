<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Database as DatabaseContract;
use Crab\Contracts\WorkerProvider;
use Crab\Engine\Process\Worker;

/**
 * Binds the database from the "database" configuration section. Each
 * worker opens its own connections when it first runs a statement and
 * closes them when it stops.
 */
final class DatabaseProvider implements WorkerProvider
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults('database', [
            // A MySQL or SQLite data source name: "mysql:host=127.0.0.1;dbname=app" or "sqlite:/path/app.sqlite".
            'dsn' => null,
            'username' => null,
            'password' => null,
            // Further PDO attributes, as PDO's constructor takes them.
            'options' => [],
            // The most connections each worker opens; unset, it is 1 for SQLite, which allows one writer, and 10 otherwise.
            'pool' => null,
            // Seconds to wait for a connection when all are in use.
            'timeout' => 5.0,
        ]);
        $container->singleton(DatabaseContract::class, static function (Container $container): Database {
            $config = $container->get(Config::class);
            $dsn = $config->get('database.dsn');
            if (!is_string($dsn) || $dsn === '') {
                throw new DatabaseException('The "database.dsn" setting is not configured.');
            }
            $username = $config->get('database.username');
            $password = $config->get('database.password');
            $options = (array) $config->get('database.options');
            return new Database(
                static fn (): \PDO => new \PDO($dsn, $username, $password, $options),
                (int) ($config->get('database.pool') ?? (str_starts_with($dsn, 'sqlite:') ? 1 : 10)),
                (float) $config->get('database.timeout'),
            );
        });
        $container->singleton(Connection::class, DatabaseContract::class);
    }

    public function start(Worker $worker, Container $container): void
    {
    }

    public function stop(Worker $worker, Container $container): void
    {
        $container->get(DatabaseContract::class)->close();
    }
}
