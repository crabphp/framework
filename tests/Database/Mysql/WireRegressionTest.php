<?php

declare(strict_types=1);

namespace Crab\Tests\Database\Mysql;

use Crab\Database\DatabaseException;
use Crab\Database\DatabaseManager;
use Crab\Database\Mysql\Connection;
use Crab\Database\Mysql\Settings;
use Crab\Database\Mysql\Stream;
use Crab\Database\Mysql\StreamFailure;
use Crab\Engine\Async\Async;
use Crab\Engine\Network\DnsResolver;
use Crab\Engine\Process\Worker;
use PHPUnit\Framework\TestCase;

/**
 * Uses owned peers to reproduce invalid handles, malformed acknowledgements
 * and authentication traffic that previously evaded the setup bound.
 */
final class WireRegressionTest extends TestCase
{
    public function testAnInvalidPreparedHandleIsClosedAndTheNextCallPreparesAgain(): void
    {
        foreach ([1615, 1243] as $number) {
            ScriptedServer::run(static function (ScriptedServer $server) use ($number): void {
                self::login($server);
                $server->prepare(1);
                $server->sequence = 0;
                self::assertSame("\x17" . pack('V', 1), substr($server->receive(), 0, 5));
                $server->send("\xff" . pack('v', $number) . '#HY000Handle is invalid');
                $server->sequence = 0;
                self::assertSame("\x19" . pack('V', 1), $server->receive());
                $server->prepare(2);
                $server->sequence = 0;
                self::assertSame("\x17" . pack('V', 2), substr($server->receive(), 0, 5));
                $server->ok();
            }, static function (string $dsn) use ($number): void {
                $connection = new Connection($dsn);
                try {
                    try {
                        $connection->execute('UPDATE example SET n = 1');
                        self::fail('The invalid-handle error was hidden by a replay.');
                    } catch (DatabaseException $error) {
                        self::assertSame($number, $error->code());
                    }
                    self::assertSame(0, $connection->execute('UPDATE example SET n = 1'));
                } finally {
                    $connection->close();
                }
            });
        }
    }

    public function testContinuousFastAuthenticationCannotBypassTheSetupBound(): void
    {
        ScriptedServer::run(
            static function (ScriptedServer $server): void {
                $server->greeting('caching_sha2_password');
                $server->receive();
                try {
                    for ($i = 0; $i < 100_000; ++$i) {
                        $server->send("\x01\x03");
                    }
                    $server->ok();
                    $server->prepare();
                    $server->sequence = 0;
                    $server->receive();
                    $server->ok();
                } catch (\RuntimeException) {
                    // The bounded client closes while this peer is still talking.
                }
            },
            static function (string $dsn): void {
                $connection = new Connection($dsn, timeout: 0.01);
                $start = hrtime(true);
                try {
                    $connection->execute('SELECT 1');
                    self::fail('Repeated fast-auth replies bypassed connection setup.');
                } catch (DatabaseException) {
                    self::assertLessThan(5.0, (hrtime(true) - $start) / 1e9);
                } finally {
                    $connection->close();
                }
            },
        );
    }

    public function testAProgressingAuthenticationSwitchStillSharesTheSetupDeadline(): void
    {
        ScriptedServer::run(static function (ScriptedServer $server): void {
            $server->greeting('caching_sha2_password');
            $server->receive();
            Async::sleep(\Crab\Engine\EventLoop::current(), 0.03);
            $server->send("\xfemysql_native_password\0abcdefghijklmnopqrst\0");
            self::assertTrue($server->eof());
        }, static function (string $dsn): void {
            $connection = new Connection($dsn, timeout: 0.01);
            try {
                $connection->execute('SELECT 1');
                self::fail('An authentication switch started after the deadline.');
            } catch (DatabaseException $error) {
                self::assertStringContainsString('timed out', $error->getMessage());
            } finally {
                $connection->close();
            }
        });
    }

    public function testBufferedAuthenticationBytesStillRespectTheOriginalSetupDeadline(): void
    {
        ScriptedServer::run(static function (ScriptedServer $server): void {
            $server->send(str_repeat('x', 10_000));
        }, static function (string $dsn, Worker $worker): void {
            $stream = new Stream($worker->loop);
            $deadline = (hrtime(true) / 1e9) + 5.0;
            try {
                $stream->open(new Settings($dsn), new DnsResolver(fallback: false), $deadline, null);
                self::assertSame(4, strlen($stream->read(4, $deadline)));
                self::assertSame('x', $stream->read(1, $deadline));
                $deadline = (hrtime(true) / 1e9) - 1.0;
                try {
                    $stream->read(1, $deadline);
                    self::fail('Buffered authentication bytes extended the original setup deadline.');
                } catch (StreamFailure $error) {
                    self::assertStringContainsString('timed out', $error->getMessage());
                }
            } finally {
                $stream->close();
            }
        });
    }

