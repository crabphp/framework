<?php

declare(strict_types=1);

namespace Crab\Tests\Database;

use Crab\Contracts\Database\Connection;
use Crab\Database\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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

    private static function database(): Database
    {
        return new Database(static fn(): \PDO => new \PDO('sqlite::memory:'), max: 1);
    }
}
