<?php

declare(strict_types=1);

namespace Crab\Tests\Database\Mysql;

use Crab\Database\DatabaseException;
use Crab\Database\Mysql\Authentication;
use Crab\Database\Mysql\Codec;
use Crab\Database\Mysql\Connection;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Process\Worker;
use PHPUnit\Framework\TestCase;

/**
 * Scripted handshakes cover authentication paths independent of the local server's
 * configured account plugin. No server settings or accounts are changed.
 */
final class AuthenticationTest extends TestCase
{
    public function testNativeAndCachingFastAuthenticationSendTheExpectedResponse(): void
    {
        foreach (['mysql_native_password', 'caching_sha2_password'] as $plugin) {
            ScriptedServer::run(static function (ScriptedServer $server) use ($plugin): void {
                $server->greeting($plugin);
                $response = $server->receive();
                $offset = 32;
                self::assertSame('user', Codec::terminated($response, $offset));
                self::assertSame(Authentication::response($plugin, 'secret', '12345678abcdefgh1234'), Codec::readString($response, $offset));
                if ($plugin === 'caching_sha2_password') {
                    $server->send("\x01\x03");
                }
                $server->ok();
                $server->prepare();
                $server->sequence = 0;
                self::assertSame("\x17", $server->receive()[0]);
                $server->ok();
            }, static function (string $dsn, Worker $worker): void {
                $connection = new Connection(static fn () => null, $dsn, 'user', 'secret', worker: $worker);
                try {
                    self::assertSame(0, $connection->execute('select 1'));
                } finally {
                    $connection->close();
                }
            });
        }
    }

    public function testAnAuthenticationSwitchUsesTheNewPluginAndSalt(): void
    {
        ScriptedServer::run(static function (ScriptedServer $server): void {
            $server->greeting('caching_sha2_password');
            $server->receive();
            $salt = 'abcdefghijklmnopqrst';
            $server->send("\xfemysql_native_password\0" . $salt . "\0");
            self::assertSame(Authentication::response('mysql_native_password', 'secret', $salt), $server->receive());
            $server->ok();
            $server->prepare();
            $server->sequence = 0;
            $server->receive();
            $server->ok();
        }, static function (string $dsn): void {
            $connection = new Connection(static fn () => null, $dsn, 'user', 'secret');
            try {
                self::assertSame(0, $connection->execute('select 1'));
            } finally {
                $connection->close();
            }
        });
    }

    public function testHostNamesUseTheEngineResolverBeforeConnecting(): void
    {
        ScriptedServer::run(static function (ScriptedServer $server): void {
            $server->greeting('mysql_native_password');
            $server->receive();
            $server->ok();
            $server->prepare();
            $server->sequence = 0;
            $server->receive();
            $server->ok();
        }, static function (string $dsn): void {
            $connection = new Connection(static fn () => null, str_replace('127.0.0.1', 'localhost', $dsn));
            try {
                self::assertSame(0, $connection->execute('select 1'));
            } finally {
                $connection->close();
            }
        });
    }

    public function testFullCachingAuthenticationUsesTheServersRsaKey(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $public = openssl_pkey_get_details($key)['key'];
        ScriptedServer::run(static function (ScriptedServer $server) use ($key, $public): void {
            $server->greeting('caching_sha2_password');
            $server->receive();
            $server->send("\x01\x04");
            self::assertSame("\x02", $server->receive());
            $server->send("\x01" . $public);
            self::assertTrue(openssl_private_decrypt($server->receive(), $plain, $key, OPENSSL_PKCS1_OAEP_PADDING));
            $expected = 'secret' . "\0";
            $salt = '12345678abcdefgh1234';
            for ($i = 0; $i < strlen($expected); ++$i) {
                $expected[$i] = $expected[$i] ^ $salt[$i % 20];
            }
            self::assertTrue(hash_equals($expected, $plain));
            $server->ok();
            $server->prepare();
            $server->sequence = 0;
            $server->receive();
            $server->ok();
        }, static function (string $dsn): void {
            $connection = new Connection(static fn () => null, $dsn, 'user', 'secret');
            try {
                self::assertSame(0, $connection->execute('select 1'));
            } finally {
                $connection->close();
            }
        });
    }

