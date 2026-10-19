<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\Pool;
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

final class NetworkIdleTest extends TestCase
{
    private EventLoop $loop;
    private Network $network;
    private Client $client;
    private string $hosts;
    /**
     * Socket pairs exercise the real exchange without listening on a port.
     *
     * @var array<string, resource>
     */
    private array $peers = [];
    /**
     * @var array<string, Connection>
     */
    private array $connections = [];
    /**
     * @var array<string, string>
     */
    private array $watchers = [];
    /**
     * @var list<Task>
     */
    private array $tasks = [];
    /**
     * A held request is answered by its test after it has proved overlap.
     *
     * @var (\Closure(string, string): bool)|null
     */
    private ?\Closure $hold = null;

    protected function setUp(): void
    {
        $this->loop = EventLoop::install(RevoltEventLoop::create());
        $this->hosts = sys_get_temp_dir() . '/crab-idle-hosts-' . bin2hex(random_bytes(6));
        file_put_contents($this->hosts, '');
        $this->network = new Network(
            connections: 1,
            resolver: new DnsResolver([], fallback: false, hostsFile: $this->hosts),
        );
        $this->client = new Client(transport: $this->network, timeout: 5);
    }

    protected function tearDown(): void
    {
        foreach ($this->tasks as $task) {
            $task->cancel();
        }
        $this->client->close();
        foreach ($this->watchers as $watcher) {
            $this->loop->cancel($watcher);
        }
        foreach ($this->peers as $peer) {
            if (!is_resource($peer)) {
                continue;
            }
            fclose($peer);
        }
        unlink($this->hosts);
        $this->loop->run();
        parent::tearDown();
    }

    public function testExpiredOriginsReleaseTheirSocketsWithoutBeingRequestedAgain(): void
    {
        $this->connect('live');
        $this->get('live');
        for ($wave = 0; $wave < 3; ++$wave) {
            $expired = [];
            for ($i = 0; $i < 20; ++$i) {
                $host = "wave-{$wave}-{$i}";
                $expired[] = $host;
                $this->connect($host);
                $this->get($host);
            }
            $this->expire($expired);
            $this->get('live', "/wave-{$wave}");

            self::assertSame(['http://live:80'], array_keys($this->kept('pools')));
            foreach ($expired as $host) {
                self::assertNull($this->stream($host), 'An expired pool must close its socket, not just lose its key.');
            }
        }
    }

    public function testSweepingExpiredNeighborsPreservesAFreshReusableConnection(): void
    {
        $this->connect('old');
        $this->get('old');
        $this->connect('fresh');
        $this->get('fresh');
        $fresh = $this->kept('pools')['http://fresh:80'];
        $this->expire(['old']);
        $this->get('fresh', '/again');

        self::assertNull($this->stream('old'));
        self::assertSame(['http://fresh:80'], array_keys($this->kept('pools')));
        self::assertSame($fresh, $this->kept('pools')['http://fresh:80']);
        self::assertIsResource($this->stream('fresh'));
    }

    public function testSweepingPreservesAnActiveRequestAndItsWaitingCaller(): void
    {
        $this->connect('old');
        $this->get('old');
        $this->connect('held');
        $this->get('held');
        $this->connect('trigger');
        $held = $this->kept('pools')['http://held:80'];
        $ready = $this->loop->getSuspension();
        $this->hold = static function (string $host, string $path) use ($ready): bool {
            if ($host !== 'held' || $path !== '/held') {
                return false;
            }
            $ready->resume();
            return true;
        };
        $first = $this->call('held', '/held');
        $this->await($ready, 'The held request did not reach its socket peer.');
        $waiting = $this->call('held', '/waiting');
        $turn = $this->loop->getSuspension();
        $this->loop->defer(static fn() => $turn->resume());
        $this->await($turn, 'The second caller did not get its event-loop turn.');
        self::assertSame(1, $held->stats()['waiting'], 'The second caller must actually be waiting for this pool.');

        try {
            $this->expire(['old', 'held']);
            $this->get('trigger');
            self::assertSame($held, $this->kept('pools')['http://held:80']);
            self::assertSame(1, $held->stats()['borrowed']);
            self::assertNull($this->stream('old'));
        } finally {
            $this->answer($this->peers['held'], 'held/held');
            $one = $this->awaitTask($first);
            $two = $this->awaitTask($waiting);
        }
        self::assertSame('held/held', $one);
        self::assertSame('held/waiting', $two);
        $this->get('held', '/reused');
        self::assertSame($held, $this->kept('pools')['http://held:80']);
    }

