<?php

declare(strict_types=1);

namespace Crab\Tests\Database;

use Crab\Contracts\Database\Connection;
use Crab\Database\Database;
use Crab\Queue\DatabaseStore;
use Crab\Queue\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OwnershipJob
{
    public function handle(string $value): void {}
}

final class OwnershipTest extends TestCase
{
    /**
     * @return array<string, array{bool}>
     */
    public static function connectionFactories(): array
    {
        return ['different factories' => [false], 'same factory' => [true]];
    }

    #[DataProvider('connectionFactories')]
    public function testOnlyTheDatabaseThatLentAConnectionOwnsIt(bool $sameFactory): void
    {
        $connect = static fn(): \PDO => new \PDO('sqlite::memory:');
        $owner = new Database($connect, max: 1);
        $other = $sameFactory ? new Database($connect, max: 1) : self::database();
        $connection = $owner->acquire();
        try {
            self::assertTrue($owner->owns($owner));
            self::assertFalse($other->owns($owner));
            self::assertTrue($owner->owns($connection));
            self::assertFalse($other->owns($connection));
        } finally {
            $owner->release($connection);
            $owner->close();
            $other->close();
        }
    }

    #[DataProvider('connectionFactories')]
    public function testReturningAConnectionToAnotherDatabaseLeavesItsSessionOpen(bool $sameFactory): void
    {
        $connect = static fn(): \PDO => new \PDO('sqlite::memory:');
        $owner = new Database($connect, max: 1);
        $other = $sameFactory ? new Database($connect, max: 1) : self::database();
        $connection = $owner->acquire();
        try {
            $connection->execute('create table ownership_proof (id integer)');
            $failure = null;
            try {
                $other->release($connection);
            } catch (\LogicException $error) {
                $failure = $error;
            }

            self::assertInstanceOf(\LogicException::class, $failure);
            self::assertStringContainsString('database that lent it', $failure->getMessage());
            self::assertSame([], $connection->select('select * from ownership_proof'));
        } finally {
            $owner->release($connection);
            $owner->close();
            $other->close();
        }
    }

    public function testReturningAForeignTransactionDoesNotRollItBack(): void
    {
        $owner = self::database();
        $other = self::database();
        try {
            $owner->execute('create table ownership_proof (id integer)');
            $owner->transaction(static function (Connection $transaction) use ($other): void {
                $transaction->execute('insert into ownership_proof values (7)');
                $failure = null;
                try {
                    $other->release($transaction);
                } catch (\LogicException $error) {
                    $failure = $error;
                }

                self::assertInstanceOf(\LogicException::class, $failure);
                self::assertStringContainsString('database that lent it', $failure->getMessage());
                self::assertSame(['id' => 7], $transaction->first('select * from ownership_proof'));
            });
            self::assertSame(['id' => 7], $owner->first('select * from ownership_proof'));
        } finally {
            $owner->close();
            $other->close();
        }
    }

    public function testAConnectionCanStillBeReturnedAfterItsPoolCloses(): void
    {
        $database = self::database();
        $connection = $database->acquire();
        $connection->execute('create table ownership_proof (id integer)');
        $database->close();
        $database->release($connection);
        try {
            self::assertSame(['value' => 1], $database->first('select 1 as value'));
        } finally {
            $database->close();
        }
    }

    public function testReturningAConnectionTwiceReportsTheMistakeWithoutClosingItsSession(): void
    {
        $database = self::database();
        $connection = $database->acquire();
        try {
            $connection->execute('create table ownership_proof (id integer)');
            $database->release($connection);
            $failure = null;
            try {
                $database->release($connection);
            } catch (\LogicException $error) {
                $failure = $error;
            }

            self::assertInstanceOf(\LogicException::class, $failure);
            self::assertStringContainsString('not on loan', $failure->getMessage());
            self::assertSame([], $database->select('select * from ownership_proof'));
        } finally {
            $database->close();
        }
    }

    public function testAConnectionFromAClosedPoolCannotBeReturnedTwice(): void
    {
        $database = self::database();
        $connection = $database->acquire();
        $database->close();
        $database->release($connection);
        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('not on loan');
            $database->release($connection);
        } finally {
            $database->close();
        }
    }

    public function testReturningAnOldConnectionLeavesItsReplacementPoolAlone(): void
    {
        $database = self::database();
        $old = $database->acquire();
        $old->execute('create table old_session (id integer)');
        $database->close();
        $current = $database->acquire();
        try {
            $current->execute('create table current_session (id integer)');
            $database->release($old);

            self::assertNotSame($old, $current);
            self::assertSame([], $current->select('select * from current_session'));
        } finally {
            $database->release($current);
            $database->close();
        }
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function queueTransactions(): array
    {
        return [
            'same database commits' => [true, true],
            'same database rolls back' => [true, false],
            'shared factory commits' => [false, true],
            'shared factory rolls back' => [false, false],
        ];
    }

    #[DataProvider('queueTransactions')]
    public function testAJobJoinsOnlyItsOwnDatabaseTransaction(bool $sameDatabase, bool $commit): void
    {
        $connect = static fn(): \PDO => new \PDO('sqlite::memory:');
        $owner = new Database($connect, max: 1);
        $destination = $sameDatabase ? $owner : new Database($connect, max: 1);
        try {
            foreach ($sameDatabase ? [$owner] : [$owner, $destination] as $database) {
                $database->execute(
                    'create table jobs (id integer primary key, queue text, job text, payload text, attempts integer, '
                    . 'available_at integer, created_at integer, reserved_at integer, failed_at integer, error text)',
                );
            }
            $queue = new Queue(new DatabaseStore($destination));
            try {
                $owner->transaction(static function (Connection $transaction) use (
                    $queue,
                    $destination,
                    $sameDatabase,
                    $commit,
                ): void {
                    $id = $queue->within($transaction)->push(OwnershipJob::class, value: 'queued');
                    self::assertSame($sameDatabase ? 1 : '', $id);
                    self::assertSame($sameDatabase ? 1 : 0, $transaction->table('jobs')->count());
                    if (!$sameDatabase) {
                        self::assertSame(0, $destination->table('jobs')->count());
                    }
                    if (!$commit) {
                        throw new \DomainException('roll back the write');
                    }
                });
            } catch (\DomainException $error) {
                self::assertFalse($commit);
                self::assertSame('roll back the write', $error->getMessage());
            }

            self::assertSame($sameDatabase && $commit ? 1 : 0, $owner->table('jobs')->count());
            self::assertSame($commit ? 1 : 0, $destination->table('jobs')->count());
        } finally {
            $owner->close();
            $destination->close();
        }
    }

    private static function database(): Database
    {
        return new Database(static fn(): \PDO => new \PDO('sqlite::memory:'), max: 1);
    }
}
