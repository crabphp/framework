<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Database as DatabaseContract;
use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Migrator as MigratorContract;
use Crab\Contracts\Database\Table as TableContract;
use Crab\Engine\FailureReporter;
use Psr\Clock\ClockInterface;

/**
 * Runs the migration files of its directories in the order of their
 * names, and keeps the names that have run in a table of the database.
 *
 * Where the database can roll back a change to its tables (SQLite), each
 * migration runs in a transaction, so a failure before commit leaves
 * nothing behind. Where it cannot (MySQL), a migration that fails halfway has
 * done what it did before failing.
 */
final class Migrator implements MigratorContract
{
    /**
     * @var list<string>
     */
    private array $directories = [];

    /**
     * @param string|list<string> $directories Where the migration files are.
     * @param string $table The table that remembers which have run.
     * @param ClockInterface|null $clock Tells the time a migration ran and names new files; the real time when left out.
     * @param int $lockTimeout Whole seconds to wait for another migrator; zero does not wait.
     */
    public function __construct(
        private readonly Connection $connection,
        string|array $directories = [],
        private readonly string $table = 'migrations',
        private readonly ?ClockInterface $clock = null,
        private readonly int $lockTimeout = 60,
    ) {
        if ($lockTimeout < 0) {
            throw new \InvalidArgumentException(
                'The migration lock timeout must be at least zero whole seconds. Set "database.migration_lock_timeout" to the time you can wait.',
            );
        }
        foreach ((array) $directories as $directory) {
            $this->path($directory);
        }
    }

    public function path(string $directory): void
    {
        $this->directories[] = rtrim($directory, '/\\');
    }

    public function run(): array
    {
        return $this->locked(fn(Connection $connection): array => $this->runMigrations($connection));
    }

    /**
     * MySQL keeps its deployment lock; SQLite checks pending work again
     * inside each migration's writer transaction before calling up().
     *
     * @return list<string>
     */
    private function runMigrations(Connection $connection): array
    {
        $files = $this->files();
        $ran = $this->ran($connection);
        $batch = $ran === [] ? 1 : max($ran) + 1;
        $done = [];
        foreach ($files as $name => $file) {
            if (isset($ran[$name])) {
                continue;
            }
            $migration = self::load($name, $file);
            $this->assertLockHeld($connection);
            $applied = $this->apply($connection, $name, 'run', function (Connection $connection) use (
                $migration,
                $name,
                $batch,
            ): bool {
                if ($connection->table($this->table)->where('name', $name)->exists()) {
                    return false;
                }
                $migration->up(new Schema($connection));
                $connection
                    ->table($this->table)
                    ->insert(['name' => $name, 'batch' => $batch, 'ran_at' => $this->now()]);
                return true;
            });
            if ($applied) {
                $done[] = $name;
            }
        }
        return $done;
    }

    public function rollback(): array
    {
        return $this->locked(fn(Connection $connection): array => $this->rollbackMigrations($connection));
    }

    /**
     * Takes back the latest committed batch. SQLite checks each record
     * again after acquiring its writer lock so two rollbacks do not repeat it.
     *
     * @return list<string>
     */
    private function rollbackMigrations(Connection $connection): array
    {
        $files = $this->files();
        $ran = $this->ran($connection);
        if ($ran === []) {
            return [];
        }
        $batch = max($ran);
        $names = array_keys($ran, $batch, true);
        rsort($names);
        $done = [];
        foreach ($names as $name) {
            if (!isset($files[$name])) {
                throw new DatabaseException(
                    "The migration \"{$name}\" has run, but its file is gone, so it cannot be taken back.",
                );
            }
            $migration = self::load($name, $files[$name]);
            $this->assertLockHeld($connection);
            $applied = $this->apply($connection, $name, 'rolled back', function (Connection $connection) use (
                $migration,
                $name,
            ): bool {
                if (!$connection->table($this->table)->where('name', $name)->exists()) {
                    return false;
                }
                $migration->down(new Schema($connection));
                $connection->table($this->table)->where('name', $name)->delete();
                return true;
            });
            if ($applied) {
                $done[] = $name;
            }
        }
        return $done;
    }

    public function status(): array
    {
        $ran = $this->ran($this->connection, create: false);
        $status = [];
        foreach (array_keys($this->files()) as $name) {
            $status[$name] = $ran[$name] ?? null;
        }
        return $status;
    }