    public function testPeerClosureAndClientCloseLeaveNoIdleBookkeeping(): void
    {
        $this->connect('ended');
        $this->get('ended');
        $this->loop->cancel($this->watchers['ended']);
        fclose($this->peers['ended']);
        $this->expire(['ended']);
        $this->connect('live');
        $this->get('live');
        self::assertNull($this->stream('ended'));
        self::assertSame(['http://live:80'], array_keys($this->kept('pools')));

        $this->client->close();
        self::assertSame([], $this->kept('pools'));
        self::assertSame([], $this->kept('idleSince'));
        self::assertSame(0.0, new \ReflectionProperty($this->network, 'pruneAt')->getValue($this->network));
        self::assertNull($this->stream('live'));
        $this->connect('reopened');
        $this->get('reopened');
        $this->expire(['reopened']);
        $this->failToResolve('missing');
        self::assertSame([], $this->kept('pools'));
        self::assertSame([], $this->kept('idleSince'));
    }

    public function testAnUnsentAttemptDoesNotRenewAnOldPoolsIdleTime(): void
    {
        $this->connect('old');
        $this->get('old');
        $this->expire(['old']);
        // Defer maintenance while an already-expired request visits the old
        // origin. It borrows nothing, so it must not renew the idle period.
        if (property_exists($this->network, 'pruneAt')) {
            new \ReflectionProperty($this->network, 'pruneAt')->setValue($this->network, (hrtime(true) / 1e9) + 3600);
        }
        $failure = null;
        try {
            $this->network->exchange(new Request('GET', 'http://old/'), (hrtime(true) / 1e9) - 1, 1, null);
        } catch (NetworkException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(NetworkException::class, $failure);
        self::assertTrue($failure->unsent);
        self::assertIsResource($this->stream('old'), 'Maintenance must respect its next scheduled sweep.');
        $this->sweepNext();
        $this->failToResolve('missing');
        self::assertSame([], $this->kept('pools'));
        self::assertNull($this->stream('old'));
    }

    public function testSweepingIdleHttp1PoolsPreservesAnHttp2Session(): void
    {
        $this->connect('old');
        $this->get('old');
        [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($socket, false);
        stream_set_blocking($peer, false);
        $this->peers['http2'] = $peer;
        $connection = $this->connection($socket);
        $pool = $this->pool('http2', 'https');
        $slot = $pool->acquire();
        $slot->connection = $connection;
        $session = new \ReflectionMethod($this->network, 'session')->invoke(
            $this->network,
            'https://http2:443',
            $pool,
            $slot,
            (hrtime(true) / 1e9) + 5,
        );
        self::assertStringStartsWith("PRI * HTTP/2.0\r\n", fread($peer, 8192));
        $this->expire(['old']);
        $this->failToResolve('missing');

        self::assertNull($this->stream('old'));
        self::assertSame(['https://http2:443' => $pool], $this->kept('pools'));
        self::assertSame(['https://http2:443' => $session], $this->kept('sessions'));
        self::assertSame(1, $pool->stats()['borrowed']);
        $session->close();
        self::assertSame([], $this->kept('pools'));
        self::assertSame([], $this->kept('idleSince'));
    }

    private function connect(string $host): void
    {
        [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($socket, false);
        stream_set_blocking($peer, false);
        $this->peers[$host] = $peer;
        $connection = $this->connections[$host] = $this->connection($socket);
        $pool = $this->pool($host);
        $slot = $pool->acquire();
        $slot->connection = $connection;
        $pool->release($slot);
        $buffer = '';
        $this->watchers[$host] = $this->loop->onReadable($peer, function (string $watcher) use (
            $host,
            $peer,
            &$buffer,
        ): void {
            $bytes = fread($peer, 8192);
            if ($bytes === '' && feof($peer)) {
                $this->loop->cancel($watcher);
                return;
            }
            $buffer .= $bytes;
            while (($end = strpos($buffer, "\r\n\r\n")) !== false) {
                $head = substr($buffer, 0, $end);
                $buffer = substr($buffer, $end + 4);
                $path = explode(' ', $head, 3)[1];
                if ($this->hold !== null && ($this->hold)($host, $path)) {
                    continue;
                }
                $this->answer($peer, $host . $path);
            }
        });
    }

    /**
     * Install an actual HTTP-client connection without DNS or a listener.
     *
     * @param resource $socket
     */
    private function connection(mixed $socket): Connection
    {
        $class = new \ReflectionClass(Connection::class);
        $connection = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($connection, $this->loop, $socket);
        return $connection;
    }

    private function pool(string $host, string $scheme = 'http'): Pool
    {
        $port = $scheme === 'https' ? 443 : 80;
        $pool = new \ReflectionMethod($this->network, 'pool')->invoke($this->network, $scheme, $host, $port);
        return $pool;
    }

    /**
     * @param resource $peer
     */
    private function answer(mixed $peer, string $body): void
    {
        $reply = "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
        self::assertSame(strlen($reply), fwrite($peer, $reply), 'The socket-pair fixture could not write its answer.');
    }

    private function get(string $host, string $path = '/'): void
    {
        $response = $this->client->get("http://{$host}{$path}");
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($host . $path, (string) $response->getBody());
    }

    private function call(string $host, string $path): Task
    {
        $task = Task::spawn($this->loop, function (Cancellation $cancellation) use ($host, $path): string {
            $response = $this->client->request('GET', "http://{$host}{$path}", cancellation: $cancellation);
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
            new \RuntimeException('The HTTP request did not finish after its peer answered.'),
        ));
        try {
            return $task->await($cancellation);
        } finally {
            $this->loop->cancel($timer);
        }
    }

    private function await(Suspension $suspension, string $message): void
    {
        $timer = $this->loop->delay(5, static fn() => $suspension->throw(new \RuntimeException($message)));
        try {
            $suspension->suspend();
        } finally {
            $this->loop->cancel($timer);
        }
    }

    /**
     * Advance stored idle ages, not real time; the same tests can fail on the
     * original implementation, which has no origin-level maintenance fields.
     *
     * @param list<string> $hosts
     */
    private function expire(array $hosts): void
    {
        foreach ($hosts as $host) {
            $this->connections[$host]->idleSince = 0.0;
        }
        if (property_exists($this->network, 'idleSince')) {
            $property = new \ReflectionProperty($this->network, 'idleSince');
            $idle = $property->getValue($this->network);
            foreach ($hosts as $host) {
                $origin = "http://{$host}:80";
                if (isset($idle[$origin])) {
                    $idle[$origin] = 0.0;
                }
            }
            $property->setValue($this->network, $idle);
        }
        $this->sweepNext();
    }

    private function sweepNext(): void
    {
        if (property_exists($this->network, 'pruneAt')) {
            new \ReflectionProperty($this->network, 'pruneAt')->setValue($this->network, 0.0);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function kept(string $name): array
    {
        return new \ReflectionProperty($this->network, $name)->getValue($this->network);
    }

    /**
     * @return resource|null
     */
    private function stream(string $host): mixed
    {
        return new \ReflectionProperty(Connection::class, 'stream')->getValue($this->connections[$host]);
    }

    private function failToResolve(string $host): void
    {
        $failure = null;
        try {
            $this->client->get("http://{$host}/");
        } catch (NetworkException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(NetworkException::class, $failure);
        self::assertTrue($failure->unsent);
        self::assertStringContainsString('no address was found', $failure->getMessage());
    }
}
