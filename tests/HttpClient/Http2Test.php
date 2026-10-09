<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Http\Request;
use Crab\Http\Stream;
use Crab\HttpClient\Client;
use Crab\HttpClient\CookieJar;
use Crab\HttpClient\Hpack;
use Crab\HttpClient\NetworkException;
use PHPUnit\Framework\TestCase;

/**
 * The client against a real HTTP/2 server, Node's, and its header
 * compression against the examples of the specification.
 */
final class Http2Test extends TestCase
{
    /**
     * @var resource|null
     */
    private mixed $node = null;
    private string $certificate = '';
    private string $url = '';
    /**
     * @var list<Client>
     */
    private array $clients = [];

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        if (is_resource($this->node)) {
            proc_terminate($this->node, 9);
            proc_close($this->node);
        }
        if ($this->certificate !== '') {
            @unlink($this->certificate);
        }
        EventLoop::current()->run();
    }

    public function testHeadersAreReadAsTheSpecificationsExamplesSay(): void
    {
        // RFC 7541, C.4: three requests on one connection, with Huffman coding and the table growing.
        $hpack = new Hpack();
        $read = static fn (string $hex): array => $hpack->decode((string) hex2bin(str_replace(' ', '', $hex)));
        $first = [[':method', 'GET'], [':scheme', 'http'], [':path', '/'], [':authority', 'www.example.com']];
        self::assertSame($first, $read('8286 8441 8cf1 e3c2 e5f2 3a6b a0ab 90f4 ff'));
        self::assertSame([...$first, ['cache-control', 'no-cache']], $read('8286 84be 5886 a8eb 1064 9cbf'));
        self::assertSame(
            [[':method', 'GET'], [':scheme', 'https'], [':path', '/index.html'], [':authority', 'www.example.com'], ['custom-key', 'custom-value']],
            $read('8287 85bf 4088 25a8 49e9 5ba9 7d7f 8925 a849 e95b b8e8 b4bf'),
        );
        // C.6.1: an answer, whose table is then made to hold 256 bytes and no more.
        $answers = new Hpack(256);
        self::assertSame(
            [[':status', '302'], ['cache-control', 'private'], ['date', 'Mon, 21 Oct 2013 20:13:21 GMT'], ['location', 'https://www.example.com']],
            $answers->decode((string) hex2bin('488264025885aec3771a4b6196d07abe941054d444a8200595040b8166e082a62d1bff6e919d29ad171863c78f0b97c8e9ae82ae43d3')),
        );
        self::assertSame(
            [[':status', '307'], ['cache-control', 'private'], ['date', 'Mon, 21 Oct 2013 20:13:21 GMT'], ['location', 'https://www.example.com']],
            $answers->decode((string) hex2bin('4883640effc1c0bf')),
        );

        // What is written is read back the same, whatever the lengths.
        $fields = [[':method', 'PATCH'], [':path', '/a?b=c'], ['authorization', 'Bearer secret'], ['x-long', str_repeat('v', 70000)], ['x-empty', ''], ['accept', '*/*']];
        self::assertSame($fields, (new Hpack())->decode(Hpack::encode($fields)));
        self::assertSame("\x82\x87\x84", Hpack::encode([[':method', 'GET'], [':scheme', 'https'], [':path', '/']]), 'A field the connection knows whole is one byte.');

        foreach (['ff', '40', '7f', 'be', '3fe11f', '4082ffff'] as $broken) {
            try {
                (new Hpack())->decode((string) hex2bin($broken));
                self::fail("{$broken} was read as a header block.");
            } catch (\RuntimeException) {
            }
        }
    }

    public function testRequestsShareOneConnectionAndRunAtOnce(): void
    {
        $http = $this->serve();
        $got = $http->get($this->url . '/echo', query: ['page' => 2], headers: ['X-Api-Key' => 'k', 'Cookie' => 'a=1']);
        self::assertSame(['2', 200, 'application/json'], [$got->getProtocolVersion(), $got->getStatusCode(), $got->getHeaderLine('Content-Type')]);
        $seen = $got->json();
        self::assertSame(['GET', '/echo?page=2', 'https', 'k', 'a=1', 'Crab', 'gzip, deflate', 1], [
            $seen['method'], $seen['path'], $seen['scheme'], $seen['headers']['x-api-key'], $seen['headers']['cookie'], $seen['headers']['user-agent'], $seen['headers']['accept-encoding'], $seen['session'],
        ]);
        self::assertStringStartsWith('localhost:', $seen['authority']);

        $posted = $http->post($this->url . '/echo', json: ['name' => 'Ada'])->json();
        self::assertSame(['POST', '{"name":"Ada"}', 'application/json', '14', 1], [$posted['method'], $posted['body'], $posted['headers']['content-type'], $posted['headers']['content-length'], $posted['session']]);

        // Six requests of 0.2 seconds each, to a server that takes three at a time, on the one connection.
        $loop = EventLoop::current();
        $started = hrtime(true);
        $tasks = [];
        for ($i = 0; $i < 6; ++$i) {
            $tasks[] = Task::spawn($loop, fn (): array => $http->get($this->url . '/slow', query: ['n' => $i])->json());
        }
        $answers = array_map(static fn (Task $task): array => $task->await(), $tasks);
        $took = (hrtime(true) - $started) / 1e9;
        self::assertSame(['/slow?n=0', '/slow?n=1', '/slow?n=2', '/slow?n=3', '/slow?n=4', '/slow?n=5'], array_column($answers, 'path'));
        self::assertSame([1], array_values(array_unique(array_column($answers, 'session'))));
        self::assertGreaterThan(0.35, $took, 'No more at once than the server allows.');
        self::assertLessThan(0.9, $took, 'And not one after another.');

        $jar = new CookieJar();
        $http->cookies($jar)->get($this->url . '/echo');
        self::assertSame(['h2' => 'yes'], $jar->for($this->url . '/'));
        self::assertSame('/echo?redirected=1', $http->get($this->url . '/redirect')->json()['path']);
    }

    public function testBodiesOfAnySizeGoBothWays(): void
    {
        $http = $this->serve();
        $big = $http->get($this->url . '/big');
        self::assertSame([80 * 65536, md5(str_repeat(substr(str_repeat('abcdefghijklmnopqrstuvwxyz', 2521), 0, 65536), 80))], [$big->getBody()->getSize(), md5((string) $big->getBody())]);

        $content = random_bytes(3 * 1024 * 1024);
        $sent = $http->request('PUT', $this->url . '/echo', body: $content, headers: ['Content-Type' => 'application/octet-stream'])->json();
        self::assertSame([strlen($content), md5($content), (string) strlen($content)], [$sent['length'], $sent['hash'], $sent['headers']['content-length']]);

        // Of a length nobody knows beforehand.
        $pipe = popen(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo str_repeat("streamed ", 30000);'), 'r');
        $streamed = json_decode((string) $http->sendRequest(new Request('POST', $this->url . '/echo', [], new Stream($pipe)))->getBody(), true);
        self::assertSame([270000, false], [$streamed['length'], isset($streamed['headers']['content-length'])]);

        self::assertSame(['compressed' => true], $http->get($this->url . '/gzip')->json());
        self::assertSame(40000, strlen($http->get($this->url . '/long-headers')->getHeaderLine('X-Long')), 'Headers longer than one frame.');
        self::assertSame(30000, strlen($http->get($this->url . '/echo', headers: ['X-Long' => str_repeat('q', 30000)])->json()['headers']['x-long']));
        self::assertSame([204, ''], [$http->get($this->url . '/nothing')->getStatusCode(), (string) $http->get($this->url . '/nothing')->getBody()]);
        self::assertSame('with trailers', (string) $http->get($this->url . '/trailers')->getBody());
        self::assertSame([200, ''], [$http->request('HEAD', $this->url . '/echo')->getStatusCode(), (string) $http->request('HEAD', $this->url . '/echo')->getBody()]);
        self::assertSame([413, 'too large'], [($early = $http->request('POST', $this->url . '/early', body: $content))->getStatusCode(), (string) $early->getBody()], 'An answer that comes before the body is all sent is the answer.');
        self::assertSame(1, $http->get($this->url . '/echo')->json()['session'], 'All of it on the one connection.');
    }

    public function testWhatTheServerDidNotActOnIsMadeAgainAndTheRestIsReported(): void
    {
        $http = $this->serve();
        $again = $http->post($this->url . '/refuse-once', json: ['pay' => 5])->json();
        self::assertSame(['POST', '{"pay":5}', 2], [$again['method'], $again['body'], $again['times']], 'A request the server refused before acting on it is made again, a POST too.');

        try {
            $http->get($this->url . '/reset');
            self::fail('A request the server gave up on was answered.');
        } catch (NetworkException $error) {
            self::assertStringContainsString('/reset failed: the server gave up on the request, with an internal error.', $error->getMessage());
        }
        try {
            $http->timeout(0.05)->get($this->url . '/slow');
            self::fail('A request that took too long was waited for.');
        } catch (NetworkException $error) {
            self::assertStringContainsString('it took more than 0.05 seconds, and was waiting for the answer when given up', $error->getMessage());
        }
        self::assertSame(1, $http->get($this->url . '/echo')->json()['session'], 'Neither cost the connection.');

        // A server that says it is leaving is left: the next request goes over a new connection.
        self::assertSame('last one here', (string) $http->get($this->url . '/goaway')->getBody());
        self::assertSame(2, $http->get($this->url . '/echo')->json()['session']);

        self::assertSame('after 3 tries', (string) $http->retry(3, 0.01)->get($this->url . '/busy-twice')->getBody());

        // This server speaks HTTP/2 only, so a client told not to offer it finds nothing in common.
        try {
            (new Client(tls: ['cafile' => $this->certificate], http2: false))->get($this->url . '/echo');
            self::fail('HTTP/2 was spoken by a client told not to.');
        } catch (NetworkException $error) {
            self::assertStringContainsString('the encrypted connection could not be made', $error->getMessage());
        }
    }

    /**
     * Starts the server and returns a client that trusts it.
     */
    private function serve(): Client
    {
        $node = trim((string) @shell_exec('command -v node'));
        if ($node === '' || !extension_loaded('openssl')) {
            self::markTestSkipped('Needs Node.js, for an HTTP/2 server, and the openssl extension.');
        }
        $this->certificate = TestServer::certificate('localhost');
        $this->node = proc_open([$node, __DIR__ . '/h2-server.mjs', $this->certificate], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $port = (int) fgets($pipes[1]);
        self::assertGreaterThan(0, $port, 'The HTTP/2 server did not start.');
        $this->url = "https://localhost:{$port}";
        return $this->clients[] = new Client(tls: ['cafile' => $this->certificate], timeout: 10.0);
    }
}
