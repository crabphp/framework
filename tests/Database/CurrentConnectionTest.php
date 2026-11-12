<?php

declare(strict_types=1);

namespace Crab\Tests\Database;

use Crab\Contracts\Database\Connection;
use Crab\Database\Database;
use Crab\Database\DatabaseException;
use Crab\Database\ManagedConnection;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrentConnectionTest extends TestCase
{
    use \Crab\Tests\Database\Mysql\AsyncDriverMatrix;

    public static function drivers(): array
    {
        return getenv('CRAB_TEST_MYSQL_DSN') === false ? [['sqlite']] : [['sqlite'], ['mysql'], ['mysql-async']];
    }

    #[DataProvider('drivers')]
    public function testLoansNestAndRestoreAfterReturnAndFailure(string $driver): void
    {
        $db = $this->database($driver);
        self::assertSame($db, $db->current());
        foreach (['run', 'transaction'] as $method) {
            $seen = [];
            $db->{$method}(static function (Connection $outer) use ($db, $method, &$seen): void {
                $seen[] = $db->current() === $outer;
                $db->{$method}(static function (Connection $inner) use ($db, $outer, &$seen): void {
                    $seen[] = $db->current() === $inner && $inner !== $outer;
                });
                $seen[] = $db->current() === $outer;
                try {
                    $db->{$method}(static function (): never {
                        throw new \RuntimeException('unwind');
                    });
                } catch (\RuntimeException) {
                    $seen[] = $db->current() === $outer;
                }
            });
            self::assertSame([true, true, true, true], $seen);
            self::assertSame($db, $db->current());
            try {
                $db->{$method}(static function (): never {
                    throw new \RuntimeException('unwind');
                });
            } catch (\RuntimeException) {
                // Checked after the callback unwound.
            }
            self::assertSame($db, $db->current());
        }
        $loan = $db->acquire();
        self::assertSame($db, $db->current());
        $db->release($loan);
        $db->first('select 1 as n');
        self::assertSame($db, $db->current());
        $db->close();
    }

    #[DataProvider('drivers')]
    public function testCurrentStatementsRollBackAndAPoisonedSessionStaysCurrent(string $driver): void
    {
        $db = $this->database($driver);
        $db->execute('drop table if exists current_rows');
        $db->execute('create table current_rows (id integer primary key)');
        try {
            $db->transaction(static function () use ($db): never {
                $db->current()->table('current_rows')->insert(['id' => 1]);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // The row and the current loan must both be gone.
        }
        self::assertSame(0, $db->table('current_rows')->count());
        $db->execute('drop table current_rows');
        $seen = [];
        try {
            $db->transaction(static function (ManagedConnection $connection) use ($db, &$seen): void {
                $connection->close();
                $seen[] = $db->current() === $connection;
                try {
                    $db->current()->first('select 1');
                } catch (DatabaseException) {
                    $seen[] = true;
                }
            });
        } catch (DatabaseException) {
            // Losing the session also refuses commit.
        }
        self::assertSame([true, true], $seen);
        self::assertSame($db, $db->current());
        $db->close();
    }

    #[DataProvider('drivers')]
    public function testOverlappingFibersKeepTheirOwnLoansAndChildrenInheritNone(string $driver): void
    {
        $db = $this->database($driver);
        $loop = EventLoop::current();
        $waiting = [];
        $seen = [];
        $loans = [];
        $deadline = $loop->delay(5.0, static function () use (&$waiting): void {
            foreach ($waiting as $suspension) {
                $suspension->throw(new \RuntimeException('Timed out waiting for both transaction barriers.'));
            }
        });
        $tasks = [];
        for ($i = 0; $i < 2; ++$i) {
            $tasks[] = Task::spawn($loop, static function () use ($db, $loop, $i, &$waiting, &$seen, &$loans): void {
                $db->transaction(static function (Connection $connection) use (
                    $db,
                    $loop,
                    $i,
                    &$waiting,
                    &$seen,
                    &$loans,
                ): void {
                    $loans[$i] = $connection;
                    $seen[] = $db->current() === $connection;
                    $child = Task::spawn($loop, static fn(): bool => $db->current() === $db);
                    $seen[] = $child->await();
                    $waiting[$i] = $loop->getSuspension();
                    if (count($waiting) === 2) {
                        $loop->defer(static function () use (&$waiting): void {
                            foreach ($waiting as $suspension) {
                                $suspension->resume();
                            }
                        });
                    }
                    $waiting[$i]->suspend();
                    unset($waiting[$i]);
                    $seen[] = $db->current() === $connection;
                });
                $seen[] = $db->current() === $db;
            });
        }
        try {
            foreach ($tasks as $task) {
                $task->await();
            }
        } finally {
            $loop->cancel($deadline);
            $db->close();
        }
        self::assertCount(8, $seen);
        self::assertNotContains(false, $seen);
        self::assertNotSame($loans[0], $loans[1]);
        self::assertSame($db, $db->current());
    }

    #[DataProvider('drivers')]
    public function testAQueryBuiltDuringALoanRefusesToRunAfterIt(string $driver): void
    {
        $db = $this->database($driver);
        $db->run(static function (Connection $connection): void {
            $connection->execute('drop table if exists crab_loans');
            $connection->execute('create table crab_loans (id integer primary key, name varchar(20) not null)');
            $connection->table('crab_loans')->insert(['id' => 1, 'name' => 'first']);
        });
        $kept = $db->run(static function (Connection $connection): \Crab\Contracts\Database\Query {
            $query = $connection->table('crab_loans')->where('id', 1);
            // Within its loan the query and its copies run.
            self::assertSame('first', $query->value('name'));
            self::assertSame('first', (clone $query)->value('name'));
            return $query;
        });
        $refused = static function (\Closure $run): void {
            try {
                $run();
                self::fail('A query of an ended loan ran.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('that loan has ended', $error->getMessage());
            }
        };
        $refused(static fn() => $kept->get());
        $refused(static fn() => (clone $kept)->orderBy('id')->cursorPaginate(5));
        $refused(static fn() => $kept->update(['name' => 'late']));
        // Lent again, the same physical connection is a new loan, not the old one come back.
        $db->transaction(static function () use ($kept, $refused): void {
            $refused(static fn() => $kept->first());
        });
        // A query on the database itself takes a connection for each statement, whenever it runs.
        $pooled = $db->table('crab_loans')->where('id', 1);
        self::assertSame('first', $pooled->value('name'));
        $db->execute('drop table crab_loans');
    }

    private function database(string $driver): Database
    {
        return new Database(static fn(): \PDO => $driver === 'sqlite'
            ? new \PDO('sqlite::memory:')
            : new \PDO(
                (string) getenv('CRAB_TEST_MYSQL_DSN'),
                getenv('CRAB_TEST_MYSQL_USER') ?: 'root',
                getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '',
            ), factory: $this->mysqlFactory($driver));
    }
}
