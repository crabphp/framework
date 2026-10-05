<?php

declare(strict_types=1);

namespace Crab\Tests\Database;

use Crab\App;
use Crab\Config\Config;
use Crab\Console\Output;
use Crab\Contracts\Database\Database as DatabaseContract;
use Crab\Contracts\Database\Migrator as MigratorContract;
use Crab\Contracts\Database\Schema as SchemaContract;
use Crab\Database\Database;
use Crab\Database\DatabaseException;
use Crab\Database\DatabaseProvider;
use Crab\Database\Migrator;
use Crab\Database\Schema;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Crab\Contracts\Clock\Clock;

/**
 * Runs against SQLite always, and against MySQL too when
 * CRAB_TEST_MYSQL_DSN names a database the tests may fill.
 */
final class MigratorTest extends TestCase
{
    private const TABLES = ['migrator_posts', 'migrator_users', 'migrator_teams', 'migrator_half', 'migrations', 'migrator_log'];

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-migrator-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/migrations', recursive: true);
        mkdir($this->directory . '/package');
    }

    protected function tearDown(): void
    {
        foreach (['/migrations', '/package', '/new/folder', '/new'] as $folder) {
            foreach (glob($this->directory . $folder . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($this->directory . $folder);
        }
        @unlink($this->directory . '/app.sqlite');
        rmdir($this->directory);
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $this->pdo('mysql')->exec('drop table if exists ' . implode(', ', self::TABLES));
        }
    }

    public static function drivers(): array
    {
        return getenv('CRAB_TEST_MYSQL_DSN') === false ? [['sqlite']] : [['sqlite'], ['mysql']];
    }

    #[DataProvider('drivers')]
    public function testMigrationsRunOnceInOrderAndRollBackByBatch(string $driver): void
    {
        $db = $this->database($driver);
        $schema = new Schema($db);
        $clock = new FrozenClock('2026-01-31 12:00:00');
        $migrator = new Migrator($db, $this->directory . '/migrations/', clock: $clock);
        // Written out of order: the names decide.
        $this->migration('migrations/2026_01_02_000000_create_posts', "\$schema->create('migrator_posts', function (Table \$t) { \$t->id(); \$t->foreignId('user_id')->references('migrator_users'); });", "\$schema->drop('migrator_posts');");
        $this->migration('migrations/2026_01_01_000000_create_users', "\$schema->create('migrator_users', function (Table \$t) { \$t->id(); \$t->string('name'); }); \$schema->connection()->table('migrator_users')->insert(['name' => 'seeded']);", "\$schema->drop('migrator_users');");

        self::assertSame(['2026_01_01_000000_create_users' => null, '2026_01_02_000000_create_posts' => null], $migrator->status());
        self::assertSame(['2026_01_01_000000_create_users', '2026_01_02_000000_create_posts'], $migrator->run());
        self::assertSame([true, true], [$schema->hasTable('migrator_users'), $schema->hasTable('migrator_posts')]);
        self::assertSame(['seeded'], $db->table('migrator_users')->pluck('name'), 'A migration may fill rows through the schema\'s connection.');
        self::assertSame(['2026_01_01_000000_create_users' => 1, '2026_01_02_000000_create_posts' => 1], $migrator->status());
        self::assertSame(['2026-01-31 12:00:00', '2026-01-31 12:00:00'], $db->table('migrations')->pluck('ran_at'));
        self::assertSame([], $migrator->run(), 'What has run does not run again.');

        $migrator->path($this->directory . '/package');
        $this->migration('package/2026_01_03_000000_create_teams', "\$schema->create('migrator_teams', function (Table \$t) { \$t->id(); });", "\$schema->drop('migrator_teams');");
        self::assertSame(['2026_01_03_000000_create_teams'], $migrator->run());
        self::assertSame(2, $migrator->status()['2026_01_03_000000_create_teams'], 'A later run is a later batch.');

        self::assertSame(['2026_01_03_000000_create_teams'], $migrator->rollback());
        self::assertSame([false, true], [$schema->hasTable('migrator_teams'), $schema->hasTable('migrator_posts')]);
        self::assertNull($migrator->status()['2026_01_03_000000_create_teams']);
        self::assertSame(['2026_01_02_000000_create_posts', '2026_01_01_000000_create_users'], $migrator->rollback(), 'Newest first, so that what points at a table goes before it.');
        self::assertSame([false, false], [$schema->hasTable('migrator_users'), $schema->hasTable('migrator_posts')]);
        self::assertSame([], $migrator->rollback());
        self::assertCount(3, $migrator->run(), 'Everything runs again after being taken back.');
    }

    #[DataProvider('drivers')]
    public function testAMigrationThatFailsIsNamedAndNotRecorded(string $driver): void
    {
        $db = $this->database($driver);
        $schema = new Schema($db);
        $migrator = new Migrator($db, $this->directory . '/migrations');
        $this->migration('migrations/2026_01_01_000000_create_users', "\$schema->create('migrator_users', function (Table \$t) { \$t->id(); });", "\$schema->drop('migrator_users');");
        $this->migration('migrations/2026_01_02_000000_half', "\$schema->create('migrator_half', function (Table \$t) { \$t->id(); }); \$schema->drop('no_such_table');", "throw new \\DomainException('cannot undo');");
        $this->migration('migrations/2026_01_03_000000_never', "\$schema->create('migrator_teams', function (Table \$t) { \$t->id(); });", '');

        try {
            $migrator->run();
            self::fail('A failing migration was reported as run.');
        } catch (DatabaseException $error) {
            self::assertStringStartsWith('The migration "2026_01_02_000000_half" could not be run: ', $error->getMessage());
            self::assertInstanceOf(DatabaseException::class, $error->getPrevious());
        }
        self::assertSame(['2026_01_01_000000_create_users' => 1, '2026_01_02_000000_half' => null, '2026_01_03_000000_never' => null], $migrator->status(), 'Those before it stay; it and those after it have not run.');
        self::assertFalse($schema->hasTable('migrator_teams'));
        // SQLite takes the half-made table back with the transaction; MySQL cannot.
        self::assertSame($driver === 'mysql', $schema->hasTable('migrator_half'));

        unlink($this->directory . '/migrations/2026_01_02_000000_half.php');
        $this->migration('migrations/2026_01_02_000000_half', "\$schema->create('migrator_log', function (Table \$t) { \$t->id(); });", "throw new \\DomainException('cannot undo');");
        self::assertCount(2, $migrator->run());
        try {
            $migrator->rollback();
            self::fail('A rollback that failed was reported as done.');
        } catch (DatabaseException $error) {
            self::assertSame('The migration "2026_01_02_000000_half" could not be rolled back: cannot undo', $error->getMessage());
        }
        self::assertSame(['2026_01_01_000000_create_users' => 1, '2026_01_02_000000_half' => 2, '2026_01_03_000000_never' => null], $migrator->status(), 'The newest of the batch was taken back; the one that failed is still recorded.');

        unlink($this->directory . '/migrations/2026_01_02_000000_half.php');
        try {
            $migrator->rollback();
            self::fail('A migration with no file was rolled back.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('has run, but its file is gone', $error->getMessage());
        }
    }

    public function testFilesThatAreNotMigrationsAndMissingSettingsAreReported(): void
    {
        $db = $this->database('sqlite');
        $mistakes = [
            'No migration directory is configured. Set "database.migrations"' => static fn () => (new Migrator($db))->run(),
            'No migration directory is configured' => static fn () => (new Migrator($db))->make('create users table'),
            'needs a name that says what it does' => fn () => (new Migrator($db, $this->directory . '/migrations'))->make(' !! '),
        ];
        foreach ($mistakes as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (DatabaseException|\InvalidArgumentException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }

        file_put_contents($this->directory . '/migrations/2026_01_01_000000_wrong.php', "<?php return ['not' => 'a migration'];");
        try {
            (new Migrator($db, $this->directory . '/migrations'))->run();
            self::fail('A file that returns an array was run.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('"2026_01_01_000000_wrong" must return an object that implements Crab\Contracts\Database\Migration', $error->getMessage());
            self::assertStringContainsString('returned array', $error->getMessage());
        }
        file_put_contents($this->directory . '/package/2026_01_01_000000_wrong.php', '<?php return null;');
        try {
            (new Migrator($db, [$this->directory . '/migrations', $this->directory . '/package']))->status();
            self::fail('Two migrations of one name were accepted.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('Two migrations are named "2026_01_01_000000_wrong"', $error->getMessage());
        }
        self::assertSame([], (new Migrator($db, $this->directory . '/empty'))->status(), 'A folder that is not there yet holds no migrations.');
    }

    public function testNewMigrationFilesAreWrittenAndRun(): void
    {
        $db = $this->database('sqlite');
        $clock = new FrozenClock('2026-01-31 12:00:00');
        $migrator = new Migrator($db, $this->directory . '/new/folder', clock: $clock);

        $created = $migrator->make('Create migrator_users table');
        self::assertSame($this->directory . '/new/folder/2026_01_31_120000_create_migrator_users_table.php', $created);
        $source = (string) file_get_contents($created);
        self::assertStringContainsString("\$schema->create('migrator_users', function (Table \$table) {", $source);
        self::assertStringContainsString("\$schema->drop('migrator_users');", $source);
        self::assertStringStartsWith("<?php\n\ndeclare(strict_types=1);\n\nuse Crab\\Contracts\\Database\\Migration;", $source);

        $clock->advance('+1 minute');
        $plain = $migrator->make('add phone to users');
        self::assertStringEndsWith('/2026_01_31_120100_add_phone_to_users.php', $plain);
        self::assertStringContainsString("    public function up(Schema \$schema): void\n    {\n        //\n    }", (string) file_get_contents($plain));
        try {
            $migrator->make('add  phone  to users');
            self::fail('An existing migration file was overwritten.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('already exists', $error->getMessage());
        }

        self::assertCount(2, $migrator->run(), 'A file as it was written is a migration that runs.');
        $schema = new Schema($db);
        self::assertTrue($schema->hasColumn('migrator_users', 'created_at'));
        self::assertCount(2, $migrator->rollback());
        self::assertFalse($schema->hasTable('migrator_users'));
    }

    public function testTheProviderBindsTheSchemaAndMigratorAndAddsTheCommands(): void
    {
        $app = new App(new Config(['database' => [
            'dsn' => 'sqlite:' . $this->directory . '/app.sqlite',
            'migrations' => $this->directory . '/migrations',
        ]]));
        $app->container->instance(Clock::class, new FrozenClock('2026-01-31 12:00:00'));
        $app->register(DatabaseProvider::class);
        self::assertInstanceOf(Schema::class, $app->container->get(SchemaContract::class));
        self::assertInstanceOf(Migrator::class, $app->container->get(MigratorContract::class));

        self::assertSame("There are no migrations.\n", $this->run_($app, 'migrate:status'));
        self::assertSame("Nothing to migrate.\n", $this->run_($app, 'migrate'));
        self::assertSame("Nothing to roll back.\n", $this->run_($app, 'migrate:rollback'));
        self::assertSame("Created {$this->directory}/migrations/2026_01_31_120000_create_migrator_users_table.php\n", $this->run_($app, 'make:migration', 'create_migrator_users_table'));
        self::assertStringContainsString('Give the migration a name', $this->run_($app, 'make:migration', expect: 1));
        $this->migration('migrations/2026_02_01_000000_create_posts', "\$schema->create('migrator_posts', function (Table \$t) { \$t->id(); \$t->foreignId('user_id')->references('migrator_users'); });", "\$schema->drop('migrator_posts');");

        self::assertSame("Pending  2026_01_31_120000_create_migrator_users_table\nPending  2026_02_01_000000_create_posts\n", $this->run_($app, 'migrate:status'));
        self::assertSame("Migrated     2026_01_31_120000_create_migrator_users_table\nMigrated     2026_02_01_000000_create_posts\n", $this->run_($app, 'migrate'));
        self::assertSame("Ran (1)  2026_01_31_120000_create_migrator_users_table\nRan (1)  2026_02_01_000000_create_posts\n", $this->run_($app, 'migrate:status'));

        $db = $app->container->get(DatabaseContract::class);
        try {
            $db->table('migrator_posts')->insert(['user_id' => 5]);
            self::fail('SQLite did not check a foreign key on a connection the provider opened.');
        } catch (DatabaseException) {
        }
        self::assertSame("Rolled back  2026_02_01_000000_create_posts\nRolled back  2026_01_31_120000_create_migrator_users_table\n", $this->run_($app, 'migrate:rollback'));
        $db->close();

        $unset = new App(new Config(['database' => ['dsn' => 'sqlite::memory:']]));
        $unset->register(DatabaseProvider::class);
        self::assertStringContainsString('Set "database.migrations"', $this->run_($unset, 'migrate', expect: 1));
    }

    public function testSeveralDatabasesAreConfiguredByNameAndReachedFromOneAnother(): void
    {
        mkdir($this->directory . '/reports');
        $app = new App(new Config(['database' => [
            'default' => 'main',
            'connections' => [
                'reports' => ['dsn' => 'sqlite:' . $this->directory . '/reports.sqlite', 'migrations' => $this->directory . '/reports', 'pool' => 3],
                'main' => ['dsn' => 'sqlite:' . $this->directory . '/app.sqlite', 'migrations' => $this->directory . '/migrations'],
                'broken' => ['username' => 'nobody'],
            ],
        ]]));
        $app->register(DatabaseProvider::class);
        $this->migration('migrations/2026_01_01_000000_create_users', "\$schema->create('migrator_users', function (Table \$t) { \$t->id(); \$t->string('name'); });", "\$schema->drop('migrator_users');");
        $this->migration('reports/2026_01_01_000000_create_totals', "\$schema->create('totals', function (Table \$t) { \$t->id(); \$t->integer('amount'); });", "\$schema->drop('totals');");

        self::assertSame("Migrated     2026_01_01_000000_create_users\n", $this->run_($app, 'migrate'));
        self::assertSame("Pending  2026_01_01_000000_create_totals\n", $this->run_($app, 'migrate:status', '--database=reports'));
        self::assertSame("Migrated     2026_01_01_000000_create_totals\n", $this->run_($app, 'migrate', '--database=reports'));
        self::assertStringContainsString('/reports/', $this->run_($app, 'make:migration', '--database=reports add_day_to_totals'));

        $main = $app->container->get(DatabaseContract::class);
        $reports = $main->connection('reports');
        self::assertNotSame($main, $reports);
        self::assertSame($reports, $main->connection('reports'), 'A database is made once.');
        self::assertSame($main, $reports->connection('main'), 'Any of them reaches the others.');
        self::assertSame($main, $main->connection('main'));

        $main->table('migrator_users')->insert(['name' => 'Ada']);
        $reports->table('totals')->insert(['amount' => 7]);
        self::assertSame([1, 7], [$main->table('migrator_users')->count(), $reports->table('totals')->sum('amount')]);
        $schema = $app->container->get(SchemaContract::class);
        self::assertSame([true, false], [$schema->hasTable('migrator_users'), $schema->hasTable('totals')], 'Each database has its own tables, and the schema is the default one\'s.');
        self::assertSame([false, true, true], [(new Schema($reports))->hasTable('migrator_users'), (new Schema($reports))->hasTable('totals'), (new Schema($reports))->hasTable('migrations')]);
        self::assertSame($app->container->get(MigratorContract::class), $app->container->get(\Crab\Database\DatabaseManager::class)->migrator('main'), 'The bound migrator is the default database\'s, so a path a package adds is used by the commands.');

        $mistakes = [
            'There is no database named "archive"; the configured ones are: reports, main, broken.' => static fn () => $main->connection('archive'),
            'The database "broken" has no "dsn" configured' => static fn () => $main->connection('broken'),
            'was made on its own and knows no other, so it has none named "reports"' => static fn () => (new Database(static fn () => new \PDO('sqlite::memory:')))->connection('reports'),
            'The default database is "missing", and no database of that name is configured' => static fn () => new \Crab\Database\DatabaseManager(['main' => []], 'missing'),
        ];
        foreach ($mistakes as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (DatabaseException|\LogicException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        self::assertStringContainsString('no database named \"archive\"', addslashes($this->run_($app, 'migrate', '--database=archive', expect: 1)));

        $app->container->get(\Crab\Database\DatabaseManager::class)->close();
        self::assertSame(1, $main->table('migrator_users')->count(), 'Closed databases reconnect when used.');
        $main->close();
        $reports->close();
        unlink($this->directory . '/reports.sqlite');
        foreach (glob($this->directory . '/reports/*.php') as $file) {
            unlink($file);
        }
        rmdir($this->directory . '/reports');
    }

    /**
     * Runs a command and returns what it wrote.
     */
    private function run_(App $app, string $command, string $argument = '', int $expect = 0): string
    {
        $out = fopen('php://memory', 'w+');
        $arguments = $argument === '' ? [$command] : [$command, ...explode(' ', $argument)];
        self::assertSame($expect, $app->console->run($arguments, new Output($out, $out)));
        rewind($out);
        return (string) stream_get_contents($out);
    }

    private function migration(string $path, string $up, string $down): void
    {
        file_put_contents($this->directory . "/{$path}.php", <<<PHP
            <?php
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
            PHP);
    }

    private function database(string $driver): Database
    {
        $db = new Database(fn (): \PDO => $this->pdo($driver), 1);
        foreach (self::TABLES as $table) {
            $db->execute("drop table if exists {$table}");
        }
        return $db;
    }

    private function pdo(string $driver): \PDO
    {
        if ($driver === 'mysql') {
            return new \PDO(getenv('CRAB_TEST_MYSQL_DSN'), getenv('CRAB_TEST_MYSQL_USER') ?: 'root', getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '');
        }
        $pdo = new \PDO('sqlite:' . $this->directory . '/app.sqlite');
        $pdo->exec('pragma foreign_keys = on');
        return $pdo;
    }
}
