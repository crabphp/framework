<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\Network\DnsResolver;
use Crab\Engine\Suspension;
use Crab\Http\Request;
use Crab\HttpClient\Client;
use Crab\HttpClient\Connection;
use Crab\HttpClient\Hpack;
use Crab\HttpClient\Http2Session;
use Crab\HttpClient\Network;
use Crab\HttpClient\NetworkException;
use Crab\HttpClient\TimedOut;
use Crab\Tests\Support\Wait;
use PHPUnit\Framework\TestCase;

final class NetworkTest extends TestCase
{
    private EventLoop $loop;
    private Network $network;
    private Client $client;
    private string $hosts;
    /**
     * Servers belong to this test and are closed even after an assertion fails.
     *
     * @var list<TestServer>
     */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->loop = EventLoop::install(RevoltEventLoop::create());
        $this->hosts = sys_get_temp_dir() . '/crab-network-hosts-' . bin2hex(random_bytes(6));
        file_put_contents($this->hosts, '');
        $this->network = new Network(resolver: new DnsResolver([], fallback: false, hostsFile: $this->hosts));
        $this->client = new Client(transport: $this->network);
    }

    protected function tearDown(): void
    {
        $this->client->close();
        foreach ($this->servers as $server) {
            $server->close();
        }
        @unlink($this->hosts);
        $this->loop->run();
        parent::tearDown();
    }

    public function testFailedOriginsLeaveNoEmptyPoolsOrWaiterLists(): void
    {
        for ($i = 0; $i < 300; ++$i) {
            $this->failToResolve("missing-{$i}.invalid");
        }

        self::assertSame([], $this->kept('pools'));
        self::assertSame([], $this->kept('waiting'));
        $this->client->close();
        self::assertSame([], $this->kept('waiting'));
    }

    public function testAnExpiredCallDoesNotKeepThePoolItNeverBorrowed(): void
    {
        $request = new Request('GET', 'http://late.invalid/');
        try {
            $this->network->exchange($request, (hrtime(true) / 1e9) - 1, 1, null);
            self::fail('A call past its deadline was sent.');
        } catch (NetworkException $error) {
            self::assertTrue($error->unsent);
        }
        self::assertSame([], $this->kept('pools'));
        self::assertSame([], $this->kept('waiting'));
    }

    public function testAClosingResponseReleasesItsEmptyPool(): void
    {
        $this->servers[] = $server = new TestServer(static fn(): string => TestServer::reply(200, 'done', [
            'Connection' => 'close',
        ]));
        $response = $this->client->get($server->url('/'));

        self::assertSame('done', (string) $response->getBody());
        self::assertSame([], $this->kept('pools'));
        self::assertSame([], $this->kept('waiting'));
    }

    public function testClosingAnHttp2SessionReleasesThePoolWithoutAnotherRequest(): void
    {
        // A socket pair isolates session ownership from TLS negotiation. The
        // session can close after exchange() has already returned its response.
        [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($socket, false);
        stream_set_blocking($peer, false);
        $class = new \ReflectionClass(Connection::class);
        $connection = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($connection, $this->loop, $socket);
        $origin = 'https://session.test:443';
        $pool = new \ReflectionMethod($this->network, 'pool')->invoke($this->network, 'https', 'session.test', 443);
        $slot = $pool->acquire();
        $slot->connection = $connection;
        try {
            $session = new \ReflectionMethod($this->network, 'session')->invoke(
                $this->network,
                $origin,
                $pool,
                $slot,
                (hrtime(true) / 1e9) + 5,
            );
            self::assertStringStartsWith("PRI * HTTP/2.0\r\n", fread($peer, 8192));
            self::assertCount(1, $this->kept('sessions'));
            $session->close();
            self::assertSame([], $this->kept('sessions'));
            self::assertSame([], $this->kept('pools'));
        } finally {
            $connection->close();
            fclose($peer);
        }
    }

    public function testCleaningFailedOriginsPreservesAnIdleReusableConnection(): void
    {
        $this->servers[] = $server = new TestServer(static fn(): string => TestServer::reply(200, 'kept'));
        self::assertSame(200, $this->client->get($server->url('/'))->getStatusCode());
        $pools = $this->kept('pools');
        for ($i = 0; $i < 20; ++$i) {
            $this->failToResolve("missing-{$i}.invalid");
        }

        self::assertSame($pools, $this->kept('pools'));
        self::assertSame('kept', (string) $this->client->get($server->url('/again'))->getBody());
        self::assertSame(1, $server->connections);
        self::assertSame([], $this->kept('waiting'));
    }

    public function testARetryAfterHttp2ClosesKeepsItsReplacementConnectionPooled(): void
    {
        $this->servers[] = $server = new TestServer(static fn(): string => TestServer::reply(200, 'retried'));
        [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($socket, false);
        stream_set_blocking($peer, false);
        $class = new \ReflectionClass(Connection::class);
        $connection = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($connection, $this->loop, $socket);
        $origin = "http://127.0.0.1:{$server->port}";
        $pool = new \ReflectionMethod($this->network, 'pool')->invoke(
            $this->network,
            'http',
            '127.0.0.1',
            $server->port,
        );
        $slot = $pool->acquire();
        $slot->connection = $connection;
        try {
            new \ReflectionMethod($this->network, 'session')->invoke(
                $this->network,
                $origin,
                $pool,
                $slot,
                (hrtime(true) / 1e9) + 5,
            );
            // GOAWAY says no stream was processed. The in-flight exchange may
            // retry, after its HTTP/2 close callback has removed the empty pool.
            fwrite($peer, "\x00\x00\x08\x07\x00\x00\x00\x00\x00" . pack('NN', 0, 0));
            self::assertSame('retried', (string) $this->client->get($server->url('/first'))->getBody());
            self::assertCount(1, $this->kept('pools'));
            self::assertSame('retried', (string) $this->client->get($server->url('/second'))->getBody());
            self::assertSame(1, $server->connections, 'The retried request must retain its reusable connection.');
            self::assertSame([], $this->kept('sessions'));
            self::assertSame([], $this->kept('waiting'));
        } finally {
            $connection->close();
            fclose($peer);
        }
    }

    public function testCleaningFailedOriginsPreservesAnotherOriginsInFlightRequest(): void
    {
        $ready = $this->loop->getSuspension();
        $release = null;
        $this->servers[] =
            $server = new TestServer(function () use ($ready, &$release): string {
                $release = $this->loop->getSuspension();
                $ready->resume();
                $release->suspend();
                return TestServer::reply(200, 'finished');
            });
        $task = Task::spawn($this->loop, fn() => $this->client->get($server->url('/held')));
        $this->await($ready);
        try {
            $pools = $this->kept('pools');
            for ($i = 0; $i < 20; ++$i) {
                $this->failToResolve("missing-{$i}.invalid");
            }
            self::assertSame($pools, $this->kept('pools'));
            self::assertSame([], $this->kept('waiting'));
        } finally {
            $release->resume();
            $response = $task->await();
        }
        self::assertSame('finished', (string) $response->getBody());
        self::assertSame(1, $server->connections);
    }

    private function failToResolve(string $host): void
    {
        try {
            $this->client->get("http://{$host}/");
            self::fail('A resolver without name servers or fallback found an unknown name.');
        } catch (NetworkException $error) {
            self::assertTrue($error->unsent);
            self::assertStringContainsString("no address was found for the name {$host}", $error->getMessage());
        }
    }

    public function testCancelledHttp2WritersLeaveBeforeTheBlockedUploadFinishes(): void
    {
        $this->withBlockedHttp2Writer(function (Http2Session $session): void {
            for ($wave = 0; $wave < 3; ++$wave) {
                for ($index = 0; $index < 5; ++$index) {
                    $task = Task::spawn($this->loop, function (Cancellation $cancellation): ?\Throwable {
                        try {
                            $this->client->request('GET', 'https://writer.test/abandoned', cancellation: $cancellation);
                        } catch (\Throwable $error) {
                            return $error;
                        }
                        return null;
                    });
                    Wait::until(
                        $this->loop,
                        fn(): bool => count($this->state($session, 'writers')) === 1,
                        'a caller queued behind the HTTP/2 writer',
                    );
                    $task->cancel();
                    self::assertSame(
                        [],
                        $this->state($session, 'writers'),
                        'Cancellation must remove the waiter before its fiber resumes.',
                    );
                    self::assertInstanceOf(CancelledException::class, $this->awaitTask($task));
                }
            }
            self::assertCount(1, $this->state($session, 'streams'), 'Only the interested upload owns a stream.');
        });
    }

    public function testAnHttp2WriterDeadlineEndsItsOwnCallWithoutClosingTheSharedSession(): void
    {
        $this->withBlockedHttp2Writer(function (Http2Session $session): void {
            $task = Task::spawn($this->loop, function (): ?\Throwable {
                try {
                    $this->client->timeout(0.5)->get('https://writer.test/abandoned');
                } catch (\Throwable $error) {
                    return $error;
                }
                return null;
            });
            Wait::until(
                $this->loop,
                fn(): bool => count($this->state($session, 'writers')) === 1,
                'the request waiting for its HTTP/2 writer turn',
            );
            $error = $this->awaitTask($task);
            self::assertInstanceOf(NetworkException::class, $error);
            self::assertTrue($error->unsent);
            self::assertInstanceOf(TimedOut::class, $error->getPrevious());
            self::assertStringContainsString('waiting to write', $error->getMessage());
            self::assertSame([], $this->state($session, 'writers'));
            self::assertTrue($session->accepts(), 'A caller that sent nothing must not close its neighbors.');
        });
    }

    public function testCancellationAlsoEndsTheFirstHttp2PrefaceWrite(): void
    {
        [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($socket, false);
        stream_set_blocking($peer, false);
        $class = new \ReflectionClass(Connection::class);
        $connection = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($connection, $this->loop, $socket);
        $connection->protocol = 'h2';
        $pool = new \ReflectionMethod($this->network, 'pool')->invoke($this->network, 'https', 'preface.test', 443);
        $slot = $pool->acquire();
        $slot->connection = $connection;
        $pool->release($slot);
        try {
            // Fill the socket before opening the conversation, so even its
            // small preface must wait for the peer to read.
            $deadline = hrtime(true) + 5_000_000_000;
            $padding = str_repeat('x', 65_536);
            do {
                $written = fwrite($socket, $padding);
                self::assertNotFalse($written);
                self::assertLessThan($deadline, hrtime(true), 'The socket never reached backpressure.');
            } while ($written !== 0);
            $task = Task::spawn($this->loop, function (Cancellation $cancellation): ?\Throwable {
                try {
                    $this->client->request('GET', 'https://preface.test/', cancellation: $cancellation);
                } catch (\Throwable $error) {
                    return $error;
                }
                return null;
            });
            Wait::until(
                $this->loop,
                fn(): bool => (
                    isset($this->kept('sessions')['https://preface.test:443'])
                    && $this->state($connection, 'waits') !== []
                ),
                'the first HTTP/2 preface waiting for socket capacity',
            );
            $task->cancel();
            self::assertInstanceOf(CancelledException::class, $this->awaitTask($task));
            self::assertSame([], $this->kept('sessions'));
            self::assertSame([], $this->kept('pools'));
            self::assertSame([], $this->kept('waiting'));
        } finally {
            $this->client->close();
            fclose($peer);
            $this->loop->run();
        }
        self::assertSame([], $this->loop->getIdentifiers());
    }

    /**
     * Hold a real upload on socket backpressure. Other callers must finish
     * their cancellation/deadline while the peer remains paused, then the
     * upload and a fresh caller must still use this same session.
     *
     * @param \Closure(Http2Session): void $check
     */
    private function withBlockedHttp2Writer(\Closure $check): void
    {
        [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($socket, false);
        stream_set_blocking($peer, false);
        $class = new \ReflectionClass(Connection::class);
        $connection = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($connection, $this->loop, $socket);
        $pool = new \ReflectionMethod($this->network, 'pool')->invoke($this->network, 'https', 'writer.test', 443);
        $slot = $pool->acquire();
        $slot->connection = $connection;
        $session = new \ReflectionMethod($this->network, 'session')->invoke(
            $this->network,
            'https://writer.test:443',
            $pool,
            $slot,
            (hrtime(true) / 1e9) + 10,
        );
        self::assertStringStartsWith('PRI * HTTP/2.0', stream_get_contents($peer));
        $settings = self::frame(4, 0, 0, pack('nN', 4, 16_777_216)) . self::frame(8, 0, 0, pack('N', 16_777_216));
        self::assertSame(strlen($settings), fwrite($peer, $settings));
        $buffer = '';
        $headers = [];
        $bodies = [];
        $received = [];
        $hpack = new Hpack();
        $watcher = $this->loop->onReadable($peer, static function () use (
            $peer,
            $hpack,
            &$buffer,
            &$headers,
            &$bodies,
            &$received,
        ): void {
            $buffer .= fread($peer, 65_536);
            while (strlen($buffer) >= 9) {
                $length = unpack('N', "\0" . substr($buffer, 0, 3))[1];
                if (strlen($buffer) < (9 + $length)) {
                    break;
                }
                $type = ord($buffer[3]);
                $flags = ord($buffer[4]);
                $id = unpack('N', $buffer, 5)[1];
                $payload = substr($buffer, 9, $length);
                $buffer = substr($buffer, 9 + $length);
                if ($type === 1) {
                    self::assertNotSame(0, $flags & 4, 'This fixture expects a complete header block.');
                    $headers[$id] = array_column($hpack->decode($payload), 1, 0);
                    $bodies[$id] = 0;
                    $received[$headers[$id][':path']] = 0;
                }
                if ($type === 0) {
                    self::assertSame(str_repeat('b', strlen($payload)), $payload);
                    $bodies[$id] += strlen($payload);
                    $received[$headers[$id][':path']] = $bodies[$id];
                }
                if (($type === 1 || $type === 0) && ($flags & 1) !== 0) {
                    $path = $headers[$id][':path'];
                    $reply = self::frame(1, 4, $id, Hpack::encode([
                        [':status', '200'],
                        ['content-length', (string) strlen($path)],
                    ])) . self::frame(0, 1, $id, $path);
                    self::assertSame(
                        strlen($reply),
                        fwrite($peer, $reply),
                        'The HTTP/2 fixture could not send its response.',
                    );
                }
            }
        });
        $leader = null;
        try {
            self::assertSame('/warm', (string) $this->client->get('https://writer.test/warm')->getBody());
            $this->loop->disable($watcher);
            $leader = Task::spawn($this->loop, fn(Cancellation $cancellation) => $this->client->request(
                'POST',
                'https://writer.test/upload',
                body: str_repeat('b', 1_048_576),
                cancellation: $cancellation,
            ));
            Wait::until(
                $this->loop,
                fn(): bool => $this->state($session, 'writing') && $this->state($connection, 'waits') !== [],
                'the HTTP/2 upload blocked on socket capacity',
            );
            $check($session);
            self::assertFalse($leader->isComplete(), 'The peer must remain paused during the check.');
            $this->loop->enable($watcher);
            self::assertSame('/upload', (string) $this->awaitTask($leader)->getBody());
            self::assertSame('/live', (string) $this->client->get('https://writer.test/live')->getBody());
            self::assertSame(1_048_576, $received['/upload']);
            self::assertArrayNotHasKey('/abandoned', $received, 'No abandoned request may reach the peer.');
            self::assertSame($session, $this->kept('sessions')['https://writer.test:443']);
        } finally {
            $leader?->cancel();
            $this->client->close();
            $this->loop->cancel($watcher);
            fclose($peer);
            $this->loop->run();
        }
        self::assertSame([], $this->loop->getIdentifiers(), 'The fixture must release every watcher and timer.');
    }

    private static function frame(int $type, int $flags, int $stream, string $body): string
    {
        return substr(pack('N', strlen($body)), 1) . chr($type) . chr($flags) . pack('N', $stream) . $body;
    }

    private function state(object $owner, string $property): mixed
    {
        return new \ReflectionProperty($owner, $property)->getValue($owner);
    }

    private function awaitTask(Task $task): mixed
    {
        Wait::until($this->loop, $task->isComplete(...), 'the HTTP/2 caller finishing while its peer was held');
        return $task->result();
    }

    /**
     * Cache cardinality is internal state, but retaining an entry per completed
     * origin would exhaust a long-lived worker even when no request is left.
     *
     * @return array<string, mixed>
     */
    private function kept(string $property): array
    {
        return new \ReflectionProperty($this->network, $property)->getValue($this->network);
    }

    private function await(Suspension $suspension): void
    {
        $timer = $this->loop->delay(5, static fn() => $suspension->throw(
            new \RuntimeException('The local HTTP fixture did not receive the held request.'),
        ));
        try {
            $suspension->suspend();
        } finally {
            $this->loop->cancel($timer);
        }
    }
}