    public function testUnixSocketsCarryFullAuthenticationWithoutTcpOrDns(): void
    {
        ScriptedServer::run(static function (ScriptedServer $server): void {
            $server->greeting('caching_sha2_password');
            $server->receive();
            $server->send("\x01\x04");
            self::assertTrue(hash_equals("secret\0", $server->receive()));
            $server->ok();
            $server->prepare();
            $server->sequence = 0;
            $server->receive();
            $server->ok();
        }, static function (string $dsn): void {
            $connection = new Connection(static fn () => null, $dsn, 'user', 'secret');
            try {
                self::assertSame(0, $connection->execute('select 1'));
            } finally {
                $connection->close();
            }
        }, unix: true);
    }

    public function testTlsStartsAfterSslRequestAndProtectsTheFullPasswordExchange(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $request = openssl_csr_new(['commonName' => 'localhost'], $key);
        $certificate = openssl_csr_sign($request, null, $key, 1);
        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $private);
        $path = tempnam(sys_get_temp_dir(), 'crab-mysql-cert-');
        file_put_contents($path, $pem . $private);
        chmod($path, 0o600);
        try {
            ScriptedServer::run(static function (ScriptedServer $server) use ($path): void {
                $server->greeting('caching_sha2_password', 0x800);
                $request = $server->receive();
                self::assertSame(32, strlen($request));
                $offset = 0;
                self::assertNotSame(0, Codec::integer($request, $offset, 4) & 0x800);
                $server->encrypt($path);
                $server->receive();
                $server->send("\x01\x04");
                self::assertTrue(hash_equals("secret\0", $server->receive()));
                $server->ok();
                $server->prepare();
                $server->sequence = 0;
                $server->receive();
                $server->ok();
            }, static function (string $dsn): void {
                $connection = new Connection(static fn () => null, $dsn, 'user', 'secret', ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
                try {
                    self::assertSame(0, $connection->execute('select 1'));
                } finally {
                    $connection->close();
                }
            });
        } finally {
            unlink($path);
        }
    }

    public function testWrongPasswordsKeepTheServerCodeWithoutExposingBoundValues(): void
    {
        ScriptedServer::run(static function (ScriptedServer $server): void {
            $server->greeting('caching_sha2_password');
            $server->receive();
            $server->send("\xff" . pack('v', 1045) . '#28000Access denied');
        }, static function (string $dsn): void {
            $connection = new Connection(static fn () => null, $dsn, 'user', 'secret');
            try {
                $connection->execute('select ?', ['private-value']);
                self::fail('Authentication was accepted.');
            } catch (DatabaseException $error) {
                self::assertSame(1045, $error->code());
                self::assertSame(1045, $error->getCode());
                self::assertSame('28000', $error->sqlState());
                self::assertStringNotContainsString('secret', $error->getMessage());
                self::assertStringNotContainsString('private-value', $error->getMessage());
            } finally {
                $connection->close();
            }
        });
    }

    public function testDefaultTlsVerificationRefusesAnUntrustedCertificate(): void
    {
        $this->verifiedTls('127.0.0.1', false, false);
    }

    public function testDefaultTlsNameVerificationRefusesATrustedCertificateForAnotherHost(): void
    {
        $this->verifiedTls('other.example', true, false);
    }

    public function testDefaultTlsVerificationAcceptsTheConfiguredTrustedCa(): void
    {
        $this->verifiedTls('127.0.0.1', true, true);
    }

