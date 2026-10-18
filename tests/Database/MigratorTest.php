<?php

declare(strict_types=1);

namespace Crab\Tests\Database;

use Crab\App;
use Crab\Config\Config;
use Crab\Console\Output;
use Crab\Contracts\Clock\Clock;
use Crab\Contracts\Database\Database as DatabaseContract;
use Crab\Contracts\Database\Migrator as MigratorContract;
use Crab\Contracts\Database\Schema as SchemaContract;
use Crab\Database\Database;
use Crab\Database\DatabaseException;
use Crab\Database\DatabaseProvider;
use Crab\Database\Migrator;
use Crab\Database\PdoConnection;
use Crab\Database\Schema;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs against SQLite always, and against MySQL too when
 * CRAB_TEST_MYSQL_DSN names a database the tests may fill.
 */
final class MigratorTest extends TestCase
{
    use \Crab\Tests\Database\Mysql\AsyncDriverMatrix;

    private const TABLES = [
        'migrator_posts',
        'migrator_users',
        'migrator_teams',
        'migrator_half',
        'migrations',
        'migrator_log',
    ];

    private string $directory;

    /**
     * Children owned by this test, reaped before its temporary files go away.
     *
     * @var list<int>
     */
    private array $children = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-migrator-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/migrations', recursive: true);
        mkdir($this->directory . '/package');
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $pid) {
            $this->waitForChild($pid);
        }
        foreach (['/migrations', '/package', '/new/folder', '/new'] as $folder) {
            foreach (glob($this->directory . $folder . '/*.php') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($this->directory . $folder);
        }
        @unlink($this->directory . '/app.sqlite');
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            unlink($file);
        }
        rmdir($this->directory);
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $this->pdo('mysql')->exec('drop table if exists ' . implode(', ', self::TABLES));
        }
    }

    public static function drivers(): array
    {
        return getenv('CRAB_TEST_MYSQL_DSN') === false ? [['sqlite']] : [['sqlite'], ['mysql'], ['mysql-async']];
    }

    #[DataProvider('drivers')]
    public function testMigrationsRunOnceInOrderAndRollBackByBatch(string $driver): void
    {
        $db = $this->database($driver);
        $schema = new Schema($db);
        $clock = new FrozenClock('2026-01-31 12:00:00');
        $migrator = new Migrator($db, $this->directory . '/migrations/', clock: $clock);
        // Written out of order: the names decide.
        $this->migration(
            'migrations/2026_01_02_000000_create_posts',
            "\$schema->create('migrator_posts', function (Table \$t) { \$t->id(); \$t->foreignId('user_id')->references('migrator_users'); });",
            "\$schema->drop('migrator_posts');",
        );
        $this->migration(
            'migrations/2026_01_01_000000_create_users',
            "\$schema->create('migrator_users', function (Table \$t) { \$t->id(); \$t->string('name'); }); \$schema->connection()->table('migrator_users')->insert(['name' => 'seeded']);",
            "\$schema->drop('migrator_users');",
        );

        self::assertSame(
            ['2026_01_01_000000_create_users' => null, '2026_01_02_000000_create_posts' => null],
            $migrator->status(),
        );
        self::assertSame(['2026_01_01_000000_create_users', '2026_01_02_000000_create_posts'], $migrator->run());
        self::assertSame([true, true], [$schema->hasTable('migrator_users'), $schema->hasTable('migrator_posts')]);
        self::assertSame(
            ['seeded'],
            $db->table('migrator_users')->pluck('name'),
            'A migration may fill rows through the schema\'s connection.',
        );
        self::assertSame(
            ['2026_01_01_000000_create_users' => 1, '2026_01_02_000000_create_posts' => 1],
            $migrator->status(),
        );
        self::assertSame(['2026-01-31 12:00:00', '2026-01-31 12:00:00'], $db->table('migrations')->pluck('ran_at'));
        self::assertSame([], $migrator->run(), 'What has run does not run again.');

        $migrator->path($this->directory . '/package');
        $this->migration(
            'package/2026_01_03_000000_create_teams',
            "\$schema->create('migrator_teams', function (Table \$t) { \$t->id(); });",
            "\$schema->drop('migrator_teams');",
        );
        self::assertSame(['2026_01_03_000000_create_teams'], $migrator->run());
        self::assertSame(2, $migrator->status()['2026_01_03_000000_create_teams'], 'A later run is a later batch.');

        self::assertSame(['2026_01_03_000000_create_teams'], $migrator->rollback());
        self::assertSame([false, true], [$schema->hasTable('migrator_teams'), $schema->hasTable('migrator_posts')]);
        self::assertNull($migrator->status()['2026_01_03_000000_create_teams']);
        self::assertSame(
            ['2026_01_02_000000_create_posts', '2026_01_01_000000_create_users'],
            $migrator->rollback(),
            'Newest first, so that what points at a table goes before it.',
        );
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
        $this->migration(
            'migrations/2026_01_01_000000_create_users',
            "\$schema->create('migrator_users', function (Table \$t) { \$t->id(); });",
            "\$schema->drop('migrator_users');",
        );
        $this->migration(
            'migrations/2026_01_02_000000_half',
            "\$schema->create('migrator_half', function (Table \$t) { \$t->id(); }); \$schema->drop('no_such_table');",
            "throw new \\DomainException('cannot undo');",
        );
        $this->migration(
            'migrations/2026_01_03_000000_never',
            "\$schema->create('migrator_teams', function (Table \$t) { \$t->id(); });",
            '',
        );

        try {
            $migrator->run();
            self::fail('A failing migration was reported as run.');
        } catch (DatabaseException $error) {
            self::assertStringStartsWith(
                'The migration "2026_01_02_000000_half" could not be run: ',
                $error->getMessage(),
            );
            self::assertInstanceOf(DatabaseException::class, $error->getPrevious());
        }
        self::assertSame(
            [
                '2026_01_01_000000_create_users' => 1,
                '2026_01_02_000000_half' => null,
                '2026_01_03_000000_never' => null,
            ],
            $migrator->status(),
            'Those before it stay; it and those after it have not run.',
        );
        self::assertFalse($schema->hasTable('migrator_teams'));
        // SQLite takes the half-made table back with the transaction; MySQL cannot.
        self::assertSame($driver === 'mysql', $schema->hasTable('migrator_half'));

        unlink($this->directory . '/migrations/2026_01_02_000000_half.php');
        $this->migration(
            'migrations/2026_01_02_000000_half',
            "\$schema->create('migrator_log', function (Table \$t) { \$t->id(); });",
            "throw new \\DomainException('cannot undo');",
        );
        self::assertCount(2, $migrator->run());
        try {
            $migrator->rollback();
            self::fail('A rollback that failed was reported as done.');
        } catch (DatabaseException $error) {
            self::assertSame(
                'The migration "2026_01_02_000000_half" could not be rolled back: cannot undo',
                $error->getMessage(),
            );
        }
        self::assertSame(
            ['2026_01_01_000000_create_users' => 1, '2026_01_02_000000_half' => 2, '2026_01_03_000000_never' => null],
            $migrator->status(),
            'The newest of the batch was taken back; the one that failed is still recorded.',
        );

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
            'No migration directory is configured. Set "migrations" on its connection' => static fn() => new Migrator(
                $db,
            )->run(),
            'No migration directory is configured' => static fn() => new Migrator($db)->make('create users table'),
            'needs a name that says what it does' => fn() => new Migrator($db, $this->directory . '/migrations')->make(
                ' !! ',
            ),
        ];
        foreach ($mistakes as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (DatabaseException|\InvalidArgumentException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }

        file_put_contents(
            $this->directory . '/migrations/2026_01_01_000000_wrong.php',
            "<?php return ['not' => 'a migration'];",
        );
        try {
            new Migrator($db, $this->directory . '/migrations')->run();
            self::fail('A file that returns an array was run.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString(
                '"2026_01_01_000000_wrong" must return an object that implements Crab\Contracts\Database\Migration',
                $error->getMessage(),
            );
            self::assertStringContainsString('returned array', $error->getMessage());
        }
        file_put_contents($this->directory . '/package/2026_01_01_000000_wrong.php', '<?php return null;');
        try {
            new Migrator($db, [$this->directory . '/migrations', $this->directory . '/package'])->status();
            self::fail('Two migrations of one name were accepted.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString(
                'Two migrations are named "2026_01_01_000000_wrong"',
                $error->getMessage(),
            );
        }
        self::assertSame(
            [],
            new Migrator($db, $this->directory . '/empty')->status(),
            'A folder that is not there yet holds no migrations.',
        );
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
        self::assertStringStartsWith(
            "<?php\n\ndeclare(strict_types=1);\n\nuse Crab\\Contracts\\Database\\Migration;",
            $source,
        );

        $clock->advance('+1 minute');
        $plain = $migrator->make('add phone to users');
        self::assertStringEndsWith('/2026_01_31_120100_add_phone_to_users.php', $plain);
        self::assertStringContainsString(
            "    public function up(Schema \$schema): void\n    {\n        //\n    }",
            (string) file_get_contents($plain),
        );
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
        $app = new App(new Config([
            'database' => [
                'connections' => ['main' => [
                    'dsn' => 'sqlite:' . $this->directory . '/app.sqlite',
                    'migrations' => $this->directory . '/migrations',
                ]],
            ],
        ]));
        $app->container->instance(Clock::class, new FrozenClock('2026-01-31 12:00:00'));
        $app->register(DatabaseProvider::class);
        self::assertInstanceOf(Schema::class, $app->container->get(SchemaContract::class));
        self::assertInstanceOf(Migrator::class, $app->container->get(MigratorContract::class));

        self::assertSame("There are no migrations.\n", $this->run_($app, 'migrate:status'));
        self::assertSame("Nothing to migrate.\n", $this->run_($app, 'migrate'));
        self::assertSame("Nothing to roll back.\n", $this->run_($app, 'migrate:rollback'));
        self::assertSame(
            "Created {$this->directory}/migrations/2026_01_31_120000_create_migrator_users_table.php\n",
            $this->run_($app, 'make:migration', 'create_migrator_users_table'),
        );
        self::assertStringContainsString('Give the migration a name', $this->run_($app, 'make:migration', expect: 1));
        $this->migration(
            'migrations/2026_02_01_000000_create_posts',
            "\$schema->create('migrator_posts', function (Table \$t) { \$t->id(); \$t->foreignId('user_id')->references('migrator_users'); });",
            "\$schema->drop('migrator_posts');",
        );

        self::assertSame("Pending  2026_01_31_120000_create_migrator_users_table\nPending  2026_02_01_000000_create_posts\n", $this->run_(
            $app,
            'migrate:status',
        ));
        self::assertSame("Migrated     2026_01_31_120000_create_migrator_users_table\nMigrated     2026_02_01_000000_create_posts\n", $this->run_(
            $app,
            'migrate',
        ));
        self::assertSame("Ran (1)  2026_01_31_120000_create_migrator_users_table\nRan (1)  2026_02_01_000000_create_posts\n", $this->run_(
            $app,
            'migrate:status',
        ));

        $db = $app->container->get(DatabaseContract::class);
        try {
            $db->table('migrator_posts')->insert(['user_id' => 5]);
            self::fail('SQLite did not check a foreign key on a connection the provider opened.');
        } catch (DatabaseException) {
        }
        self::assertSame("Rolled back  2026_02_01_000000_create_posts\nRolled back  2026_01_31_120000_create_migrator_users_table\n", $this->run_(
            $app,
            'migrate:rollback',
        ));
        $db->close();

        $unset = new App(new Config(['database' => ['connections' => ['main' => ['dsn' => 'sqlite::memory:']]]]));
        $unset->register(DatabaseProvider::class);
        self::assertStringContainsString('Set "migrations" on its connection', $this->run_(
            $unset,
            'migrate',
            expect: 1,
        ));
    }

    public function testSeveralDatabasesAreConfiguredByNameAndReachedFromOneAnother(): void
    {
        mkdir($this->directory . '/reports');
        $app = new App(new Config([
            'database' => [
                'default' => 'main',
                'connections' => [
                    'reports' => [
                        'dsn' => 'sqlite:' . $this->directory . '/reports.sqlite',
                        'migrations' => $this->directory . '/reports',
                        'pool' => 3,
                    ],
                    'main' => [
                        'dsn' => 'sqlite:' . $this->directory . '/app.sqlite',
                        'migrations' => $this->directory . '/migrations',
                    ],
                    'broken' => ['username' => 'nobody'],
                ],
            ],
        ]));
        $app->register(DatabaseProvider::class);
        $this->migration(
            'migrations/2026_01_01_000000_create_users',
            "\$schema->create('migrator_users', function (Table \$t) { \$t->id(); \$t->string('name'); });",
            "\$schema->drop('migrator_users');",
        );
        $this->migration(
            'reports/2026_01_01_000000_create_totals',
            "\$schema->create('totals', function (Table \$t) { \$t->id(); \$t->integer('amount'); });",
            "\$schema->drop('totals');",
        );

        self::assertSame("Migrated     2026_01_01_000000_create_users\n", $this->run_($app, 'migrate'));
        self::assertSame("Pending  2026_01_01_000000_create_totals\n", $this->run_(
            $app,
            'migrate:status',
            '--database=reports',
        ));
        self::assertSame("Migrated     2026_01_01_000000_create_totals\n", $this->run_(
            $app,
            'migrate',
            '--database=reports',
        ));
        self::assertStringContainsString('/reports/', $this->run_(
            $app,
            'make:migration',
            '--database=reports add_day_to_totals',
        ));

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
        self::assertSame(
            [true, false],
            [$schema->hasTable('migrator_users'), $schema->hasTable('totals')],
            'Each database has its own tables, and the schema is the default one\'s.',
        );
        self::assertSame([false, true, true], [
            new Schema($reports)->hasTable('migrator_users'),
            new Schema($reports)->hasTable('totals'),
            new Schema($reports)->hasTable('migrations'),
        ]);
        self::assertSame(
            $app->container->get(MigratorContract::class),
            $app->container->get(\Crab\Database\DatabaseManager::class)->migrator('main'),
            'The bound migrator is the default database\'s, so a path a package adds is used by the commands.',
        );

        $mistakes = [
            'There is no database named "archive"; the configured ones are: reports, main, broken.' =>
                static fn() => $main->connection('archive'),
            'The database "broken" has no "dsn" configured' => static fn() => $main->connection('broken'),
            'was made on its own and knows no other, so it has none named "reports"' => static fn() => new Database(
                static fn() => new \PDO('sqlite::memory:'),
            )->connection('reports'),
            'The default database is "missing", and no database of that name is configured' =>
                static fn() => new \Crab\Database\DatabaseManager(['main' => []], 'missing'),
        ];
        foreach ($mistakes as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (DatabaseException|\LogicException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        self::assertStringContainsString(
            'no database named \"archive\"',
            addslashes($this->run_($app, 'migrate', '--database=archive', expect: 1)),
        );

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

    #[DataProvider('drivers')]
    public function testTwoDeploymentsRunEachPendingMigrationOnlyOnce(string $driver): void
    {
        $this->requireFork();
        $this->prepareConcurrentDatabase($driver);
        $held = $this->directory . '/held';
        $release = $this->directory . '/release';
        $names = $this->lockMigrations($held, $release);
        $start = $this->directory . '/start';
        $jobs = [];
        for ($index = 0; $index < 2; ++$index) {
            $ready = $this->directory . '/ready-' . $index;
            $jobs[] = $this->forkWork($driver, function (Database $database) use ($start, $ready): array {
                file_put_contents($ready, 'ready');
                $this->waitForFile($start);
                $migrator = new Migrator($database, $this->directory . '/migrations');
                return $migrator->run();
            });
        }
        $this->waitForFile($this->directory . '/ready-0');
        $this->waitForFile($this->directory . '/ready-1');
        file_put_contents($start, 'go');
        $this->waitForFile($held);
        file_put_contents($release, 'go');

        $results = [];
        foreach ($jobs as $job) {
            $results[] = $this->childResult($job);
        }
        $reported = array_merge(...$results);
        sort($reported);
        self::assertSame($names, $reported, 'Each migration must be reported by exactly one deployment.');
        $database = $this->concurrentDatabase($driver);
        self::assertSame($names, $database->table('migrations')->orderBy('id')->pluck('name'));
        self::assertSame(3, $database->table('migrations')->count());
        $database->close();
    }

    #[DataProvider('drivers')]
    public function testAThrowingCommitCallbackStopsBeforeTheNextMigration(string $driver): void
    {
        $database = $this->database($driver);
        $first = '2026_01_01_000000_first';
        $second = '2026_01_02_000000_second';
        $this->migration(
            'migrations/' . $first,
            "\$schema->create('migrator_users', function (Table \$table) { \$table->id(); }); \$schema->connection()->afterCommit(static fn () => throw new \\DomainException('commit callback failed'));",
            "\$schema->drop('migrator_users');",
        );
        $this->migration(
            'migrations/' . $second,
            "\$schema->create('migrator_posts', function (Table \$table) { \$table->id(); });",
            "\$schema->drop('migrator_posts');",
        );
        $migrator = new Migrator($database, $this->directory . '/migrations');
        try {
            $migrator->run();
            self::fail('The commit callback should fail the run.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString(
                'The migration "' . $first . '" could not be run: commit callback failed',
                $error->getMessage(),
            );
            self::assertInstanceOf(\DomainException::class, $error->getPrevious());
        }
        self::assertSame([$first => $driver === 'sqlite' ? 1 : null, $second => null], $migrator->status());
        self::assertSame($driver === 'sqlite' ? [$first] : [], $database->table('migrations')->pluck('name'));
        self::assertTrue(new Schema($database)->hasTable('migrator_users'));
        self::assertFalse(new Schema($database)->hasTable('migrator_posts'));
        $this->assertLockIsFree($database);
        $database->close();
    }

    #[DataProvider('drivers')]
    public function testAThrowingRollbackCommitCallbackStopsBeforeTheNextRollback(string $driver): void
    {
        $database = $this->database($driver);
        $first = '2026_01_01_000000_first';
        $second = '2026_01_02_000000_second';
        $this->migration(
            'migrations/' . $first,
            "\$schema->create('migrator_users', function (Table \$table) { \$table->id(); });",
            "\$schema->drop('migrator_users');",
        );
        $this->migration(
            'migrations/' . $second,
            "\$schema->create('migrator_posts', function (Table \$table) { \$table->id(); });",
            "\$schema->drop('migrator_posts'); \$schema->connection()->afterCommit(static fn () => throw new \\DomainException('rollback callback failed'));",
        );
        $migrator = new Migrator($database, $this->directory . '/migrations');
        self::assertSame([$first, $second], $migrator->run());
        try {
            $migrator->rollback();
            self::fail('The commit callback should fail the rollback.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString(
                'The migration "' . $second . '" could not be rolled back: rollback callback failed',
                $error->getMessage(),
            );
            self::assertInstanceOf(\DomainException::class, $error->getPrevious());
        }
        self::assertSame([$first => 1, $second => $driver === 'sqlite' ? null : 1], $migrator->status());
        self::assertTrue(new Schema($database)->hasTable('migrator_users'));
        self::assertFalse(new Schema($database)->hasTable('migrator_posts'));
        $this->assertLockIsFree($database);
        $database->close();
    }

    public function testASqliteMigrationRecordedAfterItsSnapshotIsSkippedWithoutCallingUp(): void
    {
        $this->requireFork();
        $this->prepareConcurrentDatabase('sqlite');
        $database = $this->concurrentDatabase('sqlite');
        new Migrator($database, $this->directory . '/migrations')->run();
        $database->close();
        $ready = $this->directory . '/snapshot-read';
        $release = $this->directory . '/release';
        $called = $this->directory . '/up-called';
        $name = '2026_01_01_000000_other_deployment';
        $this->migration(
            'migrations/' . $name,
            'file_put_contents(' . var_export($called, true) . ", 'up');",
            '',
            $this->barrierCode($ready, $release),
        );
        $job = $this->forkWork('sqlite', function (Database $database): array {
            return new Migrator($database, $this->directory . '/migrations')->run();
        });
        $this->waitForFile($ready);
        $database = $this->concurrentDatabase('sqlite');
        try {
            $database->table('migrations')->insert(['name' => $name, 'batch' => 1, 'ran_at' => '2026-01-01 00:00:00']);
        } finally {
            file_put_contents($release, 'go');
        }
        self::assertSame([], $this->childResult($job));
        self::assertFileDoesNotExist($called, 'The transaction must recheck the stale snapshot before calling up().');
        self::assertSame([$name], $database->table('migrations')->pluck('name'));
        $database->close();
    }

    #[DataProvider('drivers')]
    public function testTwoRollbacksTakeBackEachMigrationOnlyOnce(string $driver): void
    {
        $this->requireFork();
        $this->prepareConcurrentDatabase($driver);
        $phase = $this->directory . '/rollback-phase';
        $loaded = $this->directory . '/loaded-';
        $release = $this->directory . '/release';
        $held = $this->directory . '/held';
        $log = $this->directory . '/down-calls';
        $names = [];
        foreach (['users', 'posts', 'teams'] as $index => $table) {
            $name = '2026_01_0' . ($index + 1) . '_000000_create_' . $table;
            $names[] = $name;
            $down = $driver === 'mysql' && $index === 2 ? $this->barrierCode($held, $release) : '';
            $down .=
                'file_put_contents('
                . var_export($log, true)
                . ', '
                . var_export($name . "\n", true)
                . ', FILE_APPEND | LOCK_EX);';
            $down .= "\$schema->drop('migrator_{$table}');";
            $beforeLoad = $driver === 'sqlite'
                ? 'if (is_file('
                . var_export($phase, true)
                . ')) {'
                . $this->barrierCode($loaded, $release, perProcess: true)
                . '}'
                : '';
            $this->migration(
                'migrations/' . $name,
                "\$schema->create('migrator_{$table}', function (Table \$table) { \$table->id(); });",
                $down,
                $beforeLoad,
            );
        }
        $database = $this->concurrentDatabase($driver);
        self::assertSame($names, new Migrator($database, $this->directory . '/migrations')->run());
        $database->close();
        file_put_contents($phase, 'rollback');
        $start = $this->directory . '/start';
        $jobs = [];
        for ($index = 0; $index < 2; ++$index) {
            $ready = $this->directory . '/ready-' . $index;
            $jobs[] = $this->forkWork($driver, function (Database $database) use ($ready, $start): array {
                file_put_contents($ready, 'ready');
                $this->waitForFile($start);
                return new Migrator($database, $this->directory . '/migrations')->rollback();
            });
        }
        $this->waitForFile($this->directory . '/ready-0');
        $this->waitForFile($this->directory . '/ready-1');
        file_put_contents($start, 'go');
        if ($driver === 'sqlite') {
            foreach ($jobs as $job) {
                $this->waitForFile($loaded . $job['pid']);
            }
        } else {
            $this->waitForFile($held);
        }
        file_put_contents($release, 'go');
        $reported = array_merge($this->childResult($jobs[0]), $this->childResult($jobs[1]));
        sort($reported);
        self::assertSame($names, $reported);
        $calls = file($log, FILE_IGNORE_NEW_LINES);
        sort($calls);
        self::assertSame($names, $calls, 'Each down() must be called only once, even with stale rollback snapshots.');
        $database = $this->concurrentDatabase($driver);
        self::assertSame(0, $database->table('migrations')->count());
        foreach (['users', 'posts', 'teams'] as $table) {
            self::assertFalse(new Schema($database)->hasTable('migrator_' . $table));
        }
        $database->close();
    }

    #[DataProvider('drivers')]
    public function testAFailedMigrationReleasesTheLockForTheNextRun(string $driver): void
    {
        $database = $this->database($driver);
        $migrator = new Migrator($database, $this->directory . '/migrations', lockTimeout: 0);
        $name = '2026_01_01_000000_bad';
        $this->migration('migrations/' . $name, "throw new \\DomainException('broken migration');", '');
        try {
            $migrator->run();
            self::fail('The broken migration should fail.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('broken migration', $error->getMessage());
        }
        $this->assertLockIsFree($database);
        $this->migration(
            'migrations/' . $name,
            "\$schema->create('migrator_users', function (Table \$table) { \$table->id(); });",
            "\$schema->drop('migrator_users');",
        );
        self::assertSame([$name], $migrator->run());
        $this->assertLockIsFree($database);

        $other = $this->concurrentDatabase($driver);
        self::assertSame([], new Migrator($other, $this->directory . '/migrations', lockTimeout: 0)->run());
        $other->close();
        $database->close();
    }

    #[DataProvider('drivers')]
    public function testAFailedRollbackReleasesTheLockForTheNextRollback(string $driver): void
    {
        $database = $this->database($driver);
        $migrator = new Migrator($database, $this->directory . '/migrations', lockTimeout: 0);
        $name = '2026_01_01_000000_users';
        $up = "\$schema->create('migrator_users', function (Table \$table) { \$table->id(); });";
        $this->migration('migrations/' . $name, $up, "throw new \\DomainException('broken rollback');");
        self::assertSame([$name], $migrator->run());
        try {
            $migrator->rollback();
            self::fail('The broken rollback should fail.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('broken rollback', $error->getMessage());
        }
        $this->assertLockIsFree($database);
        $this->migration('migrations/' . $name, $up, "\$schema->drop('migrator_users');");
        $other = $this->concurrentDatabase($driver);
        self::assertSame([$name], new Migrator($other, $this->directory . '/migrations', lockTimeout: 0)->rollback());
        $other->close();
        $database->close();
    }

    #[DataProvider('drivers')]
    public function testAWaitingDeploymentTimesOutWhileTheFirstOneFinishes(string $driver): void
    {
        $this->requireFork();
        $this->prepareConcurrentDatabase($driver);
        $started = $this->directory . '/started';
        $release = $this->directory . '/release';
        $names = $this->lockMigrations($started, $release);
        $job = $this->forkWork($driver, function (Database $database): array {
            return new Migrator($database, $this->directory . '/migrations')->run();
        });
        $this->waitForFile($started);
        $database = $this->concurrentDatabase($driver);
        try {
            new Migrator($database, $this->directory . '/migrations', lockTimeout: 0)->run();
            self::fail('The waiting deployment should time out.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('Another process has been migrating for 0 seconds', $error->getMessage());
            self::assertStringContainsString('migrate:status', $error->getMessage());
        } finally {
            file_put_contents($release, 'go');
        }
        if ($driver === 'sqlite') {
            self::assertSame(5000, $database->first('PRAGMA busy_timeout')['timeout']);
        }
        self::assertSame($names, $this->childResult($job));
        self::assertSame(3, $database->table('migrations')->count());
        self::assertSame([], new Migrator($database, $this->directory . '/migrations', lockTimeout: 0)->run());
        $database->close();
    }

    #[DataProvider('drivers')]
    public function testRollbackUsesCommittedHistoryAndTheMysqlDeploymentLock(string $driver): void
    {
        $this->requireFork();
        $this->prepareConcurrentDatabase($driver);
        $started = $this->directory . '/started';
        $release = $this->directory . '/release';
        $names = $this->lockMigrations($started, $release);
        $job = $this->forkWork($driver, function (Database $database): array {
            return new Migrator($database, $this->directory . '/migrations')->run();
        });
        $this->waitForFile($started);
        $database = $this->concurrentDatabase($driver);
        try {
            $migrator = new Migrator($database, $this->directory . '/migrations', lockTimeout: 0);
            if ($driver === 'sqlite') {
                self::assertSame([], $migrator->rollback(), 'The first migration has not committed any history yet.');
            } else {
                try {
                    $migrator->rollback();
                    self::fail('A MySQL rollback must take the active run\'s lock.');
                } catch (DatabaseException $error) {
                    self::assertStringContainsString('migrate:status', $error->getMessage());
                }
            }
        } finally {
            file_put_contents($release, 'go');
        }
        self::assertSame($names, $this->childResult($job));
        self::assertSame(array_reverse($names), new Migrator($database, $this->directory . '/migrations')->rollback());
        self::assertSame(0, $database->table('migrations')->count());
        self::assertFalse(new Schema($database)->hasTable('migrator_users'));
        $database->close();
    }

    #[DataProvider('drivers')]
    public function testStatusAndMakeAnswerWhileAnotherDeploymentHoldsTheLock(string $driver): void
    {
        $this->requireFork();
        $this->prepareConcurrentDatabase($driver);
        $started = $this->directory . '/started';
        $release = $this->directory . '/release';
        $names = $this->lockMigrations($started, $release);
        $job = $this->forkWork($driver, function (Database $database): array {
            return new Migrator($database, $this->directory . '/migrations')->run();
        });
        $this->waitForFile($started);
        $database = $this->concurrentDatabase($driver);
        $migrator = new Migrator($database, $this->directory . '/migrations', lockTimeout: 0);
        try {
            self::assertSame(array_fill_keys($names, null), $migrator->status());
            $file = $migrator->make('add phone to users');
            self::assertFileExists($file);
        } finally {
            file_put_contents($release, 'go');
        }
        self::assertSame($names, $this->childResult($job));
        $status = $migrator->status();
        foreach ($names as $name) {
            self::assertSame(1, $status[$name]);
        }
        self::assertNull($status[basename($file, '.php')]);
        $database->close();
    }

    public function testStatusDoesNotCreateTheMigrationsTable(): void
    {
        $database = $this->database('sqlite');
        self::assertSame([], new Migrator($database, $this->directory . '/migrations')->status());
        self::assertFalse(new Schema($database)->hasTable('migrations'));
        $database->close();
    }

    public function testTheProviderPassesTheLockTimeoutToEveryNamedMigrator(): void
    {
        $this->requireFork();
        $this->prepareConcurrentDatabase('sqlite');
        $started = $this->directory . '/started';
        $release = $this->directory . '/release';
        $names = $this->lockMigrations($started, $release);
        $job = $this->forkWork('sqlite', function (Database $database): array {
            return new Migrator($database, $this->directory . '/migrations')->run();
        });
        $this->waitForFile($started);
        try {
            foreach ([false, true] as $several) {
                $settings = [
                    'dsn' => 'sqlite:' . $this->directory . '/app.sqlite',
                    'migrations' => $this->directory . '/migrations',
                ];
                $configuration = [
                    'connections' => $several ? ['main' => $settings, 'reports' => $settings] : ['main' => $settings],
                ];
                $configuration['migration_lock_timeout'] = 0;
                $app = new App(new Config(['database' => $configuration]));
                $app->register(DatabaseProvider::class);
                $manager = $app->container->get(\Crab\Database\DatabaseManager::class);
                foreach ($manager->names() as $name) {
                    try {
                        $manager->migrator($name)->run();
                        self::fail('The provider should pass the configured lock timeout.');
                    } catch (DatabaseException $error) {
                        self::assertStringContainsString('for 0 seconds', $error->getMessage());
                        self::assertStringContainsString('migrate:status', $error->getMessage());
                    }
                }
                $manager->close();
            }
        } finally {
            file_put_contents($release, 'go');
        }
        self::assertSame($names, $this->childResult($job));

        $defaults = new Config(['database' => ['connections' => ['main' => ['dsn' => 'sqlite::memory:']]]]);
        $app = new App($defaults);
        $app->register(DatabaseProvider::class);
        self::assertSame(60, $defaults->get('database.migration_lock_timeout'));
    }

    public function testTheLockTimeoutCannotBeNegativeAndZeroDoesNotWait(): void
    {
        $database = $this->database('sqlite');
        try {
            new Migrator($database, $this->directory . '/migrations', lockTimeout: -1);
            self::fail('The negative lock timeout should be refused.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('database.migration_lock_timeout', $error->getMessage());
        }
        self::assertSame([], new Migrator($database, $this->directory . '/migrations', lockTimeout: 0)->run());
        $database->close();
    }

    public function testTheProviderRefusesInvalidLockTimeoutsAndCastsFractionsToWholeSeconds(): void
    {
        foreach ([-1, 'later', null, INF, NAN] as $timeout) {
            $app = new App(new Config([
                'database' => [
                    'connections' => ['main' => ['dsn' => 'sqlite::memory:']],
                    'migration_lock_timeout' => $timeout,
                ],
            ]));
            $app->register(DatabaseProvider::class);
            try {
                $app->container->get(MigratorContract::class);
                self::fail('The invalid configured lock timeout should be refused.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('database.migration_lock_timeout', $error->getMessage());
            }
        }
        $settings = ['dsn' => 'sqlite::memory:', 'migrations' => $this->directory . '/migrations'];
        $app = new App(new Config([
            'database' => [
                'connections' => ['main' => $settings, 'reports' => $settings],
                'migration_lock_timeout' => 1.9,
            ],
        ]));
        $app->register(DatabaseProvider::class);
        $manager = $app->container->get(\Crab\Database\DatabaseManager::class);
        $timeout = new \ReflectionProperty(Migrator::class, 'lockTimeout');
        foreach ($manager->names() as $name) {
            self::assertSame(
                1,
                $timeout->getValue($manager->migrator($name)),
                'The provider must cast before calling the integer constructor.',
            );
        }
        $manager->close();
    }

    public static function lockLossPlaces(): array
    {
        return ['killed session between migrations' => [true], 'unlocked before returning' => [false]];
    }

    #[DataProvider('lockLossPlaces')]
    public function testAMysqlLockLossStopsTheRunBeforeMoreWorkOrReturning(bool $betweenMigrations): void
    {
        if (getenv('CRAB_TEST_MYSQL_DSN') === false) {
            self::markTestSkipped('Killing this test\'s MySQL session needs CRAB_TEST_MYSQL_DSN.');
        }
        $database = $this->database('mysql');
        $session = $this->directory . '/lock-session';
        $first = '2026_01_01_000000_first';
        $second = '2026_01_02_000000_second';
        $up = "\$schema->create('migrator_users', function (Table \$table) { \$table->id(); });";
        $up .=
            'file_put_contents('
            . var_export($session, true)
            . ', (string) $schema->connection()->first(\'SELECT CONNECTION_ID() AS id\')[\'id\']);';
        if (!$betweenMigrations) {
            // Keep the session usable so the history insert finishes and
            // only the final ownership check can detect this lost lock.
            $up .= '$schema->connection()->first(\'SELECT RELEASE_LOCK(?) AS released\', [\'crab_migrations_migrations\']);';
        }
        $this->migration('migrations/' . $first, $up, "\$schema->drop('migrator_users');");
        if ($betweenMigrations) {
            // File loading happens after the first migration and its history
            // insert finish, but before the next migration's ownership check.
            $this->migration(
                'migrations/' . $second,
                "\$schema->create('migrator_posts', function (Table \$table) { \$table->id(); });",
                "\$schema->drop('migrator_posts');",
                $this->mysqlKillCode($session),
            );
        }
        $migrator = new Migrator($database, $this->directory . '/migrations');
        try {
            $migrator->run();
            self::fail('A run that lost its MySQL lock must not succeed.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('database connection was lost during migration', $error->getMessage());
            self::assertStringContainsString('may be half applied', $error->getMessage());
            self::assertStringContainsString('migrate:status', $error->getMessage());
        }
        self::assertSame([$first], $database->table('migrations')->pluck('name'));
        self::assertFalse(new Schema($database)->hasTable('migrator_posts'));
        $this->assertLockIsFree($database);
        $database->close();
    }

    /**
     * Opens an independent connection and kills only the session whose id
     * the preceding migration recorded. KILL uses a validated integer id.
     */
    private function mysqlKillCode(string $session): string
    {
        $session = var_export($session, true);
        return <<<PHP
            \$session = (int) file_get_contents({$session});
            if (\$session < 1) {
                throw new \\RuntimeException('The migration did not record its MySQL session id.');
            }
            \$killer = new \\PDO((string) getenv('CRAB_TEST_MYSQL_DSN'), getenv('CRAB_TEST_MYSQL_USER') ?: 'root', getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '');
            \$killer->exec('KILL CONNECTION ' . \$session);
            \$killer = null;
            PHP;
    }

    public function testDifferentMigrationTablesUseDifferentMysqlLocks(): void
    {
        if (getenv('CRAB_TEST_MYSQL_DSN') === false) {
            self::markTestSkipped('MySQL lock names need CRAB_TEST_MYSQL_DSN.');
        }
        $holder = $this->database('mysql');
        $other = $this->concurrentDatabase('mysql');
        $lock = 'crab_migrations_migrations';
        self::assertSame(1, (int) $holder->first('SELECT GET_LOCK(?, ?) AS acquired', [$lock, 0])['acquired']);
        try {
            self::assertSame(
                [],
                new Migrator($other, $this->directory . '/migrations', table: 'migrator_log', lockTimeout: 0)->run(),
            );
            try {
                new Migrator($other, $this->directory . '/migrations', lockTimeout: 0)->run();
                self::fail('Migrators sharing a table must share a lock.');
            } catch (DatabaseException $error) {
                self::assertStringContainsString('migrate:status', $error->getMessage());
            }
        } finally {
            $holder->first('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            $holder->close();
            $other->close();
        }
    }

    #[DataProvider('drivers')]
    public function testAMigratorCanUseOneConnectionWithoutADatabasePool(string $driver): void
    {
        $this->database($driver)->close();
        $names = $this->lockMigrations();
        $owner = fn(): \PDO => $this->pdo($driver);
        $factory = $this->mysqlFactory($driver);
        $connection = $factory === null ? new PdoConnection($owner) : $factory($owner);
        if ($this->matrixWorker !== null) {
            self::assertInstanceOf(\Crab\Database\Mysql\Connection::class, $connection);
        }
        try {
            $migrator = new Migrator($connection, $this->directory . '/migrations');
            self::assertSame($names, $migrator->run());
            self::assertSame([], $migrator->run());
            self::assertSame(array_reverse($names), $migrator->rollback());
            self::assertSame(array_fill_keys($names, null), $migrator->status());
        } finally {
            $connection->close();
        }
    }

    /**
     * Makes three migrations. A holder signals from inside the first
     * migration and waits for the parent's release instead of sleeping.
     *
     * @return list<string>
     */
    private function lockMigrations(?string $started = null, ?string $release = null): array
    {
        $names = [];
        foreach (['users', 'posts', 'teams'] as $index => $table) {
            $name = '2026_01_0' . ($index + 1) . '_000000_create_' . $table;
            $up = $index === 0 && $started !== null ? $this->barrierCode($started, $release) : '';
            $up .= "\$schema->create('migrator_{$table}', function (Table \$table) { \$table->id(); });";
            $this->migration('migrations/' . $name, $up, "\$schema->drop('migrator_{$table}');");
            $names[] = $name;
        }
        return $names;
    }

    /**
     * Migration fixtures cannot call the parent's test instance. Their
     * file barrier has a failsafe and may signal separately for each PID.
     */
    private function barrierCode(string $started, string $release, bool $perProcess = false): string
    {
        $signal = var_export($started, true) . ($perProcess ? ' . getmypid()' : '');
        $release = var_export($release, true);
        return <<<PHP
            file_put_contents({$signal}, 'ready');
            \$deadline = hrtime(true) + 10_000_000_000;
            do {
                clearstatcache(true, {$release});
                if (is_file({$release})) {
                    break;
                }
                if (hrtime(true) >= \$deadline) {
                    throw new \\RuntimeException('The parent did not release the migration barrier.');
                }
                usleep(5_000);
            } while (true);
            PHP;
    }

    /**
     * Enables WAL before the race, so two new processes do not race over
     * changing the journal mode. No parent connection survives the fork.
     */
    private function prepareConcurrentDatabase(string $driver): void
    {
        $database = $this->database($driver);
        if ($driver === 'sqlite') {
            $database->execute('PRAGMA journal_mode = wal');
        }
        $database->close();
    }

    /**
     * Each child opens its own pool after the fork, without clearing the
     * shared tables. SQLite uses the provider's WAL and waiting settings.
     */
    private function concurrentDatabase(string $driver): Database
    {
        return new Database(
            function () use ($driver): \PDO {
                $pdo = $this->pdo($driver);
                if ($driver === 'sqlite') {
                    $pdo->exec('PRAGMA journal_mode = wal');
                    $pdo->exec('PRAGMA busy_timeout = 5000');
                }
                return $pdo;
            },
            1,
            factory: $this->mysqlFactory($driver),
        );
    }

    /**
     * Fork-dependent coverage is optional on platforms without pcntl.
     */
    private function requireFork(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('Concurrent migration tests need pcntl_fork and posix_kill for child cleanup.');
        }
    }

    /**
     * Saves results outside PHPUnit so children cannot print into the
     * parent's test output or inherit an open database connection.
     *
     * @param \Closure(Database): list<string> $work
     * @return array{pid: int, file: string}
     */
    private function forkWork(string $driver, \Closure $work): array
    {
        $file = $this->directory . '/result-' . bin2hex(random_bytes(4)) . '.json';
        $pid = pcntl_fork();
        if ($pid === -1) {
            self::fail('Cannot fork the migration worker.');
        }
        if ($pid === 0) {
            $database = $this->concurrentDatabase($driver);
            try {
                $result = ['result' => $work($database)];
            } catch (\Throwable $error) {
                $result = ['error' => $error::class . ': ' . $error->getMessage()];
            } finally {
                $database->close();
            }
            file_put_contents($file, json_encode($result, JSON_THROW_ON_ERROR));
            exit(0);
        }
        $this->children[] = $pid;
        return ['pid' => $pid, 'file' => $file];
    }

    /**
     * Waits only for a child this test owns and checks its reported result.
     *
     * @param array{pid: int, file: string} $job
     * @return list<string>
     */
    private function childResult(array $job): array
    {
        $this->waitForChild($job['pid']);
        $result = json_decode((string) file_get_contents($job['file']), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('error', $result, $result['error'] ?? '');
        return $result['result'];
    }

    /**
     * Bounds failed concurrency tests, terminating only their own child
     * by PID if it cannot finish, never a process found by name.
     */
    private function waitForChild(int $pid): void
    {
        $deadline = hrtime(true) + 10_000_000_000;
        do {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited !== 0) {
                $this->children = array_values(array_diff($this->children, [$pid]));
                self::assertSame($pid, $waited);
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));
                return;
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
        $this->children = array_values(array_diff($this->children, [$pid]));
        self::fail('The migration worker did not finish within ten seconds.');
    }

    /**
     * File barriers coordinate only these processes and need no server.
     */
    private function waitForFile(string $file): void
    {
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            clearstatcache(true, $file);
            if (is_file($file)) {
                return;
            }
            usleep(5_000);
        } while (hrtime(true) < $deadline);
        throw new \RuntimeException('The migration worker did not reach the expected file barrier.');
    }

    /**
     * A same-session MySQL lock is reentrant, so retrying alone would not
     * prove release. Also checks that SQLite's temporary timeout is restored.
     */
    private function assertLockIsFree(Database $database): void
    {
        if ($database->driver() === 'mysql') {
            self::assertSame(
                1,
                (int) $database->first('SELECT IS_FREE_LOCK(?) AS available', [
                    'crab_migrations_migrations',
                ])['available'],
            );
        } else {
            self::assertSame(60_000, $database->first('PRAGMA busy_timeout')['timeout']);
        }
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

    private function migration(string $path, string $up, string $down, string $beforeLoad = ''): void
    {
        file_put_contents($this->directory . "/{$path}.php", <<<PHP
            <?php
            use Crab\Contracts\Database\Migration;
            use Crab\Contracts\Database\Schema;
            use Crab\Contracts\Database\Table;

            {$beforeLoad}

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
        $db = new Database(fn(): \PDO => $this->pdo($driver), 1, factory: $this->mysqlFactory($driver));
        foreach (self::TABLES as $table) {
            $db->execute("drop table if exists {$table}");
        }
        return $db;
    }

    private function pdo(string $driver): \PDO
    {
        if ($driver === 'mysql') {
            return new \PDO(
                getenv('CRAB_TEST_MYSQL_DSN'),
                getenv('CRAB_TEST_MYSQL_USER') ?: 'root',
                getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '',
            );
        }
        $pdo = new \PDO('sqlite:' . $this->directory . '/app.sqlite');
        $pdo->exec('pragma foreign_keys = on');
        return $pdo;
    }
}
