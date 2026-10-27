<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Http\Request;
use Crab\Http\Stream;
use Crab\HttpClient\Client;
use Crab\HttpClient\Connection;
use Crab\HttpClient\CookieJar;
use Crab\HttpClient\Hpack;
use Crab\HttpClient\Http2Session;
use Crab\HttpClient\Network;
use Crab\HttpClient\NetworkException;
use Crab\HttpClient\Receiving;
use Crab\HttpClient\TimedOut;
use Crab\HttpClient\TooLargeException;
use Crab\Tests\Support\Wait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

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
        $read = static fn(string $hex): array => $hpack->decode((string) hex2bin(str_replace(' ', '', $hex)));
        $first = [
            [':method',    'GET'],
            [':scheme',    'http'],
            [':path',      '/'],
            [':authority', 'www.example.com'],
        ];
        self::assertSame($first, $read('8286 8441 8cf1 e3c2 e5f2 3a6b a0ab 90f4 ff'));
        self::assertSame([...$first, ['cache-control', 'no-cache']], $read('8286 84be 5886 a8eb 1064 9cbf'));
        self::assertSame(
            [
                [':method',    'GET'],
                [':scheme',    'https'],
                [':path',      '/index.html'],
                [':authority', 'www.example.com'],
                ['custom-key', 'custom-value'],
            ],
            $read('8287 85bf 4088 25a8 49e9 5ba9 7d7f 8925 a849 e95b b8e8 b4bf'),
        );
        // C.6.1: an answer, whose table is then made to hold 256 bytes and no more.
        $answers = new Hpack(256);
        self::assertSame(
            [
                [':status',       '302'],
                ['cache-control', 'private'],
                ['date',          'Mon, 21 Oct 2013 20:13:21 GMT'],
                ['location',      'https://www.example.com'],
            ],
            $answers->decode((string) hex2bin(
                '488264025885aec3771a4b6196d07abe941054d444a8200595040b8166e082a62d1bff6e919d29ad171863c78f0b97c8e9ae82ae43d3',
            )),
        );
        self::assertSame(
            [
                [':status',       '307'],
                ['cache-control', 'private'],
                ['date',          'Mon, 21 Oct 2013 20:13:21 GMT'],
                ['location',      'https://www.example.com'],
            ],
            $answers->decode((string) hex2bin('4883640effc1c0bf')),
        );

        // What is written is read back the same, including long values within the header-list limit.
        $fields = [
            [':method',       'PATCH'],
            [':path',         '/a?b=c'],
            ['authorization', 'Bearer secret'],
            ['x-long',        str_repeat('v', 60_000)],
            ['x-empty',       ''],
            ['accept',        '*/*'],
        ];
        self::assertSame($fields, new Hpack()->decode(Hpack::encode($fields)));
        self::assertSame(
            "\x82\x87\x84",
            Hpack::encode([[':method', 'GET'], [':scheme', 'https'], [':path', '/']]),
            'A field the connection knows whole is one byte.',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedHeaderBlocks(): array
    {
        return [
            'truncated index' => ['ff'],
            'missing name' => ['40'],
            'truncated name index' => ['7f'],
            'missing dynamic entry' => ['be'],
            'table too large' => ['3fe21f'],
            'invalid Huffman code' => ['4082ffff'],
        ];
    }

    #[DataProvider('malformedHeaderBlocks')]
    public function testAMalformedHeaderBlockIsRefused(string $hex): void
    {
        $this->expectException(\RuntimeException::class);

        new Hpack()->decode((string) hex2bin($hex));
    }

    public function testAHeaderTableMayReachTheAdvertisedLimit(): void
    {
        self::assertSame([], new Hpack()->decode((string) hex2bin('3fe11f')));
    }

    public function testRequestsShareOneConnectionAndRunAtOnce(): void
    {
        $http = $this->serve();
        $got = $http->get($this->url . '/echo', query: ['page' => 2], headers: ['X-Api-Key' => 'k', 'Cookie' => 'a=1']);
        self::assertSame(['2', 200, 'application/json'], [
            $got->getProtocolVersion(),
            $got->getStatusCode(),
            $got->getHeaderLine('Content-Type'),
        ]);
        $seen = $got->json();
        self::assertSame(['GET', '/echo?page=2', 'https', 'k', 'a=1', 'Crab', 'gzip, deflate', 1], [
            $seen['method'],
            $seen['path'],
            $seen['scheme'],
            $seen['headers']['x-api-key'],
            $seen['headers']['cookie'],
            $seen['headers']['user-agent'],
            $seen['headers']['accept-encoding'],
            $seen['session'],
        ]);
        self::assertStringStartsWith('localhost:', $seen['authority']);

        $posted = $http->post($this->url . '/echo', json: ['name' => 'Ada'])->json();
        self::assertSame(['POST', '{"name":"Ada"}', 'application/json', '14', 1], [
            $posted['method'],
            $posted['body'],
            $posted['headers']['content-type'],
            $posted['headers']['content-length'],
            $posted['session'],
        ]);

        // Each batch holds its replies until all three requests have arrived.
        $loop = EventLoop::current();
        $tasks = [];
        for ($i = 0; $i < 6; ++$i) {
            $tasks[] = Task::spawn($loop, fn(): array => $http->get($this->url . '/batch', query: ['n' => $i])->json());
        }
        $answers = array_map(static fn(Task $task): array => $task->await(), $tasks);
        self::assertSame(
            ['/batch?n=0', '/batch?n=1', '/batch?n=2', '/batch?n=3', '/batch?n=4', '/batch?n=5'],
            array_column($answers, 'path'),
        );
        self::assertSame([1], array_values(array_unique(array_column($answers, 'session'))));
        self::assertSame(
            [1, 1, 1, 2, 2, 2],
            array_column($answers, 'batch'),
            'Three requests overlap in each batch on the same session.',
        );

        $jar = new CookieJar();
        $http->cookies($jar)->get($this->url . '/echo');
        self::assertSame(['h2' => 'yes'], $jar->for($this->url . '/'));
        self::assertSame('/echo?redirected=1', $http->get($this->url . '/redirect')->json()['path']);
    }

    public function testConcurrentFirstRequestsJoinTheHttp2SessionBeingOpened(): void
    {
        $http = $this->serve(connections: 1, timeout: 2.0);
        $loop = EventLoop::current();
        $tasks = [];
        // No warm-up request: the first task must open the sole connection
        // while the others wait for the session that will keep it borrowed.
        for ($index = 0; $index < 3; ++$index) {
            $tasks[] = Task::spawn($loop, function () use ($http, $index): array|NetworkException {
                try {
                    $response = $http->get($this->url . '/slow', query: ['first' => $index]);
                    return ['protocol' => $response->getProtocolVersion(), ...$response->json()];
                } catch (NetworkException $error) {
                    return $error;
                }
            });
        }
        $answers = array_map(static fn(Task $task): array|NetworkException => $task->await(), $tasks);
        foreach ($answers as $answer) {
            self::assertIsArray($answer, $answer instanceof NetworkException ? $answer->getMessage() : '');
        }
        self::assertSame(['/slow?first=0', '/slow?first=1', '/slow?first=2'], array_column($answers, 'path'));
        self::assertSame(['2', '2', '2'], array_column($answers, 'protocol'));
        self::assertSame(
            [1, 1, 1],
            array_column($answers, 'session'),
            'Every first request must finish on the one new HTTP/2 session.',
        );
        self::assertSame(
            1,
            $http->get($this->url . '/echo')->json()['session'],
            'A later request keeps that same session.',
        );
    }

    public function testBodiesOfAnySizeGoBothWays(): void
    {
        $http = $this->serve();
        $big = $http->get($this->url . '/big');
        self::assertSame([
            80 * 65_536,
            md5(str_repeat(substr(str_repeat('abcdefghijklmnopqrstuvwxyz', 2521), 0, 65_536), 80)),
        ], [$big->getBody()->getSize(), md5((string) $big->getBody())]);

        $content = random_bytes(3 * 1024 * 1024);
        $sent = $http->request('PUT', $this->url . '/echo', body: $content, headers: [
            'Content-Type' => 'application/octet-stream',
        ])->json();
        self::assertSame([strlen($content), md5($content), (string) strlen($content)], [
            $sent['length'],
            $sent['hash'],
            $sent['headers']['content-length'],
        ]);

        // Of a length nobody knows beforehand.
        $pipe = popen(
            escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo str_repeat("streamed ", 30000);'),
            'r',
        );
        $streamed = json_decode(
            (string) $http->sendRequest(new Request('POST', $this->url . '/echo', [], new Stream($pipe)))->getBody(),
            true,
        );
        self::assertSame([270_000, false], [$streamed['length'], isset($streamed['headers']['content-length'])]);

        self::assertSame(['compressed' => true], $http->get($this->url . '/gzip')->json());
        self::assertSame(
            40_000,
            strlen($http->get($this->url . '/long-headers')->getHeaderLine('X-Long')),
            'Headers longer than one frame.',
        );
        self::assertSame(30_000, strlen($http->get($this->url . '/echo', headers: ['X-Long' => str_repeat(
            'q',
            30_000,
        )])->json()['headers']['x-long']));
        self::assertSame([204, ''], [
            $http->get($this->url . '/nothing')->getStatusCode(),
            (string) $http->get($this->url . '/nothing')->getBody(),
        ]);
        self::assertSame('with trailers', (string) $http->get($this->url . '/trailers')->getBody());
        self::assertSame([200, ''], [
            $http->request('HEAD', $this->url . '/echo')->getStatusCode(),
            (string) $http->request('HEAD', $this->url . '/echo')->getBody(),
        ]);
        self::assertSame(
            [413, 'too large'],
            [
                ($early = $http->request('POST', $this->url . '/early', body: $content))->getStatusCode(),
                (string) $early->getBody(),
            ],
            'An answer that comes before the body is all sent is the answer.',
        );
        self::assertSame(1, $http->get($this->url . '/echo')->json()['session'], 'All of it on the one connection.');
    }

    public function testABodyLongerThanAllowedEndsItsStreamAndNotTheConnection(): void
    {
        $http = $this->serve();
        $folder = sys_get_temp_dir() . '/crab-h2-download-' . bin2hex(random_bytes(6));
        mkdir($folder);
        try {
            try {
                $http->request('GET', $this->url . '/big', maxBytes: 1024 * 1024, sink: "{$folder}/big.bin");
                self::fail('A body past its limit was taken whole.');
            } catch (TooLargeException $error) {
                self::assertStringContainsString('with more than 1,048,576 bytes of body', $error->getMessage());
            }
            self::assertSame(['.', '..'], scandir($folder), 'Nothing was left of the download.');

            $big = $http->request('GET', $this->url . '/big', sink: "{$folder}/big.bin");
            self::assertSame([80 * 65_536, 80 * 65_536], [filesize("{$folder}/big.bin"), $big->getBody()->getSize()]);

            $raw = $http->request('GET', $this->url . '/gzip', decode: false);
            self::assertSame(['gzip', '{"compressed":true}'], [
                $raw->getHeaderLine('Content-Encoding'),
                gzdecode((string) $raw->getBody()),
            ]);
            $seen = $http->request('GET', $this->url . '/echo', decode: false)->json();
            self::assertArrayNotHasKey('accept-encoding', $seen['headers']);
            self::assertSame(1, $seen['session'], 'The stream given up did not take the connection with it.');
        } finally {
            array_map(unlink(...), array_filter((array) glob("{$folder}/{,.}*", GLOB_BRACE), is_file(...)));
            rmdir($folder);
        }
    }

    /**
     * Header preparation can also consume the remaining allowance. A
     * first HTTP/2 write after that must leave the request unsent.
     */
    public function testHeadersPreparedAfterTheDeadlineCannotOpenAnHttp2Stream(): void
    {
        $network = null;
        $http = $this->serve(network: $network);
        self::assertSame('2', $http->get($this->url . '/echo')->getProtocolVersion());
        $producer = new BodyProducer();
        $deadline = (hrtime(true) / 1_000_000_000) + 0.05;
        $read = false;
        $request = $this->createStub(RequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getUri')->willReturn(new Request('POST', $this->url . '/late-headers')->getUri());
        $request->method('getBody')->willReturn(new Stream(''));
        $request->method('getHeaders')->willReturn([]);
        $request->method('getHeaderLine')->willReturn('');
        $request
            ->method('getRequestTarget')
            ->willReturnCallback(static function () use ($producer, $deadline, &$read): string {
                $producer->readAfter($deadline, 65_536);
                $read = true;
                return '/late-headers';
            });
        try {
            $network->exchange($request, $deadline, 0.05, null, new Receiving(Client::MAX_BYTES));
            self::fail('Expired headers opened a request stream.');
        } catch (NetworkException $error) {
            self::assertStringContainsString('it took more than 0.05 seconds, and was sending', $error->getMessage());
            self::assertInstanceOf(TimedOut::class, $error->getPrevious());
            self::assertTrue($error->unsent);
        } finally {
            $producer->close();
        }
        self::assertTrue($read, 'The deadline passed while preparing the request headers.');
        // Finish the failed writer's queued connection cleanup before opening another exchange.
        Async::sleep(EventLoop::current(), 0.0);
        $seen = $http->get($this->url . '/seen')->json();
        self::assertArrayNotHasKey('/late-headers', $seen['requests']);
    }

    /**
     * HTTP/2 writes headers before reading a body. Expiry during a pipe
     * read must prevent late DATA while retaining the partial-send result.
     */
    public function testABodyProducedAfterTheDeadlineCannotSendHttp2Data(): void
    {
        $network = null;
        $http = $this->serve(network: $network);
        self::assertSame('2', $http->get($this->url . '/echo')->getProtocolVersion());
        $producer = new BodyProducer();
        $deadline = (hrtime(true) / 1_000_000_000) + 0.05;
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(null);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturnCallback(static fn(): bool => $producer->body->eof());
        $read = false;
        $body->method('read')->willReturnCallback(static function (int $length) use (
            $producer,
            $deadline,
            &$read,
        ): string {
            $read = true;
            return $producer->readAfter($deadline, $length);
        });
        try {
            $network->exchange(
                new Request('POST', $this->url . '/late-body?token=secret#fragment-secret', [], $body),
                $deadline,
                0.05,
                null,
                new Receiving(Client::MAX_BYTES),
            );
            self::fail('Expired body bytes were sent as DATA.');
        } catch (NetworkException $error) {
            self::assertStringContainsString('it took more than 0.05 seconds, and was sending', $error->getMessage());
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertInstanceOf(TimedOut::class, $error->getPrevious());
            self::assertFalse($error->unsent, 'The request headers were already sent before body production.');
        } finally {
            $producer->close();
        }
        self::assertTrue($read, 'The deadline passed while reading the request body.');
        Async::sleep(EventLoop::current(), 0.0);
        $seen = $http->get($this->url . '/seen')->json();
        self::assertSame(1, $seen['requests']['/late-body']);
        self::assertSame(0, $seen['bytes']['/late-body'] ?? 0, 'No late DATA reached the server.');
    }

    public function testWhatTheServerDidNotActOnIsMadeAgainAndTheRestIsReported(): void
    {
        $http = $this->serve();
        $again = $http->post($this->url . '/refuse-once', json: ['pay' => 5])->json();
        self::assertSame(
            ['POST', '{"pay":5}', 2],
            [$again['method'], $again['body'], $again['times']],
            'A request the server refused before acting on it is made again, a POST too.',
        );

        try {
            $http->get($this->url . '/reset');
            self::fail('A request the server gave up on was answered.');
        } catch (NetworkException $error) {
            self::assertStringContainsString(
                '/reset failed: the server gave up on the request, with an internal error.',
                $error->getMessage(),
            );
        }
        try {
            $http->timeout(0.05)->get($this->url . '/slow');
            self::fail('A request that took too long was waited for.');
        } catch (NetworkException $error) {
            self::assertStringContainsString(
                'it took more than 0.05 seconds, and was waiting for the answer when given up',
                $error->getMessage(),
            );
        }
        self::assertSame(1, $http->get($this->url . '/echo')->json()['session'], 'Neither cost the connection.');

        // A server that says it is leaving is left: the next request goes over a new connection.
        self::assertSame('last one here', (string) $http->get($this->url . '/goaway')->getBody());
        self::assertSame(2, $http->get($this->url . '/echo')->json()['session']);

        self::assertSame(
            'after 3 tries',
            (string) $http
                ->retry(3, 0.01)
                ->get($this->url . '/busy-twice')
                ->getBody(),
        );

        // This server speaks HTTP/2 only, so a client told not to offer it finds nothing in common.
        try {
            new Client(tls: ['cafile' => $this->certificate], http2: false)->get($this->url . '/echo');
            self::fail('HTTP/2 was spoken by a client told not to.');
        } catch (NetworkException $error) {
            self::assertStringContainsString('the encrypted connection could not be made', $error->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writerHandoffs(): iterable
    {
        foreach (['before', 'after', 'none'] as $when) {
            yield $when => [$when];
        }
    }

    #[DataProvider('writerHandoffs')]
    public function testWriterHandoffPreservesCancellationAndTheNextCallersTurn(string $when): void
    {
        $this->withWriterSession(function (Http2Session $session, EventLoop $loop) use ($when): void {
            $lock = new \ReflectionMethod($session, 'lock');
            $unlock = new \ReflectionMethod($session, 'unlock');
            $lock->invoke($session, INF, null);
            $cancellation = new Cancellation();
            $seen = [];
            $first = Task::spawn($loop, static function () use (
                $session,
                $lock,
                $unlock,
                $cancellation,
                &$seen,
            ): ?\Throwable {
                try {
                    $lock->invoke($session, (hrtime(true) / 1e9) + 10, $cancellation);
                    try {
                        $seen[] = 'first';
                    } finally {
                        $unlock->invoke($session);
                    }
                } catch (\Throwable $error) {
                    return $error;
                }
                return null;
            });
            $next = Task::spawn($loop, static function () use ($session, $lock, $unlock, &$seen): void {
                $lock->invoke($session, (hrtime(true) / 1e9) + 10, null);
                try {
                    $seen[] = 'next';
                } finally {
                    $unlock->invoke($session);
                }
            });
            Wait::until(
                $loop,
                fn(): bool => count($this->writerState($session, 'writers')) === 2,
                'both writers queued in order',
            );
            if ($when === 'before') {
                $cancellation->cancel();
            }
            $unlock->invoke($session);
            if ($when === 'after') {
                $cancellation->cancel();
            }
            Wait::until(
                $loop,
                static fn(): bool => $first->isComplete() && $next->isComplete(),
                'both writer turns to finish',
            );
            if ($when === 'none') {
                self::assertNull($first->result());
                self::assertSame(['first', 'next'], $seen);
            } else {
                self::assertInstanceOf(CancelledException::class, $first->result());
                self::assertSame(['next'], $seen);
            }
            self::assertNull($next->result());
            self::assertFalse($this->writerState($session, 'writing'));
            self::assertSame([], $this->writerState($session, 'writers'));
        });
    }

    public function testAControlFrameDeadlineEndsItsWriterWaitWithoutClosingTheSession(): void
    {
        $this->withWriterSession(function (Http2Session $session, EventLoop $loop, mixed $peer): void {
            new \ReflectionMethod($session, 'lock')->invoke($session, INF, null);
            $task = Task::spawn($loop, static function () use ($session): ?\Throwable {
                try {
                    new \ReflectionMethod($session, 'write')->invoke(
                        $session,
                        "\0\0\0\x04\x01\0\0\0\0",
                        (hrtime(true) / 1e9) + 0.5,
                    );
                } catch (\Throwable $error) {
                    return $error;
                }
                return null;
            });
            Wait::until(
                $loop,
                fn(): bool => count($this->writerState($session, 'writers')) === 1,
                'the control frame queued for the writer',
            );
            Wait::until($loop, $task->isComplete(...), 'the control frame deadline ending its own wait');
            self::assertInstanceOf(TimedOut::class, $task->result());
            self::assertSame([], $this->writerState($session, 'writers'));
            self::assertTrue($session->accepts());
            self::assertSame('', stream_get_contents($peer));
        });
    }

    public function testAnAbandonedStreamClosesTheSessionIfItsResetCannotBeWritten(): void
    {
        $this->withWriterSession(function (Http2Session $session, EventLoop $loop): void {
            new \ReflectionMethod($session, 'lock')->invoke($session, INF, null);
            $stream = new \Crab\HttpClient\Http2Stream(1, 65_535, false, new Receiving(Client::MAX_BYTES));
            new \ReflectionProperty($session, 'streams')->setValue($session, [1 => $stream]);
            new \ReflectionMethod($session, 'abandon')->invoke($session, $stream);
            Wait::until(
                $loop,
                fn(): bool => count($this->writerState($session, 'writers')) === 1,
                'the abandoned stream reset queued behind the writer',
            );
            // The reset has its own five-second deadline. Observe its timer
            // being removed, with a separate ten-second diagnostic deadline.
            $finished = $loop->getSuspension();
            $watcher = $loop->repeat(0.01, function () use ($session, $finished): void {
                if ($this->writerState($session, 'writers') === []) {
                    $finished->resume();
                }
            });
            $guard = $loop->delay(10, static fn() => $finished->throw(
                new \RuntimeException('The abandoned HTTP/2 reset did not reach its deadline.'),
            ));
            try {
                $finished->suspend();
                $loop->cancel($watcher);
                Async::sleep($loop, 0);
            } finally {
                $loop->cancel($watcher);
                $loop->cancel($guard);
            }
            self::assertFalse(
                $session->accepts(),
                'A reset that could not be sent must not leave the peer holding an abandoned stream.',
            );
        });
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dataWriterEndings(): iterable
    {
        yield 'cancellation' => ['cancel'];
        yield 'deadline' => ['timeout'];
    }

    #[DataProvider('dataWriterEndings')]
    public function testAnAbandonedDataWriterReturnsTheWindowItReservedBeforeWaiting(string $ending): void
    {
        $this->withWriterSession(function (Http2Session $session, EventLoop $loop) use ($ending): void {
            $cancellation = new Cancellation();
            $read = false;
            $body = $this->createStub(StreamInterface::class);
            $body->method('getSize')->willReturn(4);
            $body->method('isSeekable')->willReturn(false);
            $body->method('eof')->willReturnCallback(static function () use (&$read): bool {
                return $read;
            });
            $body->method('read')->willReturnCallback(static function () use ($session, &$read): string {
                $read = true;
                // Another writer owns the turn after this request's HEADERS.
                new \ReflectionMethod($session, 'lock')->invoke($session, INF, null);
                return 'data';
            });
            $task = Task::spawn($loop, static function () use ($session, $body, $cancellation, $ending): ?\Throwable {
                try {
                    $session->exchange(
                        new Request('POST', 'https://writer.test/body', [], $body),
                        (hrtime(true) / 1e9) + ($ending === 'timeout' ? 0.5 : 10),
                        new Receiving(Client::MAX_BYTES),
                        $cancellation,
                    );
                } catch (\Throwable $error) {
                    return $error;
                }
                return null;
            });
            Wait::until(
                $loop,
                fn(): bool => count($this->writerState($session, 'writers')) === 1,
                'the request body queued behind another writer',
            );
            self::assertSame(
                65_531,
                $this->writerState($session, 'window'),
                'Four DATA bytes reserved connection credit.',
            );
            if ($ending === 'cancel') {
                $cancellation->cancel();
                self::assertSame([], $this->writerState($session, 'writers'));
            }
            Wait::until($loop, $task->isComplete(...), 'the abandoned DATA caller leaving its writer wait');
            self::assertInstanceOf($ending === 'cancel' ? CancelledException::class : TimedOut::class, $task->result());
            self::assertSame(
                65_535,
                $this->writerState($session, 'window'),
                'No DATA was written, so another stream must retain the credit.',
            );
            self::assertTrue($session->accepts());
        });
    }

    /**
     * Socket pairs isolate writer ownership from TLS negotiation. A held
     * turn is always released, even when the old implementation fails.
     *
     * @param \Closure(Http2Session, EventLoop, resource): void $check
     */
    private function withWriterSession(\Closure $check): void
    {
        $loop = EventLoop::install(new StreamSelectEventLoop());
        [$socket, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($socket, false);
        stream_set_blocking($peer, false);
        $class = new \ReflectionClass(Connection::class);
        $connection = $class->newInstanceWithoutConstructor();
        $class->getConstructor()->invoke($connection, $loop, $socket);
        $session = new Http2Session($connection, $loop, $connection->close(...));
        try {
            $check($session, $loop, $peer);
        } finally {
            $session->close();
            if ($this->writerState($session, 'writing')) {
                new \ReflectionMethod($session, 'unlock')->invoke($session);
            }
            fclose($peer);
            $loop->run();
        }
        self::assertSame([], $loop->getIdentifiers(), 'Writer cleanup must release timers and watchers.');
    }

    private function writerState(Http2Session $session, string $property): mixed
    {
        return new \ReflectionProperty($session, $property)->getValue($session);
    }

    /**
     * Starts the server and returns a client that trusts it.
     */
    private function serve(int $connections = 8, float $timeout = 10.0, ?Network &$network = null): Client
    {
        $node = trim((string) @shell_exec('command -v node'));
        if ($node === '' || !extension_loaded('openssl')) {
            self::markTestSkipped('Needs Node.js, for an HTTP/2 server, and the openssl extension.');
        }
        $this->certificate = TestServer::certificate('localhost');
        $this->node = proc_open(
            [$node, __DIR__ . '/h2-server.mjs', $this->certificate],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $port = (int) fgets($pipes[1]);
        self::assertGreaterThan(0, $port, 'The HTTP/2 server did not start.');
        $this->url = "https://localhost:{$port}";
        $tls = ['cafile' => $this->certificate];
        $network = new Network(tls: $tls, connections: $connections);
        return $this->clients[] = new Client(
            tls: $tls,
            timeout: $timeout,
            connections: $connections,
            transport: $network,
        );
    }
}
