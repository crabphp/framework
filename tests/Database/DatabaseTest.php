<?php

declare(strict_types=1);

namespace Crab\Tests\Database;

use Crab\App;
use Crab\Config\Config;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Database as DatabaseContract;
use Crab\Database\Database;
use Crab\Database\DatabaseException;
use Crab\Database\DatabaseProvider;
use Crab\Database\PdoConnection;
use Crab\Engine\Async\Async;
use Crab\Engine\Process\Worker;
use Crab\Testing\WorkerHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A PDO that fails its next statements with the queued driver messages.
 */
final class FlakyPdo extends \PDO
{
    /**
     * @var list<string>
     */
    public static array $failures = [];

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        if (self::$failures !== []) {
            throw new \PDOException(array_shift(self::$failures));
        }
        return parent::prepare($query, $options);
    }
}

/**
 * Runs against SQLite always, and against MySQL too when
 * CRAB_TEST_MYSQL_DSN (with CRAB_TEST_MYSQL_USER and
 * CRAB_TEST_MYSQL_PASSWORD) names a database the tests may fill.
 */
final class DatabaseTest extends TestCase
{
    private string $file;
    /**
     * Connections opened by the database under test.
     */
    private int $connects = 0;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/crab-db-' . bin2hex(random_bytes(6)) . '.sqlite';
        FlakyPdo::$failures = [];
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $this->pdo('mysql')->exec('drop table if exists users');
        }
    }

    public static function drivers(): array
    {
        return getenv('CRAB_TEST_MYSQL_DSN') === false ? [['sqlite']] : [['sqlite'], ['mysql']];
    }

    #[DataProvider('drivers')]
    public function testStatementsBindValuesByPositionAndByName(string $driver): void
    {
        $db = $this->database($driver);
        self::assertSame(1, $db->insert('insert into users (name, age, active, score, born) values (?, ?, ?, ?, ?)',
            ['Ada', 36, true, 9.5, new \DateTimeImmutable('1815-12-10 00:00:00')]));
        self::assertSame(2, $db->insert('insert into users (name, age, active, score, born) values (:name, :age, :active, :score, :born)',
            ['name' => "Robert'); drop table users;--", 'age' => null, 'active' => false, 'score' => null, 'born' => null]));

        $rows = $db->select('select * from users order by id');
        self::assertCount(2, $rows);
        self::assertSame(['id' => 1, 'name' => 'Ada', 'age' => 36, 'active' => 1, 'born' => '1815-12-10 00:00:00'],
            array_diff_key($rows[0], ['score' => 0]));
        self::assertEqualsWithDelta(9.5, (float) $rows[0]['score'], 0.0001);
        self::assertSame(["Robert'); drop table users;--", null, 0, null], [$rows[1]['name'], $rows[1]['age'], $rows[1]['active'], $rows[1]['born']]);

        self::assertSame('Ada', $db->first('select name from users where age > :age', ['age' => 30])['name']);
        self::assertNull($db->first('select name from users where age > ?', [99]));
        self::assertSame([], $db->select('select name from users where age > ?', [99]));
        self::assertSame(1, $db->execute('update users set age = ? where name = ?', [37, 'Ada']));
        self::assertSame(0, $db->execute('update users set age = ? where name = ?', [1, 'nobody']));
        self::assertSame(2, $db->execute('delete from users'));
        self::assertSame(1, $this->connects, 'Statements run one after another share one connection.');
    }

    #[DataProvider('drivers')]
    public function testTransactionsCommitRollBackAndNest(string $driver): void
    {
        $db = $this->database($driver);
        $count = static fn (Connection $on): int => (int) $on->first('select count(*) as n from users')['n'];

        self::assertSame('done', $db->transaction(static function (Connection $tx) use ($count): string {
            $tx->insert('insert into users (name) values (?)', ['kept']);
            self::assertSame(1, $count($tx));
            return 'done';
        }));
        self::assertSame(1, $count($db));

        try {
            $db->transaction(static function (Connection $tx): void {
                $tx->insert('insert into users (name) values (?)', ['lost']);
                throw new \DomainException('changed my mind');
            });
            self::fail('The failure was swallowed.');
        } catch (\DomainException $error) {
            self::assertSame('changed my mind', $error->getMessage());
        }
        self::assertSame(1, $count($db));

        $db->transaction(static function (Connection $tx) use ($count): void {
            $tx->insert('insert into users (name) values (?)', ['outer']);
            try {
                $tx->transaction(static function (Connection $inner): void {
                    $inner->insert('insert into users (name) values (?)', ['inner, undone']);
                    $inner->transaction(static fn (Connection $deepest) => $deepest->insert('insert into users (name) values (?)', ['deepest, undone']));
                    throw new \DomainException('inner fails');
                });
            } catch (\DomainException) {
            }
            $tx->transaction(static fn (Connection $inner) => $inner->insert('insert into users (name) values (?)', ['inner, kept']));
            self::assertSame(3, $count($tx));
        });
        self::assertSame(['kept', 'outer', 'inner, kept'], array_column($db->select('select name from users order by id'), 'name'));
        self::assertSame(1, $this->connects);
    }

    #[DataProvider('drivers')]
    public function testFailuresCarryTheSqlAndNotTheValues(string $driver): void
    {
        $db = $this->database($driver);
        try {
            $db->select('select * from no_such_table where secret = ?', ['hunter2']);
            self::fail('A failing statement returned.');
        } catch (DatabaseException $error) {
            self::assertSame('select * from no_such_table where secret = ?', $error->sql);
            self::assertStringContainsString('(SQL: select * from no_such_table', $error->getMessage());
            self::assertStringNotContainsString('hunter2', $error->getMessage());
            self::assertInstanceOf(\PDOException::class, $error->getPrevious());
        }
        self::assertSame(1, $db->insert('insert into users (name) values (?)', ['still works']));
        $this->expectException(\InvalidArgumentException::class);
        $db->select('select ?', [['an array']]);
    }

    public function testManyDifferentStatementsStayCorrect(): void
    {
        $db = $this->database('sqlite');
        for ($i = 0; $i < 300; ++$i) {
            self::assertSame($i, $db->first("select {$i} as n, ? as again", [$i])['n']);
        }
        self::assertSame(7, $db->first('select 0 as n, ? as again', [7])['again'], 'A statement prepared earlier runs again with new values.');
    }

    public function testRunLendsOneConnectionAndReleaseRejectsAnOpenTransaction(): void
    {
        $db = $this->database('sqlite');
        $seen = $db->run(static function (Connection $connection) use ($db): Connection {
            self::assertInstanceOf(PdoConnection::class, $connection);
            $connection->insert('insert into users (name) values (?)', ['one']);
            return $connection;
        });
        self::assertSame($seen, $db->acquire(), 'A released connection is lent again.');
        $db->release($seen);

        $connection = $db->acquire();
        $connection->execute('begin');
        $connection->insert('insert into users (name) values (?)', ['never committed']);
        try {
            $db->release($connection);
            self::fail('A connection was returned mid-transaction.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('rolled back', $error->getMessage());
        }
        self::assertSame(['one'], array_column($db->select('select name from users'), 'name'));
    }

    public function testThePoolIsBoundedAndCallersWait(): void
    {
        $db = $this->database('sqlite', max: 2);
        $db->execute('select 1');
        $this->connects = 0;
        $peak = 0;
        $finished = WorkerHarness::run([], function (Worker $worker) use ($db, &$peak): int {
            $held = 0;
            $tasks = [];
            for ($i = 0; $i < 6; ++$i) {
                $tasks[] = $worker->spawn(static function () use ($db, $worker, &$held, &$peak, $i): int {
                    // Reads only: two SQLite connections cannot both hold a write while one waits.
                    return $db->run(static function (Connection $connection) use ($worker, &$held, &$peak, $i): int {
                        $peak = max($peak, ++$held);
                        $seen = $connection->first('select ? as task', [$i])['task'];
                        Async::sleep($worker->loop, 0.002);
                        --$held;
                        return $seen === $i ? 1 : 0;
                    });
                });
            }
            return array_sum(array_map(static fn ($task): int => $task->await(), $tasks));
        });
        self::assertSame([6, 2], [$finished, $peak]);
        self::assertSame(2, $this->connects, 'The worker opens its own connections, up to the limit and no more.');
    }

    public function testWaitingForAConnectionTimesOut(): void
    {
        $db = $this->database('sqlite', max: 1, timeout: 0.02);
        // Inside a worker, as in an application: the wait belongs to the worker's event loop.
        $message = WorkerHarness::run([], static function () use ($db): string {
            $held = $db->acquire();
            try {
                $db->select('select 1');
                return 'A statement ran with no free connection.';
            } catch (DatabaseException $error) {
                return $error->getMessage();
            } finally {
                $db->release($held);
            }
        });
        self::assertStringContainsString('the pool holds 1', $message);
        self::assertSame([['one' => 1]], $db->select('select 1 as one'));
    }

    public function testAConnectionThatWasAlreadyGoneIsReplacedAndTheStatementRunsOnce(): void
    {
        $db = $this->database('sqlite', flaky: true);
        self::assertSame(1, $this->connects);
        FlakyPdo::$failures = ['SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'];
        self::assertSame(1, $db->insert('insert into users (name) values (?)', ['after reconnect']));
        self::assertSame(2, $this->connects);
        self::assertSame(1, (int) $db->first('select count(*) as n from users')['n'], 'The statement must not be applied twice.');

        FlakyPdo::$failures = ['server has gone away', 'server has gone away'];
        try {
            $db->select('select 1');
            self::fail('A second failure in a row was retried again.');
        } catch (DatabaseException) {
        }
        self::assertSame(3, $this->connects);
        self::assertSame([['one' => 1]], $db->select('select 1 as one'));
    }

    public function testAConnectionLostMidStatementOrInATransactionIsNotRetried(): void
    {
        $db = $this->database('sqlite', flaky: true);
        FlakyPdo::$failures = ['SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query'];
        try {
            $db->insert('insert into users (name) values (?)', ['maybe applied']);
            self::fail('A statement that may have run was run again.');
        } catch (DatabaseException) {
        }
        self::assertSame(1, $this->connects);
        self::assertSame([], $db->select('select name from users'), 'The next statement reconnects.');
        self::assertSame(2, $this->connects);

        try {
            $db->transaction(static function (Connection $tx): void {
                $tx->insert('insert into users (name) values (?)', ['in the lost transaction']);
                FlakyPdo::$failures = ['server has gone away'];
                // Different SQL, so that it is prepared and meets the failure.
                $tx->insert('insert into users (name) values (:name)', ['name' => 'never sent']);
            });
            self::fail('A transaction continued on a new connection.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('gone away', $error->getMessage());
        }
        self::assertSame([], $db->select('select name from users'));

        try {
            $db->transaction(static function (Connection $tx): void {
                $tx->insert('insert into users (name) values (?)', ['before the loss']);
                FlakyPdo::$failures = ['server has gone away'];
                try {
                    $tx->select('select 2');
                } catch (DatabaseException) {
                    // Carrying on as if nothing happened must not commit half a transaction.
                }
                $tx->insert('insert into users (name) values (?)', ['on a new connection, outside it']);
            });
            self::fail('A lost transaction was reported as committed.');
        } catch (DatabaseException $error) {
            self::assertStringContainsString('nothing was committed', $error->getMessage());
        }
        self::assertSame(1, $db->transaction(static fn (Connection $tx): int => $tx->execute('delete from users')));
        self::assertSame('kept', $db->transaction(static function (Connection $tx): string {
            $tx->insert('insert into users (name) values (?)', ['kept']);
            return 'kept';
        }));
        self::assertSame(['kept'], array_column($db->select('select name from users'), 'name'));
    }

    public function testCloseDropsConnectionsAndTheDatabaseReconnects(): void
    {
        $db = $this->database('sqlite');
        $db->insert('insert into users (name) values (?)', ['before']);
        $db->close();
        $db->close();
        self::assertSame(['before'], array_column($db->select('select name from users'), 'name'));
        self::assertSame(2, $this->connects);
    }

    public function testInvalidSettingsAreRejected(): void
    {
        $rejected = 0;
        foreach ([static fn () => new Database(static fn () => null, max: 0), static fn () => new Database(static fn () => null, timeout: 0.0)] as $invalid) {
            try {
                $invalid();
            } catch (\InvalidArgumentException) {
                ++$rejected;
            }
        }
        try {
            (new Database(static fn () => 'not a PDO'))->select('select 1');
        } catch (\LogicException) {
            ++$rejected;
        }
        try {
            (new Database(static fn () => new \PDO('sqlite:/no/such/directory/db.sqlite')))->select('select 1');
        } catch (DatabaseException $error) {
            self::assertStringStartsWith('Cannot connect to the database', $error->getMessage());
            ++$rejected;
        }
        self::assertSame(4, $rejected);
    }

    public function testTheProviderBindsTheDatabaseFromConfigurationAndClosesItWithTheWorker(): void
    {
        $this->database('sqlite');
        $app = new App(new Config(['database' => ['dsn' => 'sqlite:' . $this->file]]));
        $app->register(DatabaseProvider::class);
        self::assertSame([null, 5.0, null], [config('database.pool'), config('database.timeout'), config('database.username')]);

        $db = $app->container->get(DatabaseContract::class);
        self::assertSame($db, $app->container->get(Connection::class));
        self::assertSame(1, $db->insert('insert into users (name) values (?)', ['from the provider']));

        $names = WorkerHarness::run([new DatabaseProvider()], static function (Worker $worker, Container $container): array {
            return array_column($container->get(DatabaseContract::class)->select('select name from users'), 'name');
        }, $app->container);
        self::assertSame(['from the provider'], $names);

        // SQLite allows one writer, so its pool is one connection unless set: the second transaction waits its turn.
        $order = WorkerHarness::run([], static function (Worker $worker) use ($db): array {
            $order = [];
            $tasks = [];
            foreach (['first', 'second'] as $name) {
                $tasks[] = $worker->spawn(static function () use ($db, $worker, $name, &$order): void {
                    $db->transaction(static function (Connection $tx) use ($worker, $name, &$order): void {
                        $order[] = "{$name} begins";
                        $tx->insert('insert into users (name) values (?)', [$name]);
                        Async::sleep($worker->loop, 0.002);
                        $order[] = "{$name} ends";
                    });
                });
            }
            foreach ($tasks as $task) {
                $task->await();
            }
            return $order;
        });
        self::assertSame(['first begins', 'first ends', 'second begins', 'second ends'], $order);

        $unset = new App();
        $unset->register(DatabaseProvider::class);
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('The database "default" has no "dsn" configured');
        $unset->container->get(DatabaseContract::class);
    }

    private function database(string $driver, int $max = 10, float $timeout = 5.0, bool $flaky = false): Database
    {
        $this->connects = 0;
        $db = new Database(function () use ($driver, $flaky): \PDO {
            ++$this->connects;
            return $this->pdo($driver, $flaky);
        }, $max, $timeout);
        $db->execute('drop table if exists users');
        $db->execute($driver === 'mysql'
            ? 'create table users (id int auto_increment primary key, name varchar(100) not null, age int null, active tinyint(1) null, score double null, born datetime null) engine=InnoDB'
            : 'create table users (id integer primary key autoincrement, name text not null, age integer null, active integer null, score real null, born text null)');
        return $db;
    }

    private function pdo(string $driver, bool $flaky = false): \PDO
    {
        if ($driver === 'mysql') {
            return new \PDO(getenv('CRAB_TEST_MYSQL_DSN'), getenv('CRAB_TEST_MYSQL_USER') ?: 'root', getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '');
        }
        return $flaky ? new FlakyPdo('sqlite:' . $this->file) : new \PDO('sqlite:' . $this->file);
    }
}
