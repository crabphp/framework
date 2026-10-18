<?php

declare(strict_types=1);

namespace Crab\Tests\Database\Mysql;

use Crab\Database\DatabaseException;
use Crab\Database\DatabaseManager;
use Crab\Database\Mysql\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Settings preserve PDO's DSN shape while selecting only the SSL stream options
 * the async transport understands. Invalid endpoints fail before opening sockets.
 */
final class SettingsTest extends TestCase
{
    public function testDsnValuesAndSslOptionsAreReadWithoutPdoAttributes(): void
    {
        $settings =
            new Settings('mysql:host=localhost;port=3307;dbname=crab_test;charset=latin1;unix_socket=/tmp/mysql.sock', [
                \PDO::ATTR_TIMEOUT => 1,
                'ssl' => ['cafile' => '/tmp/ca.pem'],
            ]);
        self::assertSame(['localhost', 3307, 'crab_test', 8, '/tmp/mysql.sock'], [
            $settings->host,
            $settings->port,
            $settings->database,
            $settings->charset,
            $settings->socket,
        ]);
        self::assertTrue($settings->encrypted);
        self::assertSame(['cafile' => '/tmp/ca.pem'], $settings->ssl);
        self::assertFalse(new Settings('mysql:dbname=crab_test')->encrypted);
        self::assertTrue(new Settings('mysql:dbname=crab_test', ['ssl' => true])->encrypted);
    }

    public function testInvalidPortsDriversCharsetsAndSslOptionsAreRefused(): void
    {
        foreach ([
            ['sqlite::memory:', []],
            ['mysql:port=0', []],
            ['mysql:port=65536', []],
            ['mysql:port=abc', []],
            ['mysql:charset=unknown', []],
            ['mysql:', ['ssl' => 'yes']],
        ] as [$dsn, $options]) {
            try {
                new Settings($dsn, $options);
                self::fail('Invalid MySQL settings were accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testConsoleTlsOptionsMapToPdosConstructionAttributes(): void
    {
        $map = new \ReflectionMethod(DatabaseManager::class, 'pdoOptions');
        $ssl = [
            'cafile' => '/ca',
            'capath' => '/trust',
            'local_cert' => '/cert',
            'local_pk' => '/key',
            'ciphers' => 'HIGH',
        ];
        self::assertSame(
            [
                \PDO::ATTR_TIMEOUT => 3,
                \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
                \PDO::MYSQL_ATTR_SSL_CA => '/ca',
                \PDO::MYSQL_ATTR_SSL_CAPATH => '/trust',
                \PDO::MYSQL_ATTR_SSL_CERT => '/cert',
                \PDO::MYSQL_ATTR_SSL_KEY => '/key',
                \PDO::MYSQL_ATTR_SSL_CIPHER => 'HIGH',
            ],
            $map->invoke(null, [\PDO::ATTR_TIMEOUT => 3, 'ssl' => $ssl]),
        );
        self::assertSame(
            [
                \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
                \PDO::MYSQL_ATTR_SSL_CIPHER => 'DEFAULT',
            ],
            $map->invoke(null, ['ssl' => true]),
        );
        self::assertSame(
            [
                \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
                \PDO::MYSQL_ATTR_SSL_CIPHER => 'DEFAULT',
            ],
            $map->invoke(null, ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]),
        );
        self::assertSame(
            [\PDO::MYSQL_ATTR_SSL_CA => '/same', \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true],
            $map->invoke(null, [\PDO::MYSQL_ATTR_SSL_CA => '/same', 'ssl' => ['cafile' => '/same']]),
        );
        self::assertSame([\PDO::ATTR_TIMEOUT => 3], $map->invoke(null, [\PDO::ATTR_TIMEOUT => 3, 'ssl' => false]));
    }

    public function testConsoleTlsRefusesPoliciesPdoCannotPreserve(): void
    {
        $map = new \ReflectionMethod(DatabaseManager::class, 'pdoOptions');
        foreach ([
            [['ssl' => ['verify_peer' => false]], 'couples'],
            [['ssl' => ['verify_peer_name' => false]], 'couples'],
            [['ssl' => ['peer_name' => 'other.example']], 'peer_name'],
            [['ssl' => ['peer_fingerprint' => 'abc']], 'peer_fingerprint'],
            [['ssl' => ['crypto_method' => 1]], 'crypto_method'],
            [['ssl' => true, \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false], 'conflicts'],
            [['ssl' => ['cafile' => '/ca'], \PDO::MYSQL_ATTR_SSL_CA => '/other'], 'conflicts'],
            [['ssl' => ['ciphers' => 'HIGH'], \PDO::MYSQL_ATTR_SSL_CIPHER => 'DEFAULT'], 'conflicts'],
        ] as [$options, $reason]) {
            try {
                $map->invoke(null, $options);
                self::fail('PDO accepted a TLS policy it cannot preserve.');
            } catch (DatabaseException $error) {
                self::assertStringContainsString($reason, $error->getMessage());
            }
        }
    }
}
