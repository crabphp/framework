<?php

declare(strict_types=1);

namespace Crab\Tests\Database;

use Crab\Contracts\Database\Connection;
use Crab\Database\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What runs after a commit and after a rollback, across nested transactions.
 */
final class TransactionCallbacksTest extends TestCase
{
    use \Crab\Tests\Database\Mysql\AsyncDriverMatrix;

    public static function drivers(): array
    {
        return getenv('CRAB_TEST_MYSQL_DSN') === false ? [['sqlite']] : [['sqlite'], ['mysql'], ['mysql-async']];
    }

    #[DataProvider('drivers')]
    public function testARollbackRunsWhatWasAskedLastFirstAndACommitRunsNoneOfIt(string $driver): void
    {
        $db = $this->database($driver);
        $ran = [];
        try {
            $db->transaction(static function (Connection $tx) use (&$ran): never {
                $tx->afterRollback(static function () use (&$ran): void {
                    $ran[] = 'first';
                });
                $tx->afterRollback(static function () use (&$ran): void {
                    $ran[] = 'second';
                });
                $tx->afterCommit(static function () use (&$ran): void {
                    $ran[] = 'committed';
                });
                throw new \RuntimeException('undo');
            });
        } catch (\RuntimeException $error) {
            $failure = $error->getMessage();
        }
        self::assertSame('undo', $failure ?? null);
        self::assertSame(['second', 'first'], $ran);

        $ran = [];
        $db->transaction(static function (Connection $tx) use (&$ran): void {
            $tx->afterRollback(static function () use (&$ran): void {
                $ran[] = 'undone';
            });
            $tx->afterCommit(static function () use (&$ran): void {
                $ran[] = 'committed';
            });
        });
        self::assertSame(['committed'], $ran);

        // Outside a transaction there is nothing to undo: on a lent connection and on the database.
        $db->run(static function (Connection $connection) use (&$ran): void {
            $connection->afterRollback(static function () use (&$ran): void {
                $ran[] = 'never';
            });
        });
        $db->afterRollback(static function () use (&$ran): void {
            $ran[] = 'never';
        });
        self::assertSame(['committed'], $ran);
        $db->close();
    }

    #[DataProvider('drivers')]
    public function testAnInnerRollbackUndoesOnlyItsOwnAndAnOuterOneUndoesAReleasedInner(string $driver): void
    {
        $db = $this->database($driver);
        $ran = [];
        $db->transaction(static function (Connection $tx) use (&$ran): void {
            $tx->afterRollback(static function () use (&$ran): void {
                $ran[] = 'outer';
            });
            try {
                $tx->transaction(static function (Connection $inner) use (&$ran): never {
                    $inner->afterRollback(static function () use (&$ran): void {
                        $ran[] = 'inner';
                    });
                    throw new \RuntimeException('inner only');
                });
            } catch (\RuntimeException) {
                $ran[] = 'caught';
            }
        });
        self::assertSame(['inner', 'caught'], $ran);

        $ran = [];
        try {
            $db->transaction(static function (Connection $tx) use (&$ran): never {
                $tx->transaction(static function (Connection $inner) use (&$ran): void {
                    $inner->afterRollback(static function () use (&$ran): void {
                        $ran[] = 'released inner';
                    });
                });
                throw new \RuntimeException('outer');
            });
        } catch (\RuntimeException) {
            // Checked below, outside the region whose exception is caught.
        }
        self::assertSame(['released inner'], $ran);
        $db->close();
    }

    #[DataProvider('drivers')]
    public function testACommittedInnerTransactionKeepsItsCommitCallbackWhenASiblingRollsBack(string $driver): void
    {
        $db = $this->database($driver);
        $ran = [];
        $db->transaction(static function (Connection $tx) use (&$ran): void {
            $tx->transaction(static function (Connection $inner) use (&$ran): void {
                $inner->afterCommit(static function () use (&$ran): void {
                    $ran[] = 'kept';
                });
            });
            try {
                $tx->transaction(static function (Connection $inner) use (&$ran): never {
                    $inner->afterCommit(static function () use (&$ran): void {
                        $ran[] = 'dropped';
                    });
                    throw new \RuntimeException('sibling');
                });
            } catch (\RuntimeException) {
                // The sibling's rollback must not take the first one's callback with it.
            }
            self::assertSame([], $ran);
        });
        self::assertSame(['kept'], $ran);
        $db->close();
    }