    public function testATruncatedOkAcknowledgementClosesTheCommandExchange(): void
    {
        ScriptedServer::run(static function (ScriptedServer $server): void {
            self::login($server);
            $server->prepare();
            $server->sequence = 0;
            $server->receive();
            $server->send("\0\0\0\x02\0");
            self::assertTrue($server->eof());
        }, static function (string $dsn): void {
            $connection = new Connection($dsn);
            try {
                $connection->execute('UPDATE example SET n = 1');
                self::fail('The acknowledgement omitted its mandatory warnings field.');
            } catch (DatabaseException $error) {
                self::assertStringContainsString('truncated', $error->getMessage());
            } finally {
                $connection->close();
            }
        });
    }

    public function testTheGuideExplainsRsaTrustAndVerifiedTlsDefaults(): void
    {
        $guide = (string) file_get_contents(__DIR__ . '/../../../docs/database.md');
        self::assertMatchesRegularExpression('/RSA.*(?:trust|authenticat).*(?:server|peer)/is', $guide);
        self::assertMatchesRegularExpression('/TLS.*untrusted network/is', $guide);
        self::assertStringContainsString('verify_peer', $guide);
        self::assertStringContainsString('verify_peer_name', $guide);
    }

    public function testConsoleTlsCannotAuthenticateToAScriptedPlaintextPeer(): void
    {
        ScriptedServer::runPdo(static function (ScriptedServer $server): void {
            $server->greeting('mysql_native_password');
            self::assertTrue($server->eof(), 'PDO sent credentials despite requiring TLS.');
        }, static function (string $dsn): void {
            $manager = new DatabaseManager(['main' => ['dsn' => $dsn, 'options' => ['ssl' => true]]], 'main');
            try {
                $manager->connection()->first('SELECT application_work');
                self::fail('PDO accepted a plaintext session when TLS was requested.');
            } catch (DatabaseException $error) {
                self::assertStringContainsString('Cannot connect', $error->getMessage());
            } finally {
                $manager->close();
            }
        });
    }

    public function testConsoleTlsChecksTheReportedCipherBeforeLendingItsConnection(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $request = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $private);
        $path = tempnam(sys_get_temp_dir(), 'crab-pdo-cert-');
        file_put_contents($path, $pem . $private);
        chmod($path, 0o600);
        try {
            ScriptedServer::runPdo(static function (ScriptedServer $server) use ($path): void {
                $server->greeting('mysql_native_password', 0x800);
                $request = $server->receive();
                self::assertSame(32, strlen($request));
                self::assertNotSame(0, unpack('V', $request)[1] & 0x800);
                $server->encrypt($path);
                $server->receive();
                $server->ok();
                $server->sequence = 0;
                self::assertSame("\x03SHOW SESSION STATUS LIKE 'Ssl_cipher'", $server->receive());
                $server->send("\x02");
                $server->send(ScriptedServer::column('Variable_name', 253));
                $server->send(ScriptedServer::column('Value', 253));
                $server->send("\xfe\0\0\x02\0");
                $server->send(\Crab\Database\Mysql\Codec::string('Ssl_cipher') . "\0");
                $server->send("\xfe\0\0\x02\0");
                $server->sequence = 0;
                self::assertSame("\x01", $server->receive(), 'Application SQL ran before the failed TLS status check.');
            }, static function (string $dsn) use ($path): void {
                $manager = new DatabaseManager([
                    'main' => ['dsn' => $dsn, 'options' => ['ssl' => ['cafile' => $path]]],
                ], 'main');
                try {
                    $manager->connection()->first('SELECT application_work');
                    self::fail('A session reporting no SSL cipher was lent to application work.');
                } catch (DatabaseException $error) {
                    self::assertStringContainsString('did not establish the requested TLS', $error->getMessage());
                } finally {
                    $manager->close();
                }
            });
        } finally {
            unlink($path);
        }
    }

    private static function login(ScriptedServer $server): void
    {
        $server->greeting('mysql_native_password');
        $server->receive();
        $server->ok();
    }
}