    public function make(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        if ($slug === '') {
            throw new \InvalidArgumentException(
                'A migration needs a name that says what it does, such as "create users table".',
            );
        }
        $directory = $this->directories[0] ?? throw $this->unconfigured();
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException("The migration directory {$directory} cannot be created.");
        }
        $file = $directory . '/' . $this->time()->format('Y_m_d_His') . '_' . $slug . '.php';
        if (is_file($file)) {
            throw new \RuntimeException("{$file} already exists.");
        }
        // "create users table" is taken at its word.
        $creates = preg_match('/^create_(.+)_table$/', $slug, $match) === 1 ? $match[1] : null;
        file_put_contents($file, self::stub($creates));
        return $file;
    }

    /**
     * Runs one migration's work, in a transaction where the database can
     * roll back a change to its tables.
     *
     * @param \Closure(Connection): bool $work Whether this migration still needed applying.
     */
    private function apply(Connection $connection, string $name, string $verb, \Closure $work): bool
    {
        try {
            if (SchemaGrammar::for($connection->driver())->transactional()) {
                return $connection->transaction($work);
            }
            return $work($connection);
        } catch (\Throwable $error) {
            // A writer-lock timeout belongs to the deployment, not to up() or down().
            if ($error instanceof DatabaseException && $error->sql === 'BEGIN IMMEDIATE' && $error->code() === 5) {
                throw $error;
            }
            throw new DatabaseException(
                "The migration \"{$name}\" could not be {$verb}: {$error->getMessage()}",
                previous: $error,
            );
        }
    }

    /**
     * Keeps a single borrowed connection until the work and lock cleanup
     * finish. A pool of one works when migrations use the Schema and
     * connection they receive; acquiring from the pool again needs two.
     * MySQL's connection lock survives DDL's implicit commits.
     *
     * @param \Closure(Connection): list<string> $work
     * @return list<string>
     */
    private function locked(\Closure $work): array
    {
        $lockedSession = function (Connection $connection) use ($work): array {
            if ($connection->driver() === 'sqlite') {
                return $this->lockedSqlite($connection, $work);
            }
            $name = 'crab_migrations_' . $this->table;
            $row = $connection->first('SELECT GET_LOCK(?, ?) AS acquired', [$name, $this->lockTimeout]);
            if (($row['acquired'] ?? null) === null) {
                throw new DatabaseException(
                    'The database could not acquire the migration lock. Check the database connection and retry "migrate".',
                );
            }
            if ((int) $row['acquired'] !== 1) {
                throw $this->lockTimedOut();
            }
            $failure = null;
            try {
                $done = $work($connection);
                $this->assertLockHeld($connection);
                return $done;
            } catch (\Throwable $error) {
                $failure = $error;
                throw $error;
            } finally {
                try {
                    if (!$connection instanceof ManagedConnection || $connection->isOpen()) {
                        $connection->first('SELECT RELEASE_LOCK(?) AS released', [$name]);
                    }
                } catch (\Throwable $error) {
                    if ($failure === null) {
                        throw $error;
                    }
                    FailureReporter::report('The migration lock could not be released', $error);
                }
            }
        };
        // A connection object may normally replace its session. An advisory
        // lock must keep the physical session fixed through work and cleanup.
        $lock = static function (Connection $connection) use ($lockedSession): array {
            if ($connection instanceof ManagedConnection && $connection->driver() === 'mysql') {
                return $connection->withoutReconnect($lockedSession);
            }
            return $lockedSession($connection);
        };
        if ($this->connection instanceof DatabaseContract) {
            return $this->connection->run($lock);
        }
        return $lock($this->connection);
    }

    /**
     * Each SQLite migration owns BEGIN IMMEDIATE and rechecks its history
     * before making changes. Separate commits preserve afterCommit order
     * and earlier successful migrations without a persistent lock row.
     * The busy timeout covers waiting for each writer and is restored for
     * the next borrower, including after an acquisition timeout.
     *
     * @param \Closure(Connection): list<string> $work
     * @return list<string>
     */
    private function lockedSqlite(Connection $connection, \Closure $work): array
    {
        $row = $connection->first('PRAGMA busy_timeout');
        $timeout = (int) $row['timeout'];
        $milliseconds = (int) min($this->lockTimeout * 1000, 2_147_483_647);
        // PRAGMA's assignment syntax cannot bind a parameter; only a bounded integer goes into it.
        $connection->execute('PRAGMA busy_timeout = ' . $milliseconds);
        try {
            return $work($connection);
        } catch (DatabaseException $error) {
            if ($error->code() === 5) {
                throw $this->lockTimedOut($error);
            }
            throw $error;
        } finally {
            $connection->execute('PRAGMA busy_timeout = ' . $timeout);
        }
    }

    /**
     * The fixed session prevents replacement on connection loss. This check
     * also detects a migration that explicitly released its named lock.
     */
    private function assertLockHeld(Connection $connection): void
    {
        if ($connection->driver() === 'sqlite') {
            return;
        }
        $previous = null;
        try {
            $row = $connection->first('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS owned', [
                'crab_migrations_' . $this->table,
            ]);
            if ((int) ($row['owned'] ?? 0) === 1) {
                return;
            }
        } catch (DatabaseException $error) {
            $previous = $error;
        }
        throw new DatabaseException(
            'The database connection was lost during migration. The migration in progress may be half applied. Run "migrate:status" to inspect the migrations before retrying.',
            previous: $previous,
        );
    }

    /**
     * Gives a waiting deployment a command to inspect the pending work.
     */
    private function lockTimedOut(?\Throwable $previous = null): DatabaseException
    {
        return new DatabaseException(
            "Another process has been migrating for {$this->lockTimeout} seconds. Run \"migrate:status\" to inspect the migrations, then retry when it finishes.",
            previous: $previous,
        );
    }

    /**
     * @return array<string, string> Every migration file by its name, in order of name.
     */
    private function files(): array
    {
        if ($this->directories === []) {
            throw $this->unconfigured();
        }
        $files = [];
        foreach ($this->directories as $directory) {
            foreach (glob($directory . '/*.php') ?: [] as $file) {
                $name = basename($file, '.php');
                if (isset($files[$name])) {
                    throw new DatabaseException("Two migrations are named \"{$name}\": {$files[$name]} and {$file}.");
                }
                $files[$name] = $file;
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /**
     * @return array<string, int> The batch of each migration that has run, by name. Only a writer creates the table, so status stays read-only during the first deployment.
     */
    private function ran(Connection $connection, bool $create = true): array
    {
        $schema = new Schema($connection);
        if (!$schema->hasTable($this->table)) {
            if (!$create) {
                return [];
            }
            try {
                $schema->create($this->table, static function (TableContract $table): void {
                    $table->id();
                    $table->string('name')->unique();
                    $table->integer('batch');
                    $table->dateTime('ran_at');
                });
            } catch (DatabaseException $error) {
                // Another SQLite writer can create it between hasTable() and create().
                if (
                    $connection->driver() !== 'sqlite'
                    || $error->code() !== 1
                    || !str_contains($error->getMessage(), 'already exists')
                    || !$schema->hasTable($this->table)
                ) {
                    throw $error;
                }
            }
        }
        $ran = [];
        foreach ($connection->table($this->table)->orderBy('id')->get() as $row) {
            $ran[(string) $row['name']] = (int) $row['batch'];
        }
        return $ran;
    }

    private static function load(string $name, string $file): Migration
    {
        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new DatabaseException(
                "The migration \"{$name}\" must return an object that implements "
                . Migration::class
                . "; {$file} returned "
                . get_debug_type($migration)
                . '.',
            );
        }
        return $migration;
    }

    private function time(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable();
    }

    private function now(): string
    {
        return $this->time()->format('Y-m-d H:i:s');
    }

    private function unconfigured(): DatabaseException
    {
        return new DatabaseException(
            'No migration directory is configured. Set "migrations" on its connection, under "database.connections", to the folder that holds the migration files.',
        );
    }

    private static function stub(?string $creates): string
    {
        $up = $creates === null
            ? '        //'
            : "        \$schema->create('{$creates}', function (Table \$table) {\n            \$table->id();\n            \$table->timestamps();\n        });";
        $down = $creates === null ? '        //' : "        \$schema->drop('{$creates}');";
        return <<<PHP
            <?php

            declare(strict_types=1);

            use Crab\Contracts\Database\Migration;
            use Crab\Contracts\Database\Schema;
            use Crab\Contracts\Database\Table;

            return new class implements Migration {
                public function up(Schema \$schema): void
                {
            {$up}
                }

                public function down(Schema \$schema): void
                {
            {$down}
                }
            };

            PHP;
    }
}
