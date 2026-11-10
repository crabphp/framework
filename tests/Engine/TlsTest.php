<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\Connector;
use Crab\Engine\Network\Listener;
use Crab\Engine\Network\WriteResult;
use Crab\Engine\Process\Subprocess;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;
use Crab\Engine\Tests\Support\TestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;

final class TlsTest extends TestCase
{
    private string $certificate;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $request = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $private);
        $this->certificate = tempnam(sys_get_temp_dir(), 'crab-tls-');
        file_put_contents($this->certificate, $pem . $private);
        chmod($this->certificate, 0o600);
        $this->deferCleanup(fn() => unlink($this->certificate));
    }

    public static function readSizes(): array
    {
        return [[65_536], [1024]];
    }

    #[DataProvider('readSizes')]
    public function testMegabytesInOneBurstArriveWholeInBothDirections(int $readSize): void
    {
        [$loop, $server, $client] = $this->pair($readSize);
        $payload = str_repeat("binary\0\xff0123456789", 196_608);
        $received = ['', ''];
        foreach ([$server, $client] as $i => $connection) {
            $connection->onData(static function (Connection $connection, string $bytes) use (
                &$received,
                $i,
                $payload,
                $loop,
            ): void {
                if ($received[$i] === '') {
                    $connection->pauseRead();
                    $loop->defer($connection->resumeRead(...));
                }
                $received[$i] .= $bytes;
                if (strlen($received[0]) === strlen($payload) && strlen($received[1]) === strlen($payload)) {
                    $loop->stop();
                }
            });
        }
        $ready = [];
        $server->startTls(static function (Connection $connection) use ($payload, &$ready): void {
            $ready[] = $connection->isEncrypted();
            $connection->tryWrite($payload);
        }, ['local_cert' => $this->certificate]);
        $client->startTls(
            static function (Connection $connection) use ($payload, &$ready): void {
                $ready[] = $connection->isEncrypted();
                $connection->tryWrite($payload);
            },
            ['verify_peer' => false, 'verify_peer_name' => false],
            server: false,
        );
        $deadline = $loop->delay(10, function () use (&$received, &$ready, $server, $client): void {
            throw new RuntimeException(
                'TLS burst stalled: '
                    . json_encode([
                        'received' => array_map(strlen(...), $received),
                        'ready' => $ready,
                        'queued' => [$server->bufferedBytes(), $client->bufferedBytes()],
                        'closed' => [$server->isClosed(), $client->isClosed()],
                        'reports' => $this->reports,
                    ], JSON_THROW_ON_ERROR),
            );
        });
        $loop->run();
        $loop->cancel($deadline);
        self::assertSame([true, true], $ready);
        self::assertSame([$payload, $payload], $received);
        self::assertSame([], $this->reports);
        $server->close();
        $client->close();
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testImplicitTlsListenerAndVerifiedConnector(): void
    {
        $loop = new StreamSelectEventLoop();
        $socket = Listener::bind('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        $admitted = 0;
        $ready = [];
        $accepted = [];
        $this->deferCleanup(static function () use (&$accepted): void {
            foreach ($accepted as $connection) {
                $connection->close();
            }
        });
        $listener = new Listener(
            $loop,
            $socket,
            static function (Connection $connection) use (&$ready, &$accepted): void {
                $accepted[] = $connection;
                $ready[] = [$connection->isEncrypted(), $connection->remoteAddress];
                $connection->onData(static function (Connection $connection, string $bytes): void {
                    $connection->tryWrite($bytes);
                });
            },
            tls: ['local_cert' => $this->certificate],
            onAccept: static function (Connection $connection) use (&$admitted): void {
                ++$admitted;
            },
        );
        $this->deferCleanup($listener->close(...));
        $listener->attach();
        $task = Task::spawn($loop, function () use ($loop, $port): bool {
            $connection = new Connector()->connect(
                $loop,
                '127.0.0.1',
                $port,
                (hrtime(true) / 1e9) + 3,
                tlsHost: 'localhost',
                tls: ['cafile' => $this->certificate],
            );
            $encrypted = $connection->isEncrypted();
            Async::await($loop, static function (\Closure $resume) use ($connection): \Closure {
                $connection->onData(static function () use ($resume): void {
                    $resume();
                });
                $connection->tryWrite('hello');
                return static function (): void {};
            });
            $connection->close();
            $loop->stop();
            return $encrypted;
        });
        $deadline = $loop->delay(5, static fn() => throw new RuntimeException('Implicit TLS stalled.'));
        $loop->run();
        $loop->cancel($deadline);
        self::assertTrue($task->result());
        self::assertSame(1, $admitted);
        self::assertCount(1, $ready);
        self::assertTrue($ready[0][0]);
        self::assertStringStartsWith('127.0.0.1:', $ready[0][1]);
        self::assertSame([], $this->reports);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDefaultSslContextCannotAlterEngineHandshakes(): void
    {
        stream_context_set_default(['ssl' => [
            'local_cert' => '/missing/default-certificate.pem',
            'cafile' => '/missing/default-ca.pem',
            'ciphers' => 'NO-SUCH-CIPHER',
            'peer_fingerprint' => 'bad-fingerprint',
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]]);
        [$loop, $server, $client] = $this->pair();
        $ready = [];
        $server->startTls(static function (Connection $connection) use (&$ready): void {
            $ready[] = $connection->isEncrypted();
        }, ['local_cert' => $this->certificate]);
        $client->startTls(
            static function (Connection $connection) use (&$ready, $loop): void {
                $ready[] = $connection->isEncrypted();
                $connection->onData($loop->stop(...));
                $connection->tryWrite('ping');
            },
            ['peer_name' => 'localhost', 'cafile' => $this->certificate],
            server: false,
        );
        $server->onData(static function (Connection $connection, string $bytes): void {
            $connection->tryWrite($bytes);
        });
        $deadline = $loop->delay(5, static fn() => throw new RuntimeException('Default context changed TLS.'));
        $loop->run();
        $loop->cancel($deadline);
        self::assertSame([true, true], $ready);
        $server->close();
        $client->close();
        $this->testImplicitTlsListenerAndVerifiedConnector();
        [$loop, $server, $client] = $this->pair(defaultContext: true);
        $error = null;
        try {
            $client->startTls(static function (): void {}, server: false);
        } catch (LogicException $failure) {
            $error = $failure;
        }
        self::assertInstanceOf(LogicException::class, $error);
        self::assertStringContainsString('own empty context', $error->getMessage());
        self::assertFalse($client->isClosed());
    }

    public function testPendingHandshakeOccupiesWorkerAdmission(): void
    {
        $socket = Listener::bind('tcp://127.0.0.1:0');
        $address = 'tcp://' . stream_socket_get_name($socket, false);
        $peer = stream_socket_client($address);
        $this->deferCleanup(static fn() => fclose($peer));
        $runtime = new Runtime();
        $seen = null;
        $dispatched = false;
        $runtime
            ->group(maxConnections: 1)
            ->listen(
                $address,
                static function () use (&$dispatched): void {
                    $dispatched = true;
                },
                socket: $socket,
                tls: ['local_cert' => $this->certificate],
                handshakeTimeout: 3,
            )
            ->onStart(static function (Worker $worker) use ($runtime, &$seen): void {
                $worker->loop->repeat(0.001, static function () use ($worker, $runtime, &$seen): void {
                    if ($worker->stats()['connections'] === 1) {
                        $seen = $worker->stats()['connections'];
                        $runtime->stop();
                    }
                });
                $worker->loop->delay(5, static fn() => throw new RuntimeException('TLS admission stalled.'));
            });
        $runtime->run(singleProcess: true);
        self::assertSame(1, $seen);
        self::assertFalse($dispatched);
        self::assertSame([], $this->reports);
    }

    public function testClientUpgradeVerifiesPeerByDefault(): void
    {
        [$loop, $server, $client] = $this->pair();
        $ready = false;
        $server->startTls(static function (): void {}, ['local_cert' => $this->certificate]);
        $client->onClose($loop->stop(...));
        $client->startTls(
            static function () use (&$ready): void {
                $ready = true;
            },
            ['peer_name' => 'wrong.test', 'cafile' => $this->certificate],
            server: false,
        );
        $deadline = $loop->delay(5, static fn() => throw new RuntimeException('Verification did not finish.'));
        $loop->run();
        $loop->cancel($deadline);
        self::assertTrue($client->isClosed());
        self::assertFalse($ready);
        self::assertSame([], $this->reports);
    }

    public function testStartTlsRejectsUnreadPlaintextAndQueuedOutput(): void
    {
        foreach (['owner', 'socket', 'output'] as $kind) {
            [$loop, $server, $client, $peer] = $this->pair();
            $called = false;
            $ready = static function () use (&$called): void {
                $called = true;
            };
            if ($kind === 'socket') {
                fwrite($peer, "MAIL FROM:<injected@test>\r\n");
            }
            if ($kind === 'output') {
                for ($i = 0; $i < 100 && $server->bufferedBytes() === 0; ++$i) {
                    $server->tryWrite(str_repeat('x', 4_194_304));
                }
                $error = null;
                try {
                    $server->startTls($ready);
                } catch (LogicException $failure) {
                    $error = $failure;
                }
                self::assertInstanceOf(LogicException::class, $error);
                self::assertFalse($server->isClosed());
            } else {
                $server->onClose($loop->stop(...));
                $server->startTls(
                    $ready,
                    ['local_cert' => $this->certificate],
                    unread: $kind === 'owner' ? 'injected' : '',
                );
                if (!$server->isClosed()) {
                    $deadline = $loop->delay(
                        2,
                        static fn() => throw new RuntimeException('Plaintext was not rejected by TLS.'),
                    );
                    $loop->run();
                    $loop->cancel($deadline);
                }
                self::assertTrue($server->isClosed());
                self::assertSame([], $this->reports);
            }
            self::assertFalse($called);
            $server->close();
            $client->close();
            self::assertSame([], $loop->getIdentifiers());
        }
    }

    public function testClientHelloMayArriveAfterReplyBeforeServerUpgrade(): void
    {
        [$loop, $server, $client] = $this->pair();
        $helloPending = null;
        $received = '';
        $server->onData(static function (Connection $connection, string $bytes): void {
            $connection->pauseRead();
            $connection->tryWrite("220 Begin TLS\r\n");
        });
        $client->onData(function (Connection $connection, string $bytes) use (
            $server,
            &$helloPending,
            &$received,
            $loop,
        ): void {
            if (!$connection->isEncrypted()) {
                $connection->startTls(
                    static function (Connection $connection): void {
                        $connection->tryWrite('encrypted');
                    },
                    ['verify_peer' => false, 'verify_peer_name' => false],
                    server: false,
                );
                // Emitting ClientHello does not mean it has arrived yet. Wait
                // until it is readable while the server is still paused and has
                // not entered its handshake: this is the barrier.
                $stream = new \ReflectionProperty($server, 'stream')->getValue($server);
                $loop->onReadable($stream, function (string $watcher) use (
                    $loop,
                    $stream,
                    $server,
                    &$helloPending,
                ): void {
                    $loop->cancel($watcher);
                    $helloPending = stream_socket_recvfrom($stream, 1, STREAM_PEEK);
                    $server->startTls(static function (Connection $connection): void {
                        $connection->onData(static function (Connection $connection, string $bytes): void {
                            $connection->tryWrite($bytes);
                        });
                        $connection->resumeRead();
                    }, ['local_cert' => $this->certificate]);
                });
                return;
            }
            $received .= $bytes;
            $loop->stop();
        });
        $client->tryWrite("STARTTLS\r\n");
        $deadline = $loop->delay(3, static fn() => throw new RuntimeException('Queued ClientHello was rejected.'));
        $loop->run();
        $loop->cancel($deadline);
        self::assertSame("\x16", $helloPending);
        self::assertSame('encrypted', $received);
        self::assertSame([], $this->reports);
        $server->close();
        $client->close();
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testTlsWriteShutdownGivesIndependentPeerACleanEof(): void
    {
        $loop = new StreamSelectEventLoop();
        $socket = Listener::bind('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        $listener = new Listener(
            $loop,
            $socket,
            static function (Connection $connection): void {
                $connection->tryWrite(str_repeat('x', 3_145_728));
                $connection->shutdownWrite();
            },
            maxWriteBufferSize: 4_194_304,
            tls: ['local_cert' => $this->certificate],
        );
        $this->deferCleanup($listener->close(...));
        $listener->attach();
        $python = <<<'PYTHON'
            import socket, ssl, sys, json
            context = ssl._create_unverified_context()
            with socket.create_connection(('127.0.0.1', int(sys.argv[1])), timeout=5) as tcp:
                with context.wrap_socket(tcp, server_hostname='localhost', suppress_ragged_eofs=False) as peer:
                    count = 0
                    while True:
                        chunk = peer.recv(4096)
                        if not chunk:
                            break
                        count += len(chunk)
                    print(json.dumps({'bytes': count, 'clean_eof': True}))
            PYTHON;
        $task = Task::spawn($loop, static function () use ($loop, $python, $port): mixed {
            $result = Subprocess::run(['python3', '-c', $python, (string) $port], timeout: 8);
            $loop->stop();
            return $result;
        });
        EventLoop::install($loop);
        $loop->run();
        $result = $task->result();
        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame(
            ['bytes' => 3_145_728, 'clean_eof' => true],
            json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testBadCertificateFailsListenerStartupAndLateRenewalIsReported(): void
    {
        $loop = new StreamSelectEventLoop();
        $socket = Listener::bind('tcp://127.0.0.1:0');
        $error = null;
        try {
            new Listener($loop, $socket, static function (): void {}, tls: [
                'local_cert' => '/missing/certificate.pem',
            ]);
        } catch (RuntimeException $failure) {
            $error = $failure;
        } finally {
            fclose($socket);
        }
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertStringContainsString('certificate', $error->getMessage());
        [$loop, $server, $client] = $this->pair();
        $server->startTls(static function (): void {}, ['local_cert' => '/missing/renewed-certificate.pem']);
        self::assertTrue($server->isClosed());
        self::assertCount(1, $this->reports);
        self::assertStringContainsString('renewed-certificate.pem', $this->reports[0]);
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testInvalidCertificateAndMismatchedPrivateKeyRetainOpenSslReason(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'crab-bad-cert-');
        $keyPath = tempnam(sys_get_temp_dir(), 'crab-bad-key-');
        $this->deferCleanup(static fn() => unlink($bad));
        $this->deferCleanup(static fn() => unlink($keyPath));
        file_put_contents($bad, 'not a certificate');
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        file_put_contents($keyPath, $pem);
        foreach ([['local_cert' => $bad], ['local_cert' => $this->certificate, 'local_pk' => $keyPath]] as $options) {
            $socket = Listener::bind('tcp://127.0.0.1:0');
            $error = null;
            try {
                new Listener(new StreamSelectEventLoop(), $socket, static function (): void {}, tls: $options);
            } catch (RuntimeException $failure) {
                $error = $failure;
            } finally {
                fclose($socket);
            }
            self::assertInstanceOf(RuntimeException::class, $error);
            self::assertStringContainsString('error:', $error->getMessage());
        }
    }

    public function testFailedRenewalAfterListenerStartupIsReportedAndNotDispatched(): void
    {
        $loop = new StreamSelectEventLoop();
        $socket = Listener::bind('tcp://127.0.0.1:0');
        $address = 'tcp://' . stream_socket_get_name($socket, false);
        $dispatched = false;
        $listener = new Listener(
            $loop,
            $socket,
            static function () use (&$dispatched): void {
                $dispatched = true;
            },
            tls: ['local_cert' => $this->certificate],
        );
        $this->deferCleanup($listener->close(...));
        file_put_contents($this->certificate, 'broken renewal');
        $peer = stream_socket_client($address, context: stream_context_create());
        $client = new Connection($loop, $peer);
        $this->deferCleanup($client->close(...));
        $client->onData(static function (): void {});
        $client->onClose($loop->stop(...));
        $listener->attach();
        $deadline = $loop->delay(3, static fn() => throw new RuntimeException('Bad renewal connection stayed open.'));
        $loop->run();
        $loop->cancel($deadline);
        self::assertFalse($dispatched);
        self::assertCount(1, $this->reports);
        self::assertStringContainsString('Cannot parse TLS certificate', $this->reports[0]);
    }

    public function testInvalidCipherReportsOpenSslReasonWithoutDispatching(): void
    {
        $loop = new StreamSelectEventLoop();
        $socket = Listener::bind('tcp://127.0.0.1:0');
        $address = 'tcp://' . stream_socket_get_name($socket, false);
        $dispatched = false;
        $listener = new Listener(
            $loop,
            $socket,
            static function () use (&$dispatched): void {
                $dispatched = true;
            },
            tls: ['local_cert' => $this->certificate, 'ciphers' => 'NO-SUCH-CIPHER'],
        );
        $this->deferCleanup($listener->close(...));
        $peer = stream_socket_client($address, context: stream_context_create());
        $client = new Connection($loop, $peer);
        $this->deferCleanup($client->close(...));
        $client->onData(static function (): void {});
        $client->onClose($loop->stop(...));
        $listener->attach();
        $deadline = $loop->delay(
            3,
            static fn() => throw new RuntimeException('Invalid cipher connection stayed open.'),
        );
        $loop->run();
        $loop->cancel($deadline);
        self::assertFalse($dispatched);
        self::assertCount(1, $this->reports);
        self::assertStringContainsString('TLS local handshake setup failed', $this->reports[0]);
        self::assertStringContainsString('no cipher match', $this->reports[0]);
        self::assertFalse(openssl_error_string());
    }

    public function testSilentHandshakeTimesOutWithoutDispatchingPlaintext(): void
    {
        [$loop, $server, $client] = $this->pair();
        $dispatched = false;
        $server->onData(static function () use (&$dispatched): void {
            $dispatched = true;
        });
        $server->onClose($loop->stop(...));
        $server->startTls(
            static function () use (&$dispatched): void {
                $dispatched = true;
            },
            ['local_cert' => $this->certificate],
            timeout: 0.05,
        );
        self::assertSame(WriteResult::Closed, $server->tryWrite('plaintext'));
        $loop->run();
        self::assertTrue($server->isClosed());
        self::assertFalse($dispatched);
        self::assertSame([], $loop->getIdentifiers());
    }

    /**
     * @return array{StreamSelectEventLoop, Connection, Connection, resource}
     */
    private function pair(int $readSize = 65_536, bool $defaultContext = false): array
    {
        $loop = new StreamSelectEventLoop();
        $listener = Listener::bind('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($listener, false);
        $peer = stream_socket_client('tcp://' . $address, context: $defaultContext ? null : stream_context_create());
        $socket = stream_socket_accept($listener, 1, $remote);
        fclose($listener);
        $server = new Connection($loop, $socket, $remote, 4_194_304, readSize: $readSize);
        $client = new Connection($loop, $peer, $address, 4_194_304, readSize: $readSize);
        $this->deferCleanup($server->close(...));
        $this->deferCleanup($client->close(...));
        return [$loop, $server, $client, $peer];
    }
}
