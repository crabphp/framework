<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\Suspension;
use Crab\HttpClient\Client;
use Crab\HttpClient\Connection;
use Crab\HttpClient\Hpack;
use Crab\HttpClient\Http2Session;
use Crab\HttpClient\Network;
use Crab\HttpClient\NetworkException;
use PHPUnit\Framework\TestCase;

final class Http2IdleTest extends TestCase
{
    private EventLoop $loop;
    private Network $network;
    private Client $client;
    /**
     * Socket pairs exercise real HTTP/2 frames without a TLS server or a port.
     *
     * @var array<string, resource>
     */
    private array $peers = [];
    /**
     * A replacement leaves its predecessor's peer open until teardown.
     *
     * @var list<resource>
     */
    private array $retiredPeers = [];
    /**
     * @var array<string, Connection>
     */
    private array $connections = [];
    /**
     * @var list<Task>
     */
    private array $tasks = [];

    protected function setUp(): void
    {
        $this->loop = EventLoop::install(RevoltEventLoop::create());
        $this->network = new Network();
        $this->client = new Client(transport: $this->network, timeout: 5);
    }

    protected function tearDown(): void
    {
        foreach ($this->tasks as $task) {
            $task->cancel();
        }
        $this->client->close();
        foreach ([...array_values($this->peers), ...$this->retiredPeers] as $peer) {
            fclose($peer);
        }
        $this->loop->run();
        parent::tearDown();
    }

    public function testExpiredSessionsReleaseTheirSocketsAndWatchersWithoutBeingCalledAgain(): void
    {
        $this->connect('fresh');
        $this->get('fresh');
        for ($wave = 0; $wave < 3; ++$wave) {
            $expired = [];
            for ($i = 0; $i < 20; ++$i) {
                $host = "wave-{$wave}-{$i}";
                $expired[] = $host;
                $this->connect($host);
                $this->get($host);
            }
            $this->expire($expired);
            $this->get('fresh', 3 + ($wave * 2));
            $this->turn();

            self::assertSame(['https://fresh:443'], array_keys($this->kept('sessions')));
            self::assertSame(['https://fresh:443'], array_keys($this->kept('pools')));
            foreach ($expired as $host) {
                self::assertNull($this->stream($host), 'An expired session must close its socket.');
            }
            self::assertCount(1, $this->loop->getIdentifiers(), 'Only the fresh session may retain a read watcher.');
        }
    }

    public function testAFreshSessionIsReusedWhileItsExpiredNeighborIsRemoved(): void
    {
        $this->connect('old');
        $this->get('old');
        $fresh = $this->connect('fresh');
        $this->get('fresh');
        $this->expire(['old']);
        $this->get('fresh', 3);

        self::assertNull($this->stream('old'));
        self::assertSame(['https://fresh:443' => $fresh], $this->kept('sessions'));
        self::assertIsResource($this->stream('fresh'));
    }

    public function testASessionThatNeverSentARequestCanStillExpire(): void
    {
        $this->connect('unused');
        $this->expire(['unused']);
        $this->connect('trigger');
        $this->get('trigger');

        self::assertNull($this->stream('unused'));
        self::assertSame(['https://trigger:443'], array_keys($this->kept('sessions')));
        self::assertSame(['https://trigger:443'], array_keys($this->kept('idleSince')));
    }

    public function testSweepingPreservesAnActiveStreamAndItsCapacityWaiter(): void
    {
        $this->connect('old');
        $this->get('old');
        $busy = $this->connect('busy', 1);
        $this->get('busy');
        $this->connect('trigger');
        $this->get('trigger');
        $this->expire(['old', 'busy']);
        $this->postponeSweep();
        $first = $this->call('busy', '/first');
        $second = $this->call('busy', '/second');
        $this->turn();
        self::assertCount(1, $this->state($busy, 'streams'));
        self::assertCount(
            1,
            $this->state($busy, 'waiting'),
            'The second caller must actually wait for stream capacity.',
        );

        $this->sweepNext();
        $this->get('trigger', 3);
        self::assertSame($busy, $this->kept('sessions')['https://busy:443']);
        self::assertIsResource($this->stream('busy'));
        $this->answer($this->peers['busy'], 3, 'first');
        self::assertSame('first', $this->awaitTask($first));
        $this->turn();
        self::assertArrayHasKey(5, $this->state($busy, 'streams'));
        $this->answer($this->peers['busy'], 5, 'second');
        self::assertSame('second', $this->awaitTask($second));
        self::assertSame([], $this->state($busy, 'streams'));
        self::assertNull($this->stream('old'));
        $this->get('busy', 7);
        self::assertSame([], $this->kept('http2Calls'));
    }

