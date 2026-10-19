<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\Network\DnsResolver;
use Crab\Engine\Suspension;
use Crab\Http\Request;
use Crab\HttpClient\Client;
use Crab\HttpClient\Connection;
use Crab\HttpClient\Network;
use Crab\HttpClient\NetworkException;
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
