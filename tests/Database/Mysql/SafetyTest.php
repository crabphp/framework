<?php

declare(strict_types=1);

namespace Crab\Tests\Database\Mysql;

use Crab\Contracts\Database\Connection as ConnectionContract;
use Crab\Database\DatabaseException;
use Crab\Database\ManagedConnection;
use Crab\Database\Migrator;
use Crab\Database\Mysql\Connection;
use Crab\Database\PdoConnection;
use Crab\Database\Query;
use Crab\Engine\Async\Async;
use Crab\Engine\Process\Worker;
use Crab\Testing\WorkerHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kills only sessions opened by these tests and owns its fixture tables.
 * The same lost-scope sequences must be safe through both database drivers.
 */
final class SafetyTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('CRAB_TEST_MYSQL_DSN') === false) {
            self::markTestSkipped('Set CRAB_TEST_MYSQL_DSN to crab_test to test session loss.');
        }
    }

    public static function drivers(): array
    {
        return [['pdo'], ['async']];
    }

    #[DataProvider('drivers')]
    public function testAManagedTransactionCannotCommitAnExistingRawTransaction(string $driver): void
    {
        $this->using($driver, static function (ManagedConnection $connection, \PDO $setup, string $table): void {
            $connection->execute('BEGIN');
            $connection->insert("INSERT INTO {$table} VALUES (?)", [1]);
            $called = false;
            try {
                $connection->transaction(static function (ConnectionContract $tx) use (&$called, $table): void {
                    $called = true;
                    $tx->insert("INSERT INTO {$table} VALUES (?)", [2]);
                    throw new \DomainException('rollback the callback');
                });
                self::fail('An existing raw transaction accepted a managed BEGIN.');
            } catch (DatabaseException $error) {
                self::assertStringContainsString('active transaction', $error->getMessage());
            } finally {
                $connection->execute('ROLLBACK');
            }
            self::assertFalse($called);
            self::assertSame([], $setup->query("SELECT * FROM {$table}")->fetchAll());
        });
    }

    #[DataProvider('drivers')]
    public function testALostTransactionRefusesLaterWritesUntilItsCallbackUnwinds(string $driver): void
    {
        $this->using($driver, static function (ManagedConnection $connection, \PDO $setup, string $table): void {
            $blocked = false;
            try {
                $connection->transaction(static function (ConnectionContract $tx) use (
                    $setup,
                    $table,
                    &$blocked,
                ): void {
                    $tx->insert("INSERT INTO {$table} VALUES (?)", [1]);
                    $id = $tx->first('SELECT CONNECTION_ID() AS id')['id'];
                    $setup->exec('KILL CONNECTION ' . (int) $id);
                    try {
                        $tx->first('SELECT 1 AS lost');
                        self::fail('A killed transaction still had its session.');
                    } catch (DatabaseException) {
                    }
                    try {
                        $tx->insert("INSERT INTO {$table} VALUES (?)", [2]);
                    } catch (DatabaseException $error) {
                        $blocked = true;
                        self::assertStringContainsString('transaction', $error->getMessage());
                    }
                });
                self::fail('A lost transaction scope succeeded.');
            } catch (DatabaseException $error) {
                self::assertStringContainsString('transaction lost its session', $error->getMessage());
            }
            self::assertTrue($blocked, 'The callback wrote after losing its transaction session.');
            self::assertSame([], $setup->query("SELECT * FROM {$table}")->fetchAll());
            $connection->insert("INSERT INTO {$table} VALUES (?)", [3]);
            self::assertSame([['id' => 3]], $connection->select("SELECT * FROM {$table}"));
        });
    }

    #[DataProvider('drivers')]
    public function testMigrationWorkCannotReconnectAfterItsLockOwnershipGuard(string $driver): void
    {
        $this->using($driver, static function (
            ManagedConnection $connection,
            \PDO $setup,
            string $table,
            Worker $worker,
        ): void {
            $history = $table . '_history';
            $lock = 'crab_migrations_' . $history;
            $directory = sys_get_temp_dir() . '/crab-migration-loss-' . bin2hex(random_bytes(6));
            mkdir($directory);
            $file = $directory . '/2026_01_01_000000_work.php';
            $source = '<?php return new class implements \Crab\Contracts\Database\Migration {
                public function up(\Crab\Contracts\Database\Schema $schema): void {
                    $schema->connection()->insert("INSERT INTO ' . $table . ' VALUES (2)");
                }
                public function down(\Crab\Contracts\Database\Schema $schema): void {}
            };';
            file_put_contents($file, $source);
            $probe = new LockLossConnection($connection, $setup, $lock, $worker);
            try {
                try {
                    new Migrator($probe, $directory, table: $history, lockTimeout: 0)->run();
                    self::fail('A migration continued on a replacement lock session.');
                } catch (DatabaseException) {
                }
                self::assertTrue($probe->injected);
                self::assertSame(1, $probe->acquired);
                self::assertSame([], $setup->query("SELECT * FROM {$table}")->fetchAll());
                self::assertSame(0, (int) $setup->query("SELECT COUNT(*) FROM {$history}")->fetchColumn());
            } finally {
                $release = $setup->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lock]);
                $setup->exec("DROP TABLE IF EXISTS {$history}");
                unlink($file);
                rmdir($directory);
            }
        });
    }

    #[DataProvider('drivers')]
    public function testACaughtDeadlockRefusesFurtherWorkUntilTheTransactionUnwinds(string $driver): void
    {
        $this->deadlock($driver, false);
    }

    #[DataProvider('drivers')]
    public function testACaughtNestedDeadlockAlsoFailsTheOuterTransactionScope(string $driver): void
    {
        $this->deadlock($driver, true);
    }

    #[DataProvider('drivers')]
    public function testSessionLossAfterAnAcknowledgedRawCommitMakesNoRollbackClaim(string $driver): void
    {
        $this->using($driver, static function (ManagedConnection $connection, \PDO $setup, string $table): void {
            $blocked = false;
            try {
                $connection->transaction(static function (ConnectionContract $tx) use (
                    $setup,
                    $table,
                    &$blocked,
                ): void {
                    $tx->insert("INSERT INTO {$table} VALUES (?)", [7]);
                    $tx->execute('COMMIT');
                    $id = $tx->first('SELECT CONNECTION_ID() AS id')['id'];
                    $setup->exec('KILL CONNECTION ' . (int) $id);
                    try {
                        $tx->first('SELECT 1 AS lost');
                        self::fail('The killed session still accepted commands.');
                    } catch (DatabaseException) {
                    }
                    try {
                        $tx->insert("INSERT INTO {$table} VALUES (?)", [8]);
                        self::fail('The callback wrote after losing its session.');
                    } catch (DatabaseException $error) {
                        $blocked = true;
                        self::assertStringContainsString('lost', $error->getMessage());
                        self::assertDoesNotMatchRegularExpression('/committed|rolled back/i', $error->getMessage());
                    }
                });
                self::fail('The lost scope returned successfully.');
            } catch (DatabaseException $error) {
                self::assertStringContainsString('lost', $error->getMessage());
                self::assertDoesNotMatchRegularExpression('/committed|rolled back/i', $error->getMessage());
            }
            self::assertTrue($blocked);
            self::assertSame(
                [7],
                array_map('intval', $setup->query("SELECT id FROM {$table}")->fetchAll(\PDO::FETCH_COLUMN)),
            );
        });
    }

    /**
     * The smaller transaction is the deadlock victim. A separate owned child
     * holds the other rows, so this sequence works for blocking PDO too.
     */
    private function deadlock(string $driver, bool $nested): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('The live deadlock sequence needs pcntl to coordinate PDO.');
        }
        $dsn = (string) getenv('CRAB_TEST_MYSQL_DSN');
        $raw = static fn(): \PDO => new \PDO($dsn, 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $table = 'mysql_fix_deadlock_' . bin2hex(random_bytes(6));
        $directory = sys_get_temp_dir() . '/crab-deadlock-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $setup = $raw();
        $setup->exec("CREATE TABLE {$table} (id INT PRIMARY KEY, v INT) ENGINE=InnoDB");
        $setup->exec(
            "INSERT INTO {$table} VALUES "
                . implode(',', array_map(static fn(int $id): string => "({$id},0)", range(1, 30))),
        );
        $setup = null; // No live PDO socket is inherited by the child.
        $pid = pcntl_fork();
        if ($pid === -1) {
            $raw()->exec("DROP TABLE {$table}");
            rmdir($directory);
            throw new \RuntimeException('Cannot fork the owned deadlock coordinator.');
        }
        if ($pid === 0) {
            pcntl_alarm(10);
            try {
                $other = $raw();
                while (!is_file($directory . '/a')) {
                    clearstatcache(true, $directory . '/a');
                    usleep(1000);
                }
                $other->beginTransaction();
                $other->exec("UPDATE {$table} SET v=200 WHERE id BETWEEN 2 AND 30");
                file_put_contents($directory . '/b', 'ready');
                $other->exec("UPDATE {$table} SET v=200 WHERE id=1");
                $other->commit();
                exit(0);
            } catch (\Throwable $error) {
                file_put_contents($directory . '/error', $error->getMessage());
                exit(1);
            }
        }
        try {
            WorkerHarness::run([], static function (Worker $worker) use (
                $dsn,
                $raw,
                $driver,
                $nested,
                $table,
                $directory,
            ): void {
                $connection = $driver === 'pdo'
                    ? new PdoConnection($raw)
                    : new Connection($dsn, 'root', worker: $worker);
                $callbackRan = false;
                $attempts = 0;
                try {
                    try {
                        $connection->transaction(static function (ManagedConnection $tx) use (
                            $worker,
                            $nested,
                            $table,
                            $directory,
                            &$callbackRan,
                            &$attempts,
                        ): void {
                            $tx->execute("UPDATE {$table} SET v=100 WHERE id=1");
                            file_put_contents($directory . '/a', 'ready');
                            $deadline = (hrtime(true) / 1e9) + 5;
                            while (!is_file($directory . '/b')) {
                                clearstatcache(true, $directory . '/b');
                                if ((hrtime(true) / 1e9) >= $deadline) {
                                    throw new \RuntimeException('The deadlock coordinator did not acquire its rows.');
                                }
                                Async::sleep($worker->loop, 0.001);
                            }
                            $work = static function (ConnectionContract $inner) use ($table, &$attempts): void {
                                ++$attempts;
                                $inner->execute("UPDATE {$table} SET v=100 WHERE id=2");
                            };
                            try {
                                if ($nested) {
                                    $tx->transaction($work);
                                } else {
                                    $work($tx);
                                }
                                self::fail('The smaller transaction was not the deadlock victim.');
                            } catch (DatabaseException $error) {
                                self::assertSame(1213, $error->code());
                            }
                            foreach ([
                                static fn() => $tx->insert("INSERT INTO {$table} VALUES (100,901)"),
                                static fn() => $tx->transaction(
                                    static fn() => self::fail('A nested callback ran after deadlock.'),
                                ),
                                static fn() => $tx->afterCommit(
                                    static fn() => self::fail('A commit callback ran after deadlock.'),
                                ),
                                static fn() => $tx->withoutReconnect(
                                    static fn() => self::fail('A fixed-session callback ran after deadlock.'),
                                ),
                            ] as $command) {
                                try {
                                    $command();
                                    self::fail('A failed transaction permitted more callback work.');
                                } catch (DatabaseException $error) {
                                    self::assertSame(1213, $error->code());
                                }
                            }
                            $callbackRan = true;
                        });
                        self::fail('A deadlock victim committed its managed scope.');
                    } catch (DatabaseException $error) {
                        self::assertSame(1213, $error->code());
                    }
                    self::assertTrue($callbackRan);
                    self::assertSame(1, $attempts, 'The failed statement was replayed.');
                    $connection->insert("INSERT INTO {$table} VALUES (101,902)");
                } finally {
                    $connection->close();
                }
            });
            pcntl_waitpid($pid, $status);
            $pid = 0;
            self::assertSame(
                0,
                pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1,
                is_file($directory . '/error')
                    ? (string) file_get_contents($directory . '/error')
                    : 'The coordinator did not finish.',
            );
            $setup = $raw();
            self::assertSame(0, (int) $setup->query("SELECT COUNT(*) FROM {$table} WHERE id=100")->fetchColumn());
            self::assertSame(902, (int) $setup->query("SELECT v FROM {$table} WHERE id=101")->fetchColumn());
            self::assertSame(30, (int) $setup->query("SELECT COUNT(*) FROM {$table} WHERE v=200")->fetchColumn());
        } finally {
            if ($pid > 0 && pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            $setup = $raw();
            $setup->exec("DROP TABLE {$table}");
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    private function using(string $driver, \Closure $body): void
    {
        WorkerHarness::run([], static function (Worker $worker) use ($driver, $body): void {
            $dsn = (string) getenv('CRAB_TEST_MYSQL_DSN');
            $setup = new \PDO($dsn, 'root', '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $table = 'mysql_fix_safety_' . bin2hex(random_bytes(6));
            $setup->exec("CREATE TABLE {$table} (id INT PRIMARY KEY) ENGINE=InnoDB");
            $connect = static fn(): \PDO => new \PDO($dsn, 'root', '');
            $connection = $driver === 'pdo'
                ? new PdoConnection($connect)
                : new Connection($dsn, 'root', worker: $worker);
            try {
                $body($connection, $setup, $table, $worker);
            } finally {
                $connection->close();
                $setup->exec("DROP TABLE IF EXISTS {$table}");
            }
        });
    }
}

/**
 * Transfers the lock immediately after a successful ownership check.
 * Forwarding the internal session scope keeps the race at the real driver.
 */
final class LockLossConnection implements ManagedConnection
{
    public bool $injected = false;
    public ?int $acquired = null;

    public function __construct(
        private readonly ManagedConnection $delegate,
        private readonly \PDO $killer,
        private readonly string $lock,
        private readonly Worker $worker,
    ) {}

    public function driver(): string
    {
        return $this->delegate->driver();
    }

    public function table(string $table): Query
    {
        return new Query($this, $table);
    }

    public function select(string $sql, array $bindings = []): array
    {
        return $this->delegate->select($sql, $bindings);
    }

    public function first(string $sql, array $bindings = []): ?array
    {
        $row = $this->delegate->first($sql, $bindings);
        if (!$this->injected && str_contains($sql, 'IS_USED_LOCK') && (int) ($row['owned'] ?? 0) === 1) {
            $this->injected = true;
            $id = $this->delegate->first('SELECT CONNECTION_ID() AS id')['id'];
            $this->killer->exec('KILL CONNECTION ' . (int) $id);
            $free = $this->killer->prepare('SELECT IS_FREE_LOCK(?)');
            \Crab\Tests\Support\Wait::until(
                $this->worker->loop,
                function () use ($free): bool {
                    $free->execute([$this->lock]);
                    return (int) $free->fetchColumn() === 1;
                },
                'the killed MySQL session to release its migration lock',
            );
            $statement = $this->killer->prepare('SELECT GET_LOCK(?, 0)');
            $statement->execute([$this->lock]);
            $this->acquired = (int) $statement->fetchColumn();
        }
        return $row;
    }

    public function insert(string $sql, array $bindings = []): int|string
    {
        return $this->delegate->insert($sql, $bindings);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->delegate->execute($sql, $bindings);
    }

    public function transaction(\Closure $callback): mixed
    {
        return $this->delegate->transaction(fn(): mixed => $callback($this));
    }

    public function afterCommit(\Closure $callback): void
    {
        $this->delegate->afterCommit($callback);
    }

    public function inTransaction(): bool
    {
        return $this->delegate->inTransaction();
    }

    public function withoutReconnect(\Closure $callback): mixed
    {
        return $this->delegate->withoutReconnect(fn(): mixed => $callback($this));
    }

    public function isOpen(): bool
    {
        return $this->delegate->isOpen();
    }

    public function close(): void
    {
        $this->delegate->close();
    }
}
