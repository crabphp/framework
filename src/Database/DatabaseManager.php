<?php

declare(strict_types=1);

namespace Crab\Database;

use Psr\Clock\ClockInterface;

/**
 * The databases an application is configured with, by name. Each is made
 * when it is first asked for and then kept, with its own pool of
 * connections and its own migrator.
 *
 * Each database's settings are an array with these keys, all but "dsn"
 * optional:
 *
 *     dsn          "mysql:host=127.0.0.1;dbname=app" or "sqlite:/path/app.sqlite"
 *     username     for MySQL
 *     password     for MySQL
 *     options      further PDO attributes, as PDO's constructor takes them
 *     pool         the most connections each worker opens; 1 for SQLite, which allows one writer, and 10 otherwise
 *     timeout      seconds to wait for a connection when all are in use; 5
 *     migrations   the folder that holds this database's migration files
 */
final class DatabaseManager
{
    /**
     * @var array<string, Database>
     */
    private array $databases = [];
    /**
     * @var array<string, Migrator>
     */
    private array $migrators = [];

    /**
     * @param array<string, array<string, mixed>> $connections The settings of each database, by its name.
     * @param string $default The name of the one used when none is named.
     * @param ClockInterface|null $clock Passed to the migrators; the real time when left out.
     */
    public function __construct(
        private readonly array $connections,
        private readonly string $default,
        private readonly ?ClockInterface $clock = null,
    ) {
        if (!isset($connections[$default])) {
            throw new DatabaseException("The default database is \"{$default}\", and no database of that name is configured.");
        }
    }

    /**
     * The database of this name, or the default one.
     */
    public function connection(?string $name = null): Database
    {
        $name ??= $this->default;
        return $this->databases[$name] ??= $this->make($name);
    }

    /**
     * The migrator of the database of this name, or of the default one.
     */
    public function migrator(?string $name = null): Migrator
    {
        $name ??= $this->default;
        if (!isset($this->migrators[$name])) {
            $database = $this->connection($name);
            $directory = $this->connections[$name]['migrations'] ?? null;
            $this->migrators[$name] = new Migrator($database, is_string($directory) && $directory !== '' ? $directory : [], clock: $this->clock);
        }
        return $this->migrators[$name];
    }

    /**
     * @return list<string> The names of the configured databases.
     */
    public function names(): array
    {
        return array_keys($this->connections);
    }

    /**
     * Closes the connections of every database that was used.
     */
    public function close(): void
    {
        foreach ($this->databases as $database) {
            $database->close();
        }
    }

    private function make(string $name): Database
    {
        $settings = $this->connections[$name] ?? null;
        if ($settings === null) {
            throw new DatabaseException("There is no database named \"{$name}\"; the configured ones are: " . implode(', ', $this->names()) . '.');
        }
        $dsn = $settings['dsn'] ?? null;
        if (!is_string($dsn) || $dsn === '') {
            throw new DatabaseException("The database \"{$name}\" has no \"dsn\" configured. Set it to a data source name such as \"sqlite:/path/app.sqlite\" or \"mysql:host=127.0.0.1;dbname=app\".");
        }
        $username = $settings['username'] ?? null;
        $password = $settings['password'] ?? null;
        $options = (array) ($settings['options'] ?? []);
        $sqlite = str_starts_with($dsn, 'sqlite:');
        $connect = static function () use ($dsn, $username, $password, $options, $sqlite): \PDO {
            $pdo = new \PDO($dsn, $username, $password, $options);
            if ($sqlite) {
                // SQLite checks foreign keys only on a connection that asks it to.
                $pdo->exec('pragma foreign_keys = on');
            }
            return $pdo;
        };
        $pool = (int) ($settings['pool'] ?? ($sqlite ? 1 : 10));
        $timeout = (float) ($settings['timeout'] ?? 5.0);
        return new Database($connect, $pool, $timeout, $this);
    }
}
