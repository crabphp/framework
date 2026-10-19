<?php

declare(strict_types=1);

namespace Crab\Tests\Database\Mysql;

use Crab\Config\Config;
use Crab\Container\Container;
use Crab\Contracts\Config\Config as ConfigContract;
use Crab\Contracts\Database\Connection as ConnectionContract;
use Crab\Contracts\Database\Database as DatabaseContract;
use Crab\Database\DatabaseException;
use Crab\Database\DatabaseManager;
use Crab\Database\DatabaseProvider;
use Crab\Database\Mysql\Connection;
use Crab\Database\PdoConnection;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Process\Worker;
use Crab\Testing\WorkerHarness;
use PHPUnit\Framework\TestCase;

/**
 * Exercises real MySQL sessions only when an explicitly supplied test DSN exists.
 * Each test owns its tables and kills only sessions it opened itself.
 */
final class ConnectionTest extends TestCase
{
    private const TABLES = ['mysql_async_types', 'mysql_async_tx'];

    protected function setUp(): void
    {
        if (getenv('CRAB_TEST_MYSQL_DSN') === false) {
            self::markTestSkipped('Set CRAB_TEST_MYSQL_DSN to the isolated crab_test database to test MySQL sessions.');
        }
    }

    protected function tearDown(): void
    {
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $this->pdo()->exec('drop table if exists ' . implode(', ', self::TABLES));
            $this->pdo()->exec('drop procedure if exists mysql_async_results');
        }
    }

    public function testEveryColumnTypeHasTheSamePhpValueAsPdo(): void
    {
        $this->using(function (DatabaseContract $db): void {
            $db->execute('create table mysql_async_types (
                id bigint unsigned auto_increment primary key, tiny tinyint, small smallint unsigned,
                medium mediumint, integer_value int unsigned, big bigint, unsigned_big bigint unsigned,
                float_value float, double_value double, decimal_value decimal(12,4), date_value date,
                datetime_value datetime(6), timestamp_value timestamp(6), time_value time(6), year_value year,
                json_value json, bit_value bit(9), tiny_blob tinyblob, blob_value blob, medium_blob mediumblob,
                long_blob longblob, char_value char(5), varchar_value varchar(10), binary_value binary(4),
                varbinary_value varbinary(10), tiny_text tinytext, text_value text, medium_text mediumtext,
                long_text longtext, enum_value enum("a","b"), set_value set("a","b"), null_value int
            )');
            $sql =
                'insert into mysql_async_types (tiny, small, medium, integer_value, big, unsigned_big,
                float_value, double_value, decimal_value, date_value, datetime_value, timestamp_value,
                time_value, year_value, json_value, bit_value, tiny_blob, blob_value, medium_blob, long_blob,
                char_value, varchar_value, binary_value, varbinary_value, tiny_text, text_value, medium_text,
                long_text, enum_value, set_value, null_value) values ('
                . implode(',', array_fill(0, 31, '?'))
                . ')';
            $values = [
                -128,
                65_535,
                -8_388_608,
                4_294_967_295,
                PHP_INT_MIN,
                '18446744073709551615',
                1.2345,
                1.234_567_890_1,
                '123.4500',
                '2026-10-11',
                '2026-10-11 12:34:56.123456',
                '2026-10-11 12:34:56.654321',
                '-51:04:05.000600',
                2026,
                '{"hello":"world"}',
                511,
                "a\0b",
                "a\xffb",
                'medium',
                'long',
                'c',
                'string',
                "x\0yz",
                "x\0z",
                'tiny',
                'text',
                'medium',
                'long',
                'b',
                'a,b',
                null,
            ];
            $pdo = new PdoConnection(fn(): \PDO => $this->pdo());
            self::assertSame(1, $pdo->insert($sql, $values));
            self::assertSame(
                $pdo->select('select * from mysql_async_types'),
                $db->select('select * from mysql_async_types'),
            );
            self::assertSame(0, $pdo->execute('update mysql_async_types set tiny = ? where id = ?', [-128, 1]));
            self::assertSame(0, $db->execute('update mysql_async_types set tiny = ? where id = ?', [-128, 1]));
        });
    }

    public function testTenQueriesOverlapAndAPoolOfOneMakesThemQueue(): void
    {
        foreach ([10, 1] as $pool) {
            $elapsed = $this->using(static function (DatabaseContract $db, Worker $worker): array {
                $ticks = 0;
                $timer = $worker->loop->repeat(0.02, static function () use (&$ticks): void {
                    ++$ticks;
                });
                $start = hrtime(true);
                $tasks = [];
                for ($i = 0; $i < 10; ++$i) {
                    $tasks[] = $worker->spawn(static fn(): ?array => $db->first('select sleep(0.2) as slept'));
                }
                foreach ($tasks as $task) {
                    self::assertSame(['slept' => 0], $task->await());
                }
                $worker->loop->cancel($timer);
                return [(hrtime(true) - $start) / 1e9, $ticks];
            }, ['pool' => $pool]);
            self::assertGreaterThan(4, $elapsed[1]);
            if ($pool === 10) {
                self::assertLessThan(1.0, $elapsed[0]);
            } else {
                self::assertGreaterThan(1.8, $elapsed[0]);
                self::assertLessThan(4.0, $elapsed[0]);
            }
        }
    }

    public function testDrainingAMillionRowsKeepsLoopTimersRunningWithoutRetainingThem(): void
    {
        $this->using(static function (DatabaseContract $db, Worker $worker): void {
            $digit = '(SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
            $tables = [];
            foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $alias) {
                $tables[] = $digit . ' ' . $alias;
            }
            $ticks = 0;
            $timer = $worker->loop->repeat(0.005, static function () use (&$ticks): void {
                ++$ticks;
            });
            try {
                self::assertSame(['n' => 1], $db->first('SELECT 1 AS n FROM ' . implode(' CROSS JOIN ', $tables)));
                self::assertGreaterThan(4, $ticks);
                self::assertSame(['n' => 2], $db->first('SELECT 2 AS n'));
            } finally {
                $worker->loop->cancel($timer);
            }
        }, ['pool' => 1]);
    }

    public function testCallReturnsItsFirstResultAndDrainsTheRest(): void
    {
        $this->using(static function (DatabaseContract $db): void {
            $db->execute('create procedure mysql_async_results() begin select 1 as n; select 2 as n; end');
            self::assertSame([['n' => 1]], $db->select('call mysql_async_results()'));
            self::assertSame(['n' => 1], $db->first('call mysql_async_results()'));
            self::assertSame(1, $db->execute('call mysql_async_results()'));
            self::assertSame(['n' => 3], $db->first('select 3 as n'));
        }, ['pool' => 1]);
    }

    public function testTlsOptionsEncryptTheSameMysqlSession(): void
    {
        $this->using(static function (DatabaseContract $db): void {
            $status = $db->first('SHOW SESSION STATUS LIKE "Ssl_cipher"');
            self::assertNotSame('', $status['Value']);
            self::assertSame(['n' => 1], $db->first('select 1 as n'));
        }, ['options' => ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]]);
    }

    public function testHandshakeSessionDefaultsAndExplicitCharsetsMatchPdo(): void
    {
        foreach (['', ';charset=utf8mb4', ';charset=latin1', ';charset=utf8'] as $charset) {
            $dsn = (string) getenv('CRAB_TEST_MYSQL_DSN') . $charset;
            $sql = 'select @@character_set_client as charset, @@collation_connection as collation, @@sql_mode as mode';
            $pdo = new PdoConnection(static fn(): \PDO => new \PDO($dsn, 'root', ''));
            $expected = $pdo->first($sql);
            $this->using(static function (DatabaseContract $db) use ($sql, $expected): void {
                self::assertSame($expected, $db->first($sql));
            }, ['dsn' => $dsn]);
        }
    }

    public function testWorkerDrainClosesAnInFlightBorrowedMysqlConnectionAtItsDeadline(): void
    {
        $connection = null;
        $start = hrtime(true);
        $this->using(
            static function (DatabaseContract $db, Worker $worker) use (&$connection): void {
                $connection = $db->acquire();
                $worker->loop->delay(0.02, static fn() => $worker->stop());
                // This task ignores cooperative cancellation. Provider teardown must
                // still close its socket when the worker's one-second deadline ends.
                $connection->first('select sleep(10)');
            },
            ['pool' => 1],
        );
        self::assertLessThan(1.5, (hrtime(true) - $start) / 1e9);
        self::assertFalse($connection->inTransaction());
        // Close is idempotent after provider teardown, including a borrowed session.
        $connection->close();
    }

    public function testAnIdleKilledConnectionIsReplacedBeforeItsNextCommand(): void
    {
        $this->using(function (DatabaseContract $db, Worker $worker): void {
            $before = $db->first('select connection_id() as id')['id'];
            $this->kill($before);
            Async::sleep($worker->loop, 0.01);
            $after = $db->first('select connection_id() as id')['id'];
            self::assertNotSame($before, $after);
            self::assertSame(1, $db->first('select 1 as n')['n']);
        }, ['pool' => 1]);
    }

    public function testAQueryKilledInFlightFailsAndTheNextQueryReconnects(): void
    {
        $this->using(function (DatabaseContract $db, Worker $worker): void {
            $db->run(function (ConnectionContract $connection) use ($worker): void {
                $id = $connection->first('select connection_id() as id')['id'];
                $worker->loop->delay(0.02, fn() => $this->kill($id));
                try {
                    $connection->first('select sleep(1) as slept');
                    self::fail('A killed query was retried.');
                } catch (DatabaseException $error) {
                    self::assertSame('select sleep(1) as slept', $error->sql);
                }
                self::assertNotSame($id, $connection->first('select connection_id() as id')['id']);
            });
        });
    }

    public function testALostTransactionCannotCommitEvenWhenItsErrorIsCaught(): void
    {
        $this->using(function (DatabaseContract $db, Worker $worker): void {
            $db->execute('create table mysql_async_tx (id int primary key)');
            try {
                $db->transaction(function (ConnectionContract $tx) use ($worker): void {
                    $tx->insert('insert into mysql_async_tx values (?)', [1]);
                    $id = $tx->first('select connection_id() as id')['id'];
                    $worker->loop->delay(0.02, fn() => $this->kill($id));
                    try {
                        $tx->first('select sleep(1) as slept');
                    } catch (DatabaseException) {
                    }
                });
                self::fail('A lost transaction committed.');
            } catch (DatabaseException $error) {
                self::assertStringContainsString('transaction lost its session', $error->getMessage());
            }
            self::assertSame([], $db->select('select * from mysql_async_tx'));
        });
    }

    public function testCancellingAResultClosesItsSessionAndReleasesItsWatchers(): void
    {
        WorkerHarness::run([], static function (Worker $worker): void {
            $token = new Cancellation();
            $connection = new Connection(
                (string) getenv('CRAB_TEST_MYSQL_DSN'),
                'root',
                worker: $worker,
                cancellation: $token,
            );
            $id = $connection->first('select connection_id() as id')['id'];
            $before = $worker->loop->getIdentifiers();
            $worker->loop->delay(0.02, static fn() => $token->cancel());
            try {
                $connection->first('select sleep(1) as slept');
                self::fail('The cancelled result completed.');
            } catch (CancelledException) {
            }
            self::assertSame($before, $worker->loop->getIdentifiers());
            self::assertFalse($connection->inTransaction());
            // MySQL can retain its sleeping server thread until the query ends;
            // client shutdown is not a KILL. The cancelled token cannot read a stale answer.
            try {
                $connection->first('select 1');
                self::fail('A cancelled session read another answer.');
            } catch (CancelledException) {
            }
        });
    }

    public function testTwoFibersCannotUseTheSameBorrowedConnectionAtOnce(): void
    {
        $this->using(static function (DatabaseContract $db, Worker $worker): void {
            $db->run(static function (ConnectionContract $connection) use ($worker): void {
                $connection->first('select 1');
                $first = $worker->spawn(static fn(): ?array => $connection->first('select sleep(0.1) as slept'));
                Async::sleep($worker->loop, 0.01);
                try {
                    $connection->select('select 2');
                    self::fail('The same connection accepted overlapping commands.');
                } catch (\LogicException $error) {
                    self::assertStringContainsString('Two fibers', $error->getMessage());
                }
                self::assertSame(['slept' => 0], $first->await());
            });
        });
    }

    public function testCommitCallbacksRunInOrderAndRollbackRemovesNestedCallbacks(): void
    {
        $this->using(static function (DatabaseContract $db): void {
            $order = [];
            $db->transaction(static function (ConnectionContract $outer) use (&$order): void {
                $outer->afterCommit(static function () use (&$order): void {
                    $order[] = 1;
                });
                try {
                    $outer->transaction(static function (ConnectionContract $inner) use (&$order): void {
                        $inner->afterCommit(static function () use (&$order): void {
                            $order[] = 99;
                        });
                        throw new \DomainException('rollback');
                    });
                } catch (\DomainException) {
                }
                $outer->transaction(static function (ConnectionContract $inner) use (&$order): void {
                    $inner->afterCommit(static function () use (&$order): void {
                        $order[] = 2;
                    });
                });
            });
            self::assertSame([1, 2], $order);
            try {
                $db->transaction(static function (ConnectionContract $tx) use (&$order): void {
                    $tx->afterCommit(static function () use (&$order): void {
                        $order[] = 3;
                        throw new \DomainException('committed');
                    });
                    $tx->afterCommit(static function () use (&$order): void {
                        $order[] = 4;
                    });
                });
            } catch (\DomainException $error) {
                self::assertSame('committed', $error->getMessage());
            }
            self::assertSame([1, 2, 3, 4], $order);
        });
    }

    public function testStatementHandlesAreBoundedAndMissingValuesAreRefused(): void
    {
        $this->using(static function (DatabaseContract $db): void {
            $db->run(static function (ConnectionContract $connection): void {
                for ($i = 0; $i < 300; ++$i) {
                    self::assertSame($i, $connection->first("select {$i} as n, ? as again", [$i])['again']);
                }
                self::assertSame(7, $connection->first('select 0 as n, ? as again', [7])['again']);
                try {
                    $connection->first('select 0 as n, ? as again');
                    self::fail('A missing binding reused an old value.');
                } catch (DatabaseException $error) {
                    self::assertStringContainsString('takes 1 values and was given 0', $error->getMessage());
                }
                self::assertSame(8, $connection->first('select 0 as n, ? as again', [8])['again']);
            });
        }, ['pool' => 1]);
    }

    public function testAsyncIsSelectedOnlyForMysqlInsideTheProviderWorker(): void
    {
        $manager = new DatabaseManager([
            'main' => [
                'dsn' => (string) getenv('CRAB_TEST_MYSQL_DSN'),
                'username' => 'root',
                'async' => true,
                'options' => ['ssl' => false],
            ],
        ], 'main');
        $db = $manager->connection();
        $before = $db->acquire();
        self::assertInstanceOf(PdoConnection::class, $before);
        $db->release($before);
        WorkerHarness::run([], static function (Worker $worker) use ($manager, $db): void {
            $manager->start($worker);
            $connection = $db->acquire();
            self::assertInstanceOf(Connection::class, $connection);
            self::assertTrue($db->owns($connection));
            $db->release($connection);
            $manager->stop();
        });
        $after = $db->acquire();
        self::assertInstanceOf(PdoConnection::class, $after);
        $db->release($after);
        $manager->close();
        $this->using(static function (DatabaseContract $db): void {
            $connection = $db->acquire();
            self::assertInstanceOf(PdoConnection::class, $connection);
            $db->release($connection);
        }, ['dsn' => 'sqlite::memory:']);
    }

    private function using(\Closure $body, array $settings = []): mixed
    {
        $container = new Container();
        $container->instance(ConfigContract::class, new Config([
            'database' => ['connections' => [
                'main' => $settings
                    + ['dsn' => (string) getenv('CRAB_TEST_MYSQL_DSN'), 'username' => 'root', 'async' => true],
            ]],
        ]));
        return WorkerHarness::run(
            [new DatabaseProvider()],
            static fn(Worker $worker, Container $container): mixed => $body(
                $container->get(DatabaseContract::class),
                $worker,
            ),
            $container,
        );
    }

    private function pdo(): \PDO
    {
        return new \PDO((string) getenv('CRAB_TEST_MYSQL_DSN'), 'root', '', [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function kill(int $id): void
    {
        // The id is read from this test's own session and cast before rendering.
        $this->pdo()->exec('KILL ' . $id);
    }
}