    #[DataProvider('drivers')]
    public function testAFailingRollbackCallbackDoesNotHideTheFailureOrStopTheOthers(string $driver): void
    {
        $db = $this->database($driver);
        $ran = [];
        try {
            $db->transaction(static function (Connection $tx) use (&$ran): never {
                $tx->afterRollback(static function () use (&$ran): void {
                    $ran[] = 'still runs';
                });
                $tx->afterRollback(static function (): never {
                    throw new \DomainException('callback failed');
                });
                throw new \RuntimeException('the real failure');
            });
        } catch (\Throwable $error) {
            $failure = $error;
        }
        self::assertInstanceOf(\RuntimeException::class, $failure ?? null);
        self::assertSame('the real failure', $failure->getMessage());
        self::assertSame(['still runs'], $ran);
        // The connection is still good for the next transaction.
        self::assertSame(
            1,
            (int) $db->transaction(static fn(Connection $tx): mixed => $tx->first('select 1 as n')['n']),
        );
        $db->close();
    }

    public function testACommitTheDatabaseRefusesRunsTheRollbackCallbacks(): void
    {
        // SQLite checks a deferred foreign key at COMMIT, and refuses it: the work is rolled back.
        // A file, since the connection is opened again after a commit fails.
        $file = tempnam(sys_get_temp_dir(), 'crab-commit-');
        $db = new Database(static function () use ($file): \PDO {
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->exec('pragma foreign_keys = on');
            return $pdo;
        }, max: 1);
        $db->execute('create table parents (id integer primary key)');
        $db->execute(
            'create table children (id integer primary key, parent_id integer not null references parents (id) deferrable initially deferred)',
        );
        $ran = [];
        try {
            $db->transaction(static function (Connection $tx) use (&$ran): void {
                $tx->execute('insert into children (id, parent_id) values (1, 99)');
                $tx->afterRollback(static function () use (&$ran): void {
                    $ran[] = 'undone';
                });
                $tx->afterCommit(static function () use (&$ran): void {
                    $ran[] = 'committed';
                });
            });
        } catch (\Throwable $error) {
            $failure = $error;
        }
        self::assertStringContainsString('FOREIGN KEY', ($failure ?? null)?->getMessage() ?? '');
        self::assertSame(['undone'], $ran);
        self::assertSame(0, (int) $db->first('select count(*) as n from children')['n']);
        $db->close();
        @unlink($file);
    }

    public function testACommitWhoseOutcomeIsUnknownRunsNeitherCallback(): void
    {
        $db = new Database(static fn(): \PDO => new CommitLostPdo('sqlite::memory:'));
        $ran = [];
        try {
            $db->transaction(static function (Connection $tx) use (&$ran): void {
                $tx->afterRollback(static function () use (&$ran): void {
                    $ran[] = 'undone';
                });
                $tx->afterCommit(static function () use (&$ran): void {
                    $ran[] = 'committed';
                });
            });
        } catch (\Throwable $error) {
            $failure = $error;
        }
        self::assertStringContainsString('server has gone away', ($failure ?? null)?->getMessage() ?? '');
        // The server may or may not have committed: nothing says either.
        self::assertSame([], $ran);
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

/**
 * A connection whose COMMIT reaches the database but whose answer is lost.
 */
final class CommitLostPdo extends \PDO
{
    public function commit(): bool
    {
        throw new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
    }

    public function exec(string $statement): int|false
    {
        if ($statement === 'COMMIT') {
            $this->commit();
        }
        return parent::exec($statement);
    }
}
