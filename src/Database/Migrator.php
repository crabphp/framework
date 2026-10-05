<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Migration;
use Crab\Contracts\Database\Migrator as MigratorContract;
use Crab\Contracts\Database\Table as TableContract;
use Psr\Clock\ClockInterface;

/**
 * Runs the migration files of its directories in the order of their
 * names, and keeps the names that have run in a table of the database.
 *
 * Where the database can roll back a change to its tables (SQLite), each
 * migration runs in a transaction, so one that fails leaves nothing
 * behind. Where it cannot (MySQL), a migration that fails halfway has
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
     */
    public function __construct(
        private readonly Connection $connection,
        string|array $directories = [],
        private readonly string $table = 'migrations',
        private readonly ?ClockInterface $clock = null,
    ) {
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
        $files = $this->files();
        $ran = $this->ran();
        $batch = $ran === [] ? 1 : max($ran) + 1;
        $done = [];
        foreach ($files as $name => $file) {
            if (isset($ran[$name])) {
                continue;
            }
            $migration = self::load($name, $file);
            $this->apply($name, 'run', function (Connection $connection) use ($migration, $name, $batch): void {
                $migration->up(new Schema($connection));
                $connection->table($this->table)->insert(['name' => $name, 'batch' => $batch, 'ran_at' => $this->now()]);
            });
            $done[] = $name;
        }
        return $done;
    }

    public function rollback(): array
    {
        $files = $this->files();
        $ran = $this->ran();
        if ($ran === []) {
            return [];
        }
        $batch = max($ran);
        $names = array_keys($ran, $batch, true);
        rsort($names);
        $done = [];
        foreach ($names as $name) {
            if (!isset($files[$name])) {
                throw new DatabaseException("The migration \"{$name}\" has run, but its file is gone, so it cannot be taken back.");
            }
            $migration = self::load($name, $files[$name]);
            $this->apply($name, 'rolled back', function (Connection $connection) use ($migration, $name): void {
                $migration->down(new Schema($connection));
                $connection->table($this->table)->where('name', $name)->delete();
            });
            $done[] = $name;
        }
        return $done;
    }

    public function status(): array
    {
        $ran = $this->ran();
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
            throw new \InvalidArgumentException('A migration needs a name that says what it does, such as "create users table".');
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
     * @param \Closure(Connection): void $work
     */
    private function apply(string $name, string $verb, \Closure $work): void
    {
        try {
            if (SchemaGrammar::for($this->connection->driver())->transactional()) {
                $this->connection->transaction($work);
            } else {
                $work($this->connection);
            }
        } catch (\Throwable $error) {
            throw new DatabaseException("The migration \"{$name}\" could not be {$verb}: {$error->getMessage()}", previous: $error);
        }
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
     * @return array<string, int> The batch of each migration that has run, by name. Makes the table that remembers them when it is not there.
     */
    private function ran(): array
    {
        $schema = new Schema($this->connection);
        if (!$schema->hasTable($this->table)) {
            $schema->create($this->table, static function (TableContract $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->integer('batch');
                $table->dateTime('ran_at');
            });
        }
        $ran = [];
        foreach ($this->connection->table($this->table)->orderBy('id')->get() as $row) {
            $ran[(string) $row['name']] = (int) $row['batch'];
        }
        return $ran;
    }

    private static function load(string $name, string $file): Migration
    {
        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new DatabaseException("The migration \"{$name}\" must return an object that implements " . Migration::class . "; {$file} returned " . get_debug_type($migration) . '.');
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
        return new DatabaseException('No migration directory is configured. Set "database.migrations" to the folder that holds the migration files.');
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