    public function testACancelledCapacityWaiterDoesNotRenewTheSessionsIdleAge(): void
    {
        $busy = $this->connect('busy', 1);
        $this->get('busy');
        $this->connect('trigger');
        $this->get('trigger');
        $this->expire(['busy']);
        $ages = $this->kept('idleSince');
        self::assertArrayHasKey('https://busy:443', $ages);
        $since = $ages['https://busy:443'];
        $this->postponeSweep();
        $first = $this->call('busy', '/held');
        $waiting = $this->call('busy', '/cancelled');
        $this->turn();
        self::assertCount(1, $this->state($busy, 'waiting'));

        $waiting->cancel();
        $failure = null;
        try {
            $this->awaitTask($waiting);
        } catch (CancelledException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(CancelledException::class, $failure);
        self::assertSame($since, $this->kept('idleSince')['https://busy:443']);
        self::assertSame([], $this->state($busy, 'waiting'));
        self::assertSame($busy, $this->kept('sessions')['https://busy:443']);
        $this->answer($this->peers['busy'], 3, 'finished');
        self::assertSame('finished', $this->awaitTask($first));
        self::assertGreaterThan($since, $this->kept('idleSince')['https://busy:443']);
        self::assertSame([], $this->kept('http2Calls'));

        $this->expire(['busy']);
        $this->get('trigger', 3);
        self::assertNull($this->stream('busy'));
    }

    public function testAnOldCallsCompletionDoesNotRenewItsReplacementSession(): void
    {
        $old = $this->connect('replaced');
        $this->get('replaced');
        $oldPeer = $this->peers['replaced'];
        $task = $this->call('replaced', '/held');
        $this->turn();
        self::assertArrayHasKey(3, $this->state($old, 'streams'));
        // GOAWAY leaves the accepted stream running while another session
        // starts. The old call must not refresh the new session's idle age.
        new \ReflectionMethod($old, 'leave')->invoke($old, 3, 0);
        self::assertFalse($old->accepts());
        $replacement = $this->connect('replaced');
        $this->get('replaced');
        $this->expire(['replaced']);
        $ages = $this->kept('idleSince');
        self::assertArrayHasKey('https://replaced:443', $ages);
        $since = $ages['https://replaced:443'];
        $this->answer($oldPeer, 3, 'old response');
        self::assertSame('old response', $this->awaitTask($task));
        self::assertSame($replacement, $this->kept('sessions')['https://replaced:443']);
        self::assertSame($since, $this->kept('idleSince')['https://replaced:443']);
        self::assertSame([], $this->kept('http2Calls'));

        $this->connect('trigger');
        $this->get('trigger');
        self::assertNull($this->stream('replaced'));
        self::assertSame(['https://trigger:443'], array_keys($this->kept('sessions')));
    }

    public function testClientCloseClearsIdleStateAsAnActiveCallUnwinds(): void
    {
        $busy = $this->connect('busy');
        $this->get('busy');
        $task = $this->call('busy', '/held');
        $this->turn();
        self::assertCount(1, $this->state($busy, 'streams'));
        $this->client->close();
        $failure = null;
        try {
            $this->awaitTask($task);
        } catch (NetworkException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(NetworkException::class, $failure);
        self::assertSame([], $this->kept('sessions'));
        self::assertSame([], $this->kept('pools'));
        self::assertSame([], $this->kept('idleSince'));
        self::assertNull($this->stream('busy'));
        $this->turn();
        self::assertSame([], $this->loop->getIdentifiers());

        $this->connect('reopened');
        $this->get('reopened');
        $this->expire(['reopened']);
        $this->connect('trigger');
        $this->get('trigger');
        self::assertNull($this->stream('reopened'));
        self::assertSame([], $this->kept('http2Calls'));
    }

    /**
     * Install a real connection and start its HTTP/2 conversation without TLS.
     */
    private function connect(string $host, int $maxStreams = 100): Http2Session
    {
        [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($socket, false);
        stream_set_blocking($peer, false);
        if (isset($this->peers[$host])) {
            $this->retiredPeers[] = $this->peers[$host];
        }
        $this->peers[$host] = $peer;
        $class = new \ReflectionClass(Connection::class);
        $connection = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($connection, $this->loop, $socket);
        $this->connections[$host] = $connection;
        $pool = new \ReflectionMethod($this->network, 'pool')->invoke($this->network, 'https', $host, 443);
        $slot = $pool->acquire();
        $slot->connection = $connection;
        $session = new \ReflectionMethod($this->network, 'session')->invoke(
            $this->network,
            "https://{$host}:443",
            $pool,
            $slot,
            (hrtime(true) / 1e9) + 5,
        );
        self::assertStringStartsWith("PRI * HTTP/2.0\r\n", stream_get_contents($peer));
        $settings = self::frame(4, 0, 0, pack('nN', 3, $maxStreams));
        self::assertSame(strlen($settings), fwrite($peer, $settings));
        return $session;
    }

    private static function frame(int $type, int $flags, int $stream, string $body): string
    {
        return substr(pack('N', strlen($body)), 1) . chr($type) . chr($flags) . pack('N', $stream) . $body;
    }

    /**
     * @param resource $peer
     */
    private function answer(mixed $peer, int $stream, string $body): void
    {
        $headers = Hpack::encode([[':status', '200'], ['content-length', (string) strlen($body)]]);
        $wire = self::frame(1, 4, $stream, $headers) . self::frame(0, 1, $stream, $body);
        self::assertSame(strlen($wire), fwrite($peer, $wire), 'The socket-pair fixture could not write its answer.');
    }

    private function get(string $host, int $stream = 1): void
    {
        $this->answer($this->peers[$host], $stream, $host);
        $response = $this->client->get("https://{$host}/");
        self::assertSame('2', $response->getProtocolVersion());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($host, (string) $response->getBody());
        stream_get_contents($this->peers[$host]);
    }

    private function call(string $host, string $path): Task
    {
        $task = Task::spawn($this->loop, function (Cancellation $cancellation) use ($host, $path): string {
            $response = $this->client->request('GET', "https://{$host}{$path}", cancellation: $cancellation);
            self::assertSame('2', $response->getProtocolVersion());
            self::assertSame(200, $response->getStatusCode());
            return (string) $response->getBody();
        });
        $this->tasks[] = $task;
        return $task;
    }

    private function awaitTask(Task $task): mixed
    {
        $cancellation = new Cancellation();
        $timer = $this->loop->delay(5, static fn() => $cancellation->cancel(
            new \RuntimeException('The HTTP/2 request did not finish after its peer answered.'),
        ));
        try {
            return $task->await($cancellation);
        } finally {
            $this->loop->cancel($timer);
        }
    }

    /**
     * A barrier behind already scheduled calls, with their parked state
     * asserted by the test. No elapsed-time assumption proves overlap.
     */
    private function turn(): void
    {
        $ready = $this->loop->getSuspension();
        $this->loop->defer(static fn() => $ready->resume());
        $this->await($ready);
    }

    private function await(Suspension $suspension): void
    {
        $timer = $this->loop->delay(5, static fn() => $suspension->throw(
            new \RuntimeException('The HTTP/2 fixture did not reach its next loop turn.'),
        ));
        try {
            $suspension->suspend();
        } finally {
            $this->loop->cancel($timer);
        }
    }

    /**
     * Advance recorded ages only; an origin absent from the original
     * implementation's bookkeeping stays absent for the before-fix check.
     *
     * @param list<string> $hosts
     */
    private function expire(array $hosts): void
    {
        $ages = $this->kept('idleSince');
        foreach ($hosts as $host) {
            $origin = "https://{$host}:443";
            if (isset($ages[$origin])) {
                $ages[$origin] = (hrtime(true) / 1e9) - 31;
            }
        }
        new \ReflectionProperty($this->network, 'idleSince')->setValue($this->network, $ages);
        $this->sweepNext();
    }

    private function sweepNext(): void
    {
        new \ReflectionProperty($this->network, 'pruneAt')->setValue($this->network, 0.0);
    }

    private function postponeSweep(): void
    {
        new \ReflectionProperty($this->network, 'pruneAt')->setValue($this->network, INF);
    }

    /**
     * @return array<string, mixed>
     */
    private function kept(string $property): array
    {
        return $this->state($this->network, $property);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function state(object $owner, string $property): array
    {
        return new \ReflectionProperty($owner, $property)->getValue($owner);
    }

    /**
     * @return resource|null
     */
    private function stream(string $host): mixed
    {
        return new \ReflectionProperty(Connection::class, 'stream')->getValue($this->connections[$host]);
    }
}