    /**
     * Trust and name checks use the driver's defaults, without disabling either.
     * A rejected handshake must fail before the authentication packet is sent.
     */
    private function verifiedTls(string $name, bool $trusted, bool $accepted): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $request = openssl_csr_new(['commonName' => $name], $key);
        $certificate = openssl_csr_sign($request, null, $key, 1);
        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $private);
        $path = tempnam(sys_get_temp_dir(), 'crab-verified-cert-');
        file_put_contents($path, $pem . $private);
        chmod($path, 0o600);
        try {
            ScriptedServer::run(static function (ScriptedServer $server) use ($path, $accepted): void {
                $server->greeting('mysql_native_password', 0x800);
                $request = $server->receive();
                self::assertSame(32, strlen($request));
                self::assertNotSame(0, unpack('V', $request)[1] & 0x800);
                if (!$accepted) {
                    $authenticated = false;
                    try {
                        $server->encrypt($path);
                        $server->receive();
                        $authenticated = true;
                    } catch (\RuntimeException) {
                        // The client rejects TLS and closes before credentials.
                    }
                    self::assertFalse($authenticated);
                    return;
                }
                $server->encrypt($path);
                $server->receive();
                $server->ok();
                $server->prepare();
                $server->sequence = 0;
                $server->receive();
                $server->ok();
            }, static function (string $dsn) use ($path, $trusted, $accepted): void {
                $ssl = $trusted ? ['cafile' => $path] : true;
                $connection = new Connection(static fn () => null, $dsn, 'user', 'secret', ['ssl' => $ssl]);
                try {
                    if ($accepted) {
                        self::assertSame(0, $connection->execute('select 1'));
                    } else {
                        try {
                            $connection->execute('select 1');
                            self::fail('TLS verification accepted an untrusted or mismatched certificate.');
                        } catch (DatabaseException $error) {
                            self::assertStringContainsString('TLS negotiation failed', $error->getMessage());
                            self::assertStringNotContainsString('secret', $error->getMessage());
                        }
                    }
                } finally {
                    $connection->close();
                }
            });
        } finally {
            unlink($path);
        }
    }

    public function testAClosedOrSilentServerCannotLeaveTheHandshakeWaiting(): void
    {
        foreach ([false, true] as $silent) {
            ScriptedServer::run(static function (ScriptedServer $server) use ($silent): void {
                if ($silent) {
                    self::assertTrue($server->eof());
                    return;
                }
                $server->greeting('caching_sha2_password');
                $server->receive();
                $server->close();
            }, static function (string $dsn): void {
                $connection = new Connection(static fn () => null, $dsn, timeout: 0.05);
                $start = hrtime(true);
                try {
                    $connection->execute('select 1');
                    self::fail('The unfinished handshake succeeded.');
                } catch (DatabaseException) {
                    self::assertLessThan(0.2, (hrtime(true) - $start) / 1e9);
                } finally {
                    $connection->close();
                }
            });
        }
    }

    public function testCancellingAPartialResultClosesTheSocketForItsPeer(): void
    {
        $token = new Cancellation();
        ScriptedServer::run(static function (ScriptedServer $server) use ($token): void {
            $server->greeting('mysql_native_password');
            $server->receive();
            $server->ok();
            $server->prepare(columns: 1);
            $server->sequence = 0;
            $server->receive();
            $server->send("\x01");
            $server->send(ScriptedServer::column('n'));
            $server->send("\xfe\0\0\x02\0");
            $server->send("\0\0" . pack('V', 42));
            // Start cancellation after the partial result, not during authentication
            // or prepare, whose TCP round trips can take longer on another kernel.
            \Crab\Engine\EventLoop::current()->delay(0.03, static fn () => $token->cancel());
            self::assertTrue($server->eof());
        }, static function (string $dsn, Worker $worker) use ($token): void {
            $connection = new Connection(static fn () => null, $dsn, cancellation: $token);
            try {
                $connection->select('select 42 as n');
                self::fail('A result without its terminator completed.');
            } catch (CancelledException) {
                self::assertFalse($connection->inTransaction());
            } finally {
                $connection->close();
            }
        });
    }
}
