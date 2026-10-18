<?php

declare(strict_types=1);

namespace Crab\Tests\Database\Mysql;

use Crab\Database\DatabaseException;
use Crab\Database\Mysql\Authentication;
use Crab\Database\Mysql\Connection;
use Crab\Database\Mysql\Settings;
use Crab\Database\Mysql\Stream;
use Crab\Engine\EventLoop;
use Crab\Engine\Network\DnsResolver;
use Crab\Engine\Process\Worker;
use Crab\Testing\WorkerHarness;
use PHPUnit\Framework\TestCase;

/**
 * Uses fresh accounts on the supplied server so its authentication cache cannot
 * hide a full exchange. The packet relay observes real server decisions.
 */
final class RealAuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('CRAB_TEST_MYSQL_DSN') === false) {
            self::markTestSkipped('Set CRAB_TEST_MYSQL_DSN to an isolated server where test accounts may be created.');
        }
    }

    public function testAFreshCachingAccountUsesTheRealServersRsaKey(): void
    {
        $this->usingAccount(function (\PDO $admin, string $user, #[\SensitiveParameter] string $password): void {
            $this->observeLogin($user, $password, false);
        });
    }

    public function testAWarmedCachingAccountUsesTheRealServersFastPath(): void
    {
        $this->usingAccount(function (\PDO $admin, string $user, #[\SensitiveParameter] string $password): void {
            $this->query($user, $password);
            $this->observeLogin($user, $password, true);
        });
    }

    public function testAFreshCachingAccountAuthenticatesOverVerifiedTls(): void
    {
        $ssl = $this->tlsOptions();
        $this->usingAccount(function (\PDO $admin, string $user, #[\SensitiveParameter] string $password) use (
            $ssl,
        ): void {
            $this->query($user, $password, $ssl, true);
        });
    }

    public function testTlsRefusesTheRealServersCertificateWithoutItsCa(): void
    {
        $ssl = $this->tlsOptions();
        unset($ssl['cafile']);
        $this->usingAccount(function (\PDO $admin, string $user, #[\SensitiveParameter] string $password) use (
            $ssl,
        ): void {
            try {
                $this->query($user, $password, $ssl);
                self::fail('An untrusted server certificate was accepted.');
            } catch (DatabaseException $error) {
                self::assertStringContainsString('TLS negotiation failed', $error->getMessage());
            }
        });
    }

    public function testNativePasswordLogsInOrReportsTheServersDisabledPlugin(): void
    {
        $this->usingAccount(function (\PDO $admin, string $user, #[\SensitiveParameter] string $password): void {
            $alter = $admin->prepare("ALTER USER ?@'%' IDENTIFIED WITH mysql_native_password BY ?");
            try {
                $alter->execute([$user, $password]);
            } catch (\PDOException $error) {
                self::assertSame(1524, $error->errorInfo[1]);
                // Only this disposable account is changed: a disabled plugin
                // cannot provision it normally, but must also refuse its login.
                $hash = '*' . strtoupper(sha1(sha1($password, true)));
                $update = $admin->prepare(
                    "UPDATE mysql.user SET plugin='mysql_native_password', authentication_string=? WHERE User=? AND Host='%'",
                );
                $update->execute([$hash, $user]);
                $admin->exec('FLUSH PRIVILEGES');
                try {
                    $this->query($user, $password);
                    self::fail('A disabled native-password plugin accepted a login.');
                } catch (DatabaseException $failure) {
                    self::assertSame(1524, $failure->code());
                    self::assertSame('HY000', $failure->sqlState());
                    self::assertStringContainsString(
                        "Plugin 'mysql_native_password' is not loaded",
                        $failure->getMessage(),
                    );
                }
                return;
            }
            $this->query($user, $password);
        });
    }

    /**
     * Verification remains enabled; generated image certificates need their
     * actual certificate name rather than the host's published loopback IP.
     *
     * @return array<string, mixed>
     */
    private function tlsOptions(): array
    {
        $ca = getenv('CRAB_TEST_MYSQL_CA');
        $name = getenv('CRAB_TEST_MYSQL_TLS_PEER_NAME');
        if ($ca === false || $name === false) {
            self::markTestSkipped(
                'Set CRAB_TEST_MYSQL_CA and CRAB_TEST_MYSQL_TLS_PEER_NAME to verify the real server certificate.',
            );
        }
        return ['cafile' => $ca, 'peer_name' => $name];
    }

    private function usingAccount(\Closure $body): void
    {
        $admin = new \PDO(
            (string) getenv('CRAB_TEST_MYSQL_DSN'),
            getenv('CRAB_TEST_MYSQL_USER') ?: 'root',
            getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $user = 'crab_auth_' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(16));
        $create = $admin->prepare("CREATE USER ?@'%' IDENTIFIED WITH caching_sha2_password BY ?");
        $create->execute([$user, $password]);
        try {
            $grant = $admin->prepare("GRANT SELECT ON *.* TO ?@'%'");
            $grant->execute([$user]);
            $body($admin, $user, $password);
        } finally {
            $drop = $admin->prepare("DROP USER ?@'%'");
            $drop->execute([$user]);
        }
    }

    private function query(
        string $user,
        #[\SensitiveParameter]
        string $password,
        ?array $ssl = null,
        bool $encrypted = false,
    ): void {
        WorkerHarness::run([], static function (Worker $worker) use ($user, $password, $ssl, $encrypted): void {
            $options = $ssl === null ? [] : ['ssl' => $ssl];
            $connection = new Connection(
                static fn() => null,
                (string) getenv('CRAB_TEST_MYSQL_DSN'),
                $user,
                $password,
                $options,
                worker: $worker,
            );
            try {
                self::assertSame(['value' => 42], $connection->first('SELECT :number AS value', ['number' => 42]));
                $cipher = $connection->first("SHOW SESSION STATUS LIKE 'Ssl_cipher'")['Value'];
                if ($encrypted) {
                    self::assertNotSame('', $cipher);
                } else {
                    self::assertSame('', $cipher);
                }
            } finally {
                $connection->close();
            }
        });
    }

    /**
     * Relays only login packets, unchanged, to the DSN's host and port.
     * No scripted success can replace the real server's final OK packet.
     */
    private function observeLogin(string $user, #[\SensitiveParameter] string $password, bool $fast): void
    {
        ScriptedServer::run(static function (ScriptedServer $client) use ($fast): void {
            $settings = new Settings((string) getenv('CRAB_TEST_MYSQL_DSN'));
            $address = $settings->socket !== null
                ? 'unix://' . $settings->socket
                : "tcp://{$settings->host}:{$settings->port}";
            $socket = stream_socket_client($address, $number, $reason, 5);
            if ($socket === false) {
                throw new \RuntimeException('Cannot connect the authentication relay: ' . $reason);
            }
            $server = new ScriptedServer($socket, EventLoop::current());
            try {
                $greeting = $server->receive();
                self::assertSame('caching_sha2_password', Authentication::greeting($greeting)['plugin']);
                $client->send($greeting);
                $server->send($client->receive());
                $decision = $server->receive();
                self::assertSame($fast ? "\x01\x03" : "\x01\x04", $decision);
                $client->send($decision);
                if (!$fast) {
                    $request = $client->receive();
                    self::assertSame("\x02", $request);
                    $server->send($request);
                    $key = $server->receive();
                    self::assertStringContainsString('PUBLIC KEY', $key);
                    $client->send($key);
                    $response = $client->receive();
                    self::assertSame(256, strlen($response));
                    $server->send($response);
                }
                $ok = $server->receive();
                self::assertSame("\0", $ok[0]);
                $client->send($ok);
            } finally {
                $server->close();
            }
        }, static function (string $dsn, Worker $worker) use ($user, $password): void {
            $settings = new Settings($dsn);
            $stream = new Stream($worker->loop);
            $deadline = (hrtime(true) / 1e9) + 5;
            try {
                $stream->open($settings, new DnsResolver(fallback: false), $deadline, null);
                Authentication::login($stream, $settings, $user, $password, $deadline, null);
            } finally {
                $stream->close();
            }
        });
    }
}
