<?php

declare(strict_types=1);

namespace Crab\Tests\Database;

use Crab\Contracts\Database\Table as TableContract;
use Crab\Database\Database;
use Crab\Database\DatabaseException;
use Crab\Database\MySqlSchemaGrammar;
use Crab\Database\Schema;
use Crab\Database\SchemaGrammar;
use Crab\Database\SqliteSchemaGrammar;
use Crab\Database\Table;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs against SQLite always, and against MySQL too when
 * CRAB_TEST_MYSQL_DSN names a database the tests may fill.
 */
final class SchemaTest extends TestCase
{
    use \Crab\Tests\Database\Mysql\AsyncDriverMatrix;

    private const TABLES = [
        'schema_comments',
        'schema_posts',
        'schema_articles',
        'schema_members',
        'schema_users',
        'schema_everything',
    ];

    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/crab-schema-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $this->pdo('mysql')->exec('drop table if exists ' . implode(', ', self::TABLES));
        }
    }

    public static function drivers(): array
    {
        return getenv('CRAB_TEST_MYSQL_DSN') === false ? [['sqlite']] : [['sqlite'], ['mysql'], ['mysql-async']];
    }

    public function testATableIsWrittenInEachDialect(): void
    {
        $table = new Table('users');
        $table->id();
        $table->foreignId('team_id')->nullable()->references('teams', onDelete: 'SET NULL');
        $table->string('email', 120)->unique();
        $table->string('name')->default("O'Brien \\ Co")->index();
        $table->integer('age')->unsigned()->nullable();
        $table->bigInteger('views')->default(0);
        $table->boolean('active')->default(true);
        $table->decimal('balance', 12, 4)->default(1.5);
        $table->float('score')->nullable()->default(null);
        $table->text('bio')->nullable();
        $table->date('born')->nullable();
        $table->dateTime('seen_at')->useCurrent();
        $table->json('settings')->nullable();
        $table->binary('avatar')->nullable();
        $table->timestamps();
        $table->index(['name', 'age']);
        $table->unique(['team_id', 'name'], 'one_name_per_team');

        self::assertSame(
            [
                'create table `users` (`id` integer primary key autoincrement, `team_id` integer null, `email` varchar(120) not null,'
                    . " `name` varchar(255) not null default 'O''Brien \\ Co', `age` integer null, `views` integer not null default 0,"
                    . ' `active` integer not null default 1, `balance` numeric not null default 1.5, `score` real null default null,'
                    . ' `bio` text null, `born` date null, `seen_at` datetime not null default current_timestamp, `settings` text null,'
                    . ' `avatar` blob null, `created_at` datetime null, `updated_at` datetime null,'
                    . ' foreign key (`team_id`) references `teams` (`id`) on delete set null)',
                'create unique index `users_email_unique` on `users` (`email`)',
                'create index `users_name_index` on `users` (`name`)',
                'create index `users_name_age_index` on `users` (`name`, `age`)',
                'create unique index `one_name_per_team` on `users` (`team_id`, `name`)',
            ],
            new SqliteSchemaGrammar()->create($table),
        );

        $mysql = new MySqlSchemaGrammar()->create($table);
        self::assertSame(
            'create table `users` (`id` bigint unsigned not null auto_increment primary key, `team_id` bigint unsigned null, `email` varchar(120) not null,'
            . " `name` varchar(255) not null default 'O''Brien \\\\ Co', `age` int unsigned null, `views` bigint not null default 0,"
            . ' `active` tinyint(1) not null default 1, `balance` decimal(12, 4) not null default 1.5, `score` double null default null,'
            . ' `bio` longtext null, `born` date null, `seen_at` datetime not null default current_timestamp, `settings` json null,'
            . ' `avatar` longblob null, `created_at` datetime null, `updated_at` datetime null,'
            . ' foreign key (`team_id`) references `teams` (`id`) on delete set null)',
            $mysql[0],
        );
        self::assertCount(5, $mysql);

        $change = new Table('users');
        $change->renameColumn('name', 'full_name');
        $change->dropIndex(['name', 'age']);
        $change->dropUnique('one_name_per_team');
        $change->dropColumn('bio', 'born');
        $change->foreignId('owner_id')->nullable()->references('users', 'id', 'cascade');
        $change->string('phone')->nullable()->unique();
        self::assertSame(
            [
                'alter table `users` rename column `name` to `full_name`',
                'drop index `users_name_age_index`',
                'drop index `one_name_per_team`',
                'alter table `users` drop column `bio`',
                'alter table `users` drop column `born`',
                'alter table `users` add column `owner_id` integer null references `users` (`id`) on delete cascade',
                'alter table `users` add column `phone` varchar(255) null',
                'create unique index `users_phone_unique` on `users` (`phone`)',
            ],
            new SqliteSchemaGrammar()->alter($change),
        );
        self::assertSame(
            [
                'alter table `users` rename column `name` to `full_name`',
                'drop index `users_name_age_index` on `users`',
                'drop index `one_name_per_team` on `users`',
                'alter table `users` drop column `bio`',
                'alter table `users` drop column `born`',
                'alter table `users` add column `owner_id` bigint unsigned null',
                'alter table `users` add foreign key (`owner_id`) references `users` (`id`) on delete cascade',
                'alter table `users` add column `phone` varchar(255) null',
                'create unique index `users_phone_unique` on `users` (`phone`)',
            ],
            new MySqlSchemaGrammar()->alter($change),
        );

        $members = new Table('members');
        $members->integer('team_id');
        $members->integer('user_id');
        $members->primary(['team_id', 'user_id']);
        self::assertSame(
            [
                'create table `members` (`team_id` integer not null, `user_id` integer not null, primary key (`team_id`, `user_id`))',
            ],
            new SqliteSchemaGrammar()->create($members),
        );

        self::assertInstanceOf(MySqlSchemaGrammar::class, SchemaGrammar::for('mysql'));
        self::assertInstanceOf(SqliteSchemaGrammar::class, SchemaGrammar::for('sqlite'));
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('"pgsql"');
        SchemaGrammar::for('pgsql');
    }

    #[DataProvider('drivers')]
    public function testTablesAreCreatedWithEveryKindOfColumn(string $driver): void
    {
        $db = $this->database($driver);
        $schema = new Schema($db);
        self::assertSame($db, $schema->connection());
        self::assertFalse($schema->hasTable('schema_everything'));

        $schema->create('schema_everything', static function (TableContract $table): void {
            $table->id();
            $table->string('email', 120)->unique();
            $table->string('name')->default("O'Brien \\ Co");
            $table->integer('age')->nullable();
            $table->bigInteger('views')->default(0);
            $table->boolean('active')->default(true);
            $table->decimal('balance', 12, 2)->default(1.5);
            $table->float('score')->nullable();
            $table->text('bio')->nullable();
            $table->date('born')->nullable();
            $table->dateTime('seen_at')->useCurrent();
            $table->json('settings')->nullable();
            $table->binary('avatar')->nullable();
            $table->timestamps();
            $table->index(['name', 'age']);
        });
        self::assertTrue($schema->hasTable('schema_everything'));
        self::assertTrue($schema->hasColumn('schema_everything', 'email'));
        self::assertTrue($schema->hasColumn('schema_everything', 'updated_at'));
        self::assertFalse($schema->hasColumn('schema_everything', 'missing'));
        self::assertFalse($schema->hasColumn('schema_missing', 'email'));

        $rows = $db->table('schema_everything');
        self::assertSame(1, $rows->insert(['email' => 'ada@x']));
        $row = $rows->find(1);
        self::assertSame(["O'Brien \\ Co", null, 0, 1, null, null], [
            $row['name'],
            $row['age'],
            $row['views'],
            $row['active'],
            $row['bio'],
            $row['created_at'],
        ]);
        self::assertEqualsWithDelta(1.5, (float) $row['balance'], 0.0001);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/',
            $row['seen_at'],
            'The time of insertion is the default.',
        );

        $rows->insert([
            'email' => 'grace@x',
            'name' => 'Grace',
            'age' => 45,
            'views' => 5_000_000_000,
            'active' => false,
            'balance' => '12345.67',
            'score' => 9.5,
            'bio' => str_repeat('long ', 2000),
            'born' => '1906-12-09',
            'settings' => '{"theme":"dark"}',
            'avatar' => "\x00\x01\xff",
            'created_at' => new \DateTimeImmutable('2026-01-31 12:00:00'),
        ]);
        $grace = $rows->find(2);
        self::assertSame([5_000_000_000, 0, '1906-12-09', "\x00\x01\xff", '2026-01-31 12:00:00', 10_000], [
            $grace['views'],
            $grace['active'],
            $grace['born'],
            $grace['avatar'],
            $grace['created_at'],
            strlen($grace['bio']),
        ]);
        self::assertSame(['theme' => 'dark'], json_decode($grace['settings'], true));
        self::assertEqualsWithDelta(12_345.67, (float) $grace['balance'], 0.0001);

        try {
            $rows->insert(['email' => 'ada@x']);
            self::fail('A unique column took the same value twice.');
        } catch (DatabaseException) {
        }
        try {
            $rows->insert(['name' => 'no email']);
            self::fail('A column that must have a value was left without one.');
        } catch (DatabaseException) {
        }
        self::assertSame(2, $rows->count());

        $schema->rename('schema_everything', 'schema_articles');
        self::assertSame([false, true], [$schema->hasTable('schema_everything'), $schema->hasTable('schema_articles')]);
        $schema->drop('schema_articles');
        self::assertFalse($schema->hasTable('schema_articles'));
        $schema->dropIfExists('schema_articles');
        $this->expectException(DatabaseException::class);
        $schema->drop('schema_articles');
    }

    #[DataProvider('drivers')]
    public function testForeignKeysAreKeptAndTablesAreChanged(string $driver): void
    {
        $db = $this->database($driver);
        $schema = new Schema($db);
        $schema->create('schema_users', static function (TableContract $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('schema_posts', static function (TableContract $table): void {
            $table->id();
            $table->foreignId('user_id')->references('schema_users', onDelete: 'cascade');
            $table->string('title')->index();
            $table->string('slug');
            // The slug first: MySQL would otherwise lean the foreign key on this index and refuse to drop it.
            $table->unique(['slug', 'user_id']);
        });
        $schema->create('schema_members', static function (TableContract $table): void {
            $table->foreignId('user_id')->references('schema_users');
            $table->integer('team');
            $table->primary(['user_id', 'team']);
        });

        $ada = $db->table('schema_users')->insert(['name' => 'Ada']);
        $grace = $db->table('schema_users')->insert(['name' => 'Grace']);
        $db->table('schema_posts')->insertMany([
            ['user_id' => $ada, 'title' => 'One', 'slug' => 'one'],
            ['user_id' => $ada, 'title' => 'Two', 'slug' => 'two'],
            ['user_id' => $grace, 'title' => 'One', 'slug' => 'one'],
        ]);
        $db->table('schema_members')->insert(['user_id' => $grace, 'team' => 1]);
        $refused = 0;
        $attempts = [
            static fn() => $db->table('schema_posts')->insert([
                'user_id' => 999,
                'title' => 'Orphan',
                'slug' => 'orphan',
            ]),
            static fn() => $db->table('schema_posts')->insert(['user_id' => $ada, 'title' => 'Again', 'slug' => 'one']),
            static fn() => $db->table('schema_members')->insertMany([['user_id' => $grace, 'team' => 1]]),
            static fn() => $db->table('schema_users')->where('id', $grace)->delete(),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (DatabaseException) {
                ++$refused;
            }
        }
        self::assertSame(
            4,
            $refused,
            'A row pointing nowhere, a repeated unique pair, a repeated primary key, and deleting a row still pointed at.',
        );
        $db->table('schema_users')->where('id', $ada)->delete();
        self::assertSame(['one'], $db->table('schema_posts')->pluck('slug'), 'Deleting a user deletes their posts.');

        $schema->table('schema_posts', static function (TableContract $table): void {
            $table->renameColumn('title', 'heading');
            $table->dropUnique(['slug', 'user_id']);
            $table->integer('views')->default(0);
            $table->string('summary')->nullable()->index();
            $table->foreignId('editor_id')->nullable()->references('schema_users', onDelete: 'set null');
            $table->index(['heading', 'views'], 'by_heading');
        });
        self::assertSame([true, false, true, true], [
            $schema->hasColumn('schema_posts', 'heading'),
            $schema->hasColumn('schema_posts', 'title'),
            $schema->hasColumn('schema_posts', 'views'),
            $schema->hasColumn('schema_posts', 'editor_id'),
        ]);
        $post = $db->table('schema_posts')->first();
        self::assertSame(['One', 0, null, null], [
            $post['heading'],
            $post['views'],
            $post['summary'],
            $post['editor_id'],
        ]);
        $db->table('schema_posts')->insert([
            'user_id' => $grace,
            'heading' => 'Twin',
            'slug' => 'one',
            'editor_id' => $grace,
        ]);
        self::assertSame(2, $db->table('schema_posts')->where('slug', 'one')->count(), 'The unique pair was dropped.');
        try {
            $db->table('schema_posts')->where('id', $post['id'])->update(['editor_id' => 999]);
            self::fail('A foreign key added afterwards was not kept.');
        } catch (DatabaseException) {
        }

        $schema->table('schema_posts', static function (TableContract $table): void {
            $table->dropIndex('by_heading');
            $table->dropIndex(['summary']);
            $table->dropColumn('summary', 'views');
        });
        self::assertSame([false, false, true], [
            $schema->hasColumn('schema_posts', 'summary'),
            $schema->hasColumn('schema_posts', 'views'),
            $schema->hasColumn('schema_posts', 'slug'),
        ]);
    }

    public function testMistakesAreRefusedWithWhatToDoInstead(): void
    {
        $schema = new Schema($this->database('sqlite'));
        $mistakes = [
            'was given no columns' => static fn() => $schema->create('schema_users', static fn() => null),
            'is being created, so it has nothing to rename or drop; that belongs in table()' =>
                static fn() => $schema->create('schema_users', static function (TableContract $table): void {
                    $table->id();
                    $table->dropColumn('old');
                }),
            'An id() column cannot be added to "schema_users" afterwards' =>
                static fn() => $schema->table('schema_users', static fn(TableContract $table) => $table->id()),
            'is chosen when the table is created and cannot be changed in table()' =>
                static fn() => $schema->table('schema_users', static fn(TableContract $table) => $table->primary([
                    'a',
                ])),
            '"explode" is not something a delete can do to "user_id"; use one of: cascade, set null, restrict, no action' =>
                static fn() => new Table('t')
                    ->foreignId('user_id')
                    ->references('users', onDelete: 'explode'),
            'needs a length of at least 1' => static fn() => new Table('t')->string('name', 0),
            'no more after the point than it has' => static fn() => new Table('t')->decimal('price', 4, 6),
            'over one column name or a list of them' => static fn() => new Table('t')->index([]),
            'one column name or a list' => static fn() => new Table('t')->unique([1]),
        ];
        foreach ($mistakes as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (\LogicException|\InvalidArgumentException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        self::assertFalse($schema->hasTable('schema_users'));
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
        $pdo = new \PDO('sqlite:' . $this->file);
        $pdo->exec('pragma foreign_keys = on');
        return $pdo;
    }
}
