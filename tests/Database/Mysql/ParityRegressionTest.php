<?php

declare(strict_types=1);

namespace Crab\Tests\Database\Mysql;

use Crab\Database\DatabaseManager;
use Crab\Database\Mysql\Connection;
use Crab\Database\PdoConnection;
use Crab\Engine\Process\Worker;
use Crab\Testing\WorkerHarness;
use PHPUnit\Framework\TestCase;

/**
 * Compares the review's legal MySQL inputs with native PDO fetches.
 * Fixtures and session mode changes stay on this test's own connections.
 */
final class ParityRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('CRAB_TEST_MYSQL_DSN') === false) {
            self::markTestSkipped('Set CRAB_TEST_MYSQL_DSN to crab_test to compare MySQL values.');
        }
    }

    public function testZerofillIntegersRetainPdosStringTypeAndDisplayWidth(): void
    {
        $this->using(static function (Connection $async, PdoConnection $pdo, string $table): void {
            $pdo->execute("CREATE TABLE {$table} (id INT PRIMARY KEY, z INT(5) ZEROFILL)");
            foreach ([1, 0, 12345] as $id => $value) {
                $pdo->insert("INSERT INTO {$table} VALUES (?, ?)", [$id, $value]);
            }
            $sql = "SELECT * FROM {$table} ORDER BY id";
            self::assertSame($pdo->select($sql), $async->select($sql));
        });
    }

    public function testTextFallbackBitValuesMatchBinaryFetchesAndPdo(): void
    {
        $this->using(static function (Connection $async, PdoConnection $pdo, string $table): void {
            $pdo->execute("CREATE TABLE {$table} (id INT PRIMARY KEY, b BIT(64))");
            foreach ([str_repeat("\xff", 8), "\x80" . str_repeat("\0", 7), "\x01\xff", "\0"] as $id => $value) {
                $pdo->insert("INSERT INTO {$table} VALUES (?, ?)", [$id, $value]);
            }
            $sql = "SELECT b FROM {$table} ORDER BY id";
            $expected = $pdo->select($sql);
            foreach ([$pdo, $async] as $connection) {
                $connection->execute("PREPARE fix_bit FROM 'SELECT b FROM {$table} ORDER BY id'");
                try {
                    self::assertSame($expected, $connection->select('EXECUTE fix_bit'));
                } finally {
                    $connection->execute('DEALLOCATE PREPARE fix_bit');
                }
            }
            self::assertSame($expected, $async->select($sql));
        });
    }

    public function testExecutableCommentsAcceptPositionalAndNamedParameters(): void
    {
        $this->using(static function (Connection $async, PdoConnection $pdo): void {
            self::assertSame($pdo->first('SELECT /*!80000 ? */ AS value', [42]), $async->first('SELECT /*!80000 ? */ AS value', [42]));
            self::assertSame(['value' => 42], $async->first('SELECT /*!80000 :number */ AS value', ['number' => 42]));
            self::assertSame(['value' => 42], $async->first('SELECT /*+ ? :ignored */ :number AS value', ['number' => 42]));
            self::assertSame(['value' => 42], $async->first('SELECT /*!999999 :ignored */ :number AS value', ['number' => 42]));
        });
    }

    public function testExecutableCommentGatesFollowTheRealServersVersion(): void
    {
        $this->using(static function (Connection $async, PdoConnection $pdo): void {
            $version = $pdo->first('SELECT VERSION() AS version')['version'];
            self::assertSame(1, preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $parts));
            $active = (int) $parts[1] * 10000 + (int) $parts[2] * 100 + (int) $parts[3];
            $inactive = $active + 1;
            self::assertSame(['value' => 43], $pdo->first("SELECT 1 /*!{$active} + ? */ AS value", [42]));
            self::assertSame(['value' => 43], $async->first("SELECT 1 /*!{$active} + :number */ AS value", ['number' => 42]));
            self::assertSame(['value' => 1], $pdo->first("SELECT 1 /*!{$inactive} + ? */ AS value"));
            self::assertSame(['value' => 1], $async->first("SELECT 1 /*!{$inactive} + :ignored */ AS value"));
        });
    }

    public function testNoBackslashEscapesUsesTheServersCurrentStatusWhenPreparing(): void
    {
        $this->using(static function (Connection $async, PdoConnection $pdo): void {
            $sql = "SELECT 'slash\\' AS literal, ? AS value";
            $named = "SELECT 'slash\\' AS literal, :number AS value";
            foreach ([$pdo, $async] as $connection) {
                $mode = $connection->first('SELECT @@sql_mode AS mode')['mode'];
                try {
                    $connection->execute("SET SESSION sql_mode='NO_BACKSLASH_ESCAPES'");
                    self::assertSame(['literal' => 'slash\\', 'value' => 42], $connection->first($sql, [42]));
                    self::assertSame(['literal' => 'slash\\', 'value' => 42], $connection->first($named, ['number' => 42]));
                } finally {
                    $connection->execute('SET SESSION sql_mode=?', [$mode]);
                }
            }
        });
    }

    public function testABackslashDoesNotEscapeTheClosingBacktickIdentifier(): void
    {
        $this->using(static function (Connection $async, PdoConnection $pdo): void {
            $sql = "SELECT 1 AS `a\\`, :number AS value";
            self::assertSame(['a\\' => 1, 'value' => 42], $pdo->first($sql, ['number' => 42]));
            self::assertSame($pdo->first($sql, ['number' => 42]), $async->first($sql, ['number' => 42]));
        });
    }

    public function testASixthVersionDigitWithoutWhitespaceBelongsToTheCommentBody(): void
    {
        $this->using(static function (Connection $async, PdoConnection $pdo): void {
            $expected = $pdo->first('SELECT /*!800000+ ? */ AS value', [42]);
            self::assertSame(['value' => 42], $expected);
            self::assertSame($expected, $async->first('SELECT /*!800000+ :number */ AS value', ['number' => 42]));
        });
    }

    public function testConsoleSslOptionsOpenAnEncryptedPdoSession(): void
    {
        $manager = new DatabaseManager(['main' => [
            'dsn' => (string) getenv('CRAB_TEST_MYSQL_DSN'),
            'username' => 'root',
            'async' => true,
            'options' => ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]],
        ]], 'main');
        try {
            self::assertNotSame('', $manager->connection()->first("SHOW SESSION STATUS LIKE 'Ssl_cipher'")['Value']);
        } finally {
            $manager->close();
        }
    }

    public function testVectorValuesKeepPdosRawBinaryRepresentation(): void
    {
        $this->using(static function (Connection $async, PdoConnection $pdo): void {
            $version = $pdo->first('SELECT VERSION() AS version')['version'];
            if ((int) $version < 9) {
                self::markTestSkipped('VECTOR parity requires MySQL 9 or newer.');
            }
            $sql = "SELECT STRING_TO_VECTOR('[1,2]') AS value";
            $expected = $pdo->first($sql);
            self::assertSame('0000803f00000040', bin2hex($expected['value']));
            self::assertSame($expected, $async->first($sql));
        });
    }

    private function using(\Closure $body): void
    {
        WorkerHarness::run([], static function (Worker $worker) use ($body): void {
            $dsn = (string) getenv('CRAB_TEST_MYSQL_DSN');
            $owner = static fn (): \PDO => new \PDO($dsn, 'root', '');
            $pdo = new PdoConnection($owner);
            $async = new Connection($owner, $dsn, 'root', worker: $worker);
            $table = 'mysql_fix_parity_' . bin2hex(random_bytes(6));
            try {
                $body($async, $pdo, $table);
            } finally {
                $async->close();
                $pdo->execute("DROP TABLE IF EXISTS {$table}");
                $pdo->close();
            }
        });
    }
}
