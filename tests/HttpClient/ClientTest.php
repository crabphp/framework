<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Contracts\Container\Container;
use Crab\Contracts\HttpClient\Client as ClientContract;
use Crab\Contracts\HttpClient\File;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\Network\DnsResolver;
use Crab\Engine\Process\Worker;
use Crab\Engine\Suspension;
use Crab\Http\Request;
use Crab\Http\Stream;
use Crab\HttpClient\Client;
use Crab\HttpClient\ClientException;
use Crab\HttpClient\CookieJar;
use Crab\HttpClient\HttpClientProvider;
use Crab\HttpClient\Network;
use Crab\HttpClient\NetworkException;
use Crab\HttpClient\Receiving;
use Crab\HttpClient\RequestException;
use Crab\HttpClient\Response;
use Crab\HttpClient\ResponseException;
use Crab\HttpClient\TimedOut;
use Crab\HttpClient\Transport;
use Crab\Testing\WorkerHarness;
use Crab\Tests\Support\Wait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Records the deadlines handed to the real network, so sharing a budget
 * can be checked without a narrow window for when a call finishes.
 */
final class DeadlineTransport implements Transport
{
    /**
     * @var list<float>
     */
    public array $deadlines = [];
    /**
     * @var list<float>
     */
    public array $remaining = [];

    public function __construct(
        private readonly Transport $network = new Network(),
    ) {}

    public function exchange(
        RequestInterface $request,
        float $deadline,
        float $timeout,
        ?Cancellation $cancellation,
        Receiving $receiving,
    ): Response {
        $this->deadlines[] = $deadline;
        $this->remaining[] = $deadline - (hrtime(true) / 1_000_000_000);
        return $this->network->exchange($request, $deadline, $timeout, $cancellation, $receiving);
    }

    public function close(): void
    {
        $this->network->close();
    }
}

/**
 * Holds a reply or handshake until the test has observed the caller's
 * deadline or cancellation. Cleanup releases every held callback too.
 */
final class DeadlineBarrier
{
    public bool $entered = false;
    private bool $released = false;
    /**
     * @var list<Suspension>
     */
    private array $waiting = [];

    public function hold(): void
    {
        $this->entered = true;
        if ($this->released) {
            return;
        }
        $suspension = EventLoop::current()->getSuspension();
        $this->waiting[] = $suspension;
        $suspension->suspend();
    }

    public function release(): void
    {
        $this->released = true;
        $waiting = $this->waiting;
        $this->waiting = [];
        foreach ($waiting as $suspension) {
            $suspension->resume();
        }
    }
}

final class ClientTest extends TestCase
{
    /**
     * @var list<TestServer>
     */
    private array $servers = [];
    /**
     * @var list<Client>
     */
    private array $clients = [];
    /**
     * @var list<string>
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        foreach ($this->servers as $server) {
            $server->close();
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        // Lets the loop these tests waited on come to its end, so that the next test may install its own.
        EventLoop::current()->run();
    }

    public function testRequestsAreSentAndAnswersReadOnAKeptConnection(): void
    {
        $server = $this->server(static fn(array $request): string => TestServer::reply(
            $request['target'] === '/missing' ? 404 : 200,
            json_encode(['method' => $request['method'], 'target' => $request['target'], 'body' => $request['body']]),
            ['Content-Type' => 'application/json', 'X-Served' => 'yes'],
        ));
        $http = $this->client(headers: ['X-Api-Key' => 'k', 'Accept' => 'text/plain']);

        $got = $http->get($server->url('/users?sort=name'), query: ['page' => 2, 'tags' => ['a', 'b c']]);
        self::assertSame([200, true, 'yes', 'Reply'], [
            $got->getStatusCode(),
            $got->ok(),
            $got->getHeaderLine('x-served'),
            $got->getReasonPhrase(),
        ]);
        self::assertSame(
            ['method' => 'GET', 'target' => '/users?sort=name&page=2&tags%5B0%5D=a&tags%5B1%5D=b%20c', 'body' => ''],
            $got->json(),
        );
        self::assertSame(['k', 'text/plain', 'Crab', "127.0.0.1:{$server->port}", false], [
            $server->requests[0]['headers']['x-api-key'],
            $server->requests[0]['headers']['accept'],
            $server->requests[0]['headers']['user-agent'],
            $server->requests[0]['headers']['host'],
            isset($server->requests[0]['headers']['content-length']),
        ]);

        $posted = $http->post(
            $server->url('/users'),
            json: ['name' => 'Ada', 'url' => 'https://a.example/ü'],
            headers: ['x-api-key' => 'other'],
        );
        self::assertSame('{"name":"Ada","url":"https://a.example/ü"}', $posted->json()['body']);
        self::assertSame(['application/json', '43', 'other'], [
            $server->requests[1]['headers']['content-type'],
            $server->requests[1]['headers']['content-length'],
            $server->requests[1]['headers']['x-api-key'],
        ]);
        self::assertSame('PUT', $http->put($server->url('/users/1'))->json()['method']);
        self::assertSame(
            ['PATCH', '{"a":1}'],
            array_values(array_intersect_key(
                $http->patch($server->url('/users/1'), json: ['a' => 1])->json(),
                ['method' => 1, 'body' => 1],
            )),
        );
        self::assertSame('/users/1?hard=1', $http->delete($server->url('/users/1'), query: [
            'hard' => 1,
        ])->json()['target']);
        self::assertSame('0', $server->requests[2]['headers']['content-length'], 'A PUT with nothing to send says so.');

        // An error status is an answer, not a failure.
        $missing = $http->get($server->url('/missing'));
        self::assertSame([404, false], [$missing->getStatusCode(), $missing->ok()]);

        $form = $http->request('POST', $server->url('/login'), form: ['user' => 'ada', 'pass' => 'a b']);
        self::assertSame('user=ada&pass=a+b', $form->json()['body']);
        self::assertSame('application/x-www-form-urlencoded', $server->requests[6]['headers']['content-type']);
        $raw = $http->request('post', $server->url('/raw'), body: 'plain text', headers: [
            'Content-Type' => 'text/plain',
        ]);
        self::assertSame(['POST', 'plain text'], [$raw->json()['method'], $raw->json()['body']]);

        self::assertSame(1, $server->connections, 'Every request went over one connection.');
    }

    public function testAClientForOneApiAddsPathsToItsBaseUrl(): void
    {
        $server = $this->server(static fn(array $request): string => TestServer::reply(200, $request['target']));
        $api = $this->client($server->url('/v1/'));
        self::assertSame('/v1/charges', (string) $api->get('/charges')->getBody());
        self::assertSame('/v1/charges?limit=3', (string) $api->get('charges', query: ['limit' => 3])->getBody());
        self::assertSame(
            '/elsewhere',
            (string) $api->get($server->url('/elsewhere'))->getBody(),
            'A whole URL is used as it is.',
        );

        $mistakes = [
            '"/charges" is not a whole URL, and this client has no base URL' => fn() => $this->client()->get(
                '/charges',
            ),
            'A request has one body: give json:, a form (form: and files:) or body:' => static fn() => $api->request(
                'POST',
                '/x',
                json: [],
                body: 'x',
            ),
            'A timeout is a number of seconds above zero' => static fn() => $api->timeout(0),
            'needs timeouts above zero' => static fn() => new Client(timeout: 0),
        ];
        foreach ($mistakes as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        try {
            $this->client()->get('ftp://files.example.com/a');
            self::fail('A request that cannot be sent was sent.');
        } catch (RequestException $error) {
            self::assertInstanceOf(RequestExceptionInterface::class, $error);
            self::assertStringContainsString(
                'GET ftp://files.example.com/a cannot be sent: a request needs an http:// or https:// URL',
                $error->getMessage(),
            );
            self::assertSame('GET', $error->getRequest()->getMethod());
        }
    }

    public function testEveryWayABodyIsFramedIsRead(): void
    {
        $big = str_repeat('0123456789abcdef', 200_000);
        $server = $this->server(static fn(array $request): ?string => match (strtok($request['target'], '?')) {
            '/chunked'
                => "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n1;note=x\r\n \r\n5\r\nworld\r\n0\r\nX-Trailer: t\r\n\r\n",
            '/gzip' => TestServer::reply(200, gzencode('{"compressed":true}'), [
                'Content-Encoding' => 'gzip',
                'Content-Type' => 'application/json',
            ]),
            '/deflate' => TestServer::reply(200, gzcompress('deflated'), ['Content-Encoding' => 'deflate']),
            '/deflate-raw' => TestServer::reply(200, gzdeflate('deflated without its header'), [
                'Content-Encoding' => 'deflate',
            ]),
            '/not-gzip' => TestServer::reply(200, 'plain', ['Content-Encoding' => 'gzip']),
            '/until-closed' => "HTTP/1.0 200 OK\r\nConnection: close\r\n\r\nall there is",
            '/big' => TestServer::reply(200, $big),
            '/nothing' => "HTTP/1.1 204 No Content\r\n\r\n",
            '/interim' => "HTTP/1.1 100 Continue\r\n\r\n" . TestServer::reply(200, 'after'),
            '/folded' => "HTTP/1.1 200 OK\r\nX-Long: one\r\n two\r\nContent-Length: 0\r\n\r\n",
            '/head' => "HTTP/1.1 200 OK\r\nContent-Length: 500\r\n\r\n",
            '/garbage' => "SSH-2.0-OpenSSH\r\n\r\n",
            '/short' => "HTTP/1.1 200 OK\r\nContent-Length: 50\r\nConnection: close\r\n\r\nonly this",
            default => null,
        });
        $http = $this->client();

        self::assertSame('hello world', (string) $http->get($server->url('/chunked'))->getBody());
        $gzip = $http->get($server->url('/gzip'));
        self::assertSame([['compressed' => true], false, false], [
            $gzip->json(),
            $gzip->hasHeader('Content-Encoding'),
            $gzip->hasHeader('Content-Length'),
        ]);
        self::assertSame('gzip, deflate', $server->requests[1]['headers']['accept-encoding']);
        self::assertSame('deflated', (string) $http->get($server->url('/deflate'))->getBody());
        self::assertSame('deflated without its header', (string) $http->get($server->url('/deflate-raw'))->getBody());
        self::assertSame('all there is', (string) $http->get($server->url('/until-closed'))->getBody());
        $large = $http->get($server->url('/big'));
        self::assertSame([strlen($big), md5($big)], [$large->getBody()->getSize(), md5((string) $large->getBody())]);
        self::assertSame([204, ''], [
            $http->get($server->url('/nothing'))->getStatusCode(),
            (string) $http->get($server->url('/nothing'))->getBody(),
        ]);
        self::assertSame('after', (string) $http->get($server->url('/interim'))->getBody());
        self::assertSame('one two', $http->get($server->url('/folded'))->getHeaderLine('X-Long'));
        $head = $http->request('HEAD', $server->url('/head'));
        self::assertSame(['500', ''], [$head->getHeaderLine('Content-Length'), (string) $head->getBody()]);
        self::assertSame(
            'after',
            (string) $http->get($server->url('/interim'))->getBody(),
            'The connection was left where the next answer starts.',
        );

        $failures = [
            '/not-gzip' => 'failed: the answer says it is compressed and is not.',
            '/garbage' => 'failed: the answer is not HTTP/1.1.',
            '/short' => 'failed: the connection was lost in the middle of the answer.',
            '/silent' => 'failed: the server closed the connection without answering.',
        ];
        foreach ($failures as $path => $expected) {
            try {
                $http->request('POST', $server->url($path . '?token=secret'), headers: [
                    'Authorization' => 'Bearer secret',
                ]);
                self::fail("{$path} was taken for an answer.");
            } catch (NetworkException $error) {
                self::assertSame("POST http://127.0.0.1:{$server->port}{$path} {$expected}", $error->getMessage());
                self::assertStringNotContainsString('secret', $error->getMessage());
                self::assertInstanceOf(NetworkExceptionInterface::class, $error);
            }
        }
    }

    public function testTheWorkerGoesOnWhileRequestsWait(): void
    {
        $loop = EventLoop::current();
        $waiting = [];
        $arrived = 0;
        $released = false;
        $expired = false;
        $progress = false;
        $release = static function () use (&$waiting, &$released): void {
            $released = true;
            $pending = $waiting;
            $waiting = [];
            foreach ($pending as $suspension) {
                $suspension->resume();
            }
        };
        $server = $this->server(static function (array $request) use (
            $loop,
            $release,
            &$waiting,
            &$arrived,
            &$released,
            &$progress,
        ): string {
            if (!$released) {
                $suspension = $loop->getSuspension();
                $waiting[] = $suspension;
                if (++$arrived === 3) {
                    // Other loop work must run while all three replies are held.
                    $loop->defer(static function () use ($release, &$progress): void {
                        $progress = true;
                        $release();
                    });
                }
                $suspension->suspend();
            }
            return TestServer::reply(200, $request['target']);
        });
        $http = $this->client();
        $timer = $loop->delay(5.0, static function () use ($release, &$released, &$expired): void {
            if (!$released) {
                $expired = true;
                $release();
            }
        });

        $tasks = [];
        foreach (['/a', '/b', '/c'] as $path) {
            $tasks[] = Task::spawn($loop, static fn(): string => (string) $http->get($server->url($path))->getBody());
        }
        try {
            $answers = array_map(static fn(Task $task): string => $task->await(), $tasks);
        } finally {
            $loop->cancel($timer);
            $release();
        }

        self::assertSame(['/a', '/b', '/c'], $answers);
        self::assertFalse($expired, 'Timed out waiting for three HTTP requests to arrive before releasing any reply.');
        self::assertSame(3, $arrived);
        self::assertTrue($progress, 'A separate loop callback released the waiting replies.');
        self::assertSame(3, $server->connections);

        // With one connection allowed, the second request waits for the first to give it back.
        $one = $this->client(connections: 1);
        $tasks = [
            Task::spawn($loop, static fn(): string => (string) $one->get($server->url('/d'))->getBody()),
            Task::spawn($loop, static fn(): string => (string) $one->get($server->url('/e'))->getBody()),
        ];
        self::assertSame(['/d', '/e'], array_map(static fn(Task $task): string => $task->await(), $tasks));
        self::assertSame(4, $server->connections, 'Both went over the one connection.');
    }

    public function testARequestThatGetsNoAnswerSaysWhy(): void
    {
        $answer = new DeadlineBarrier();
        $cancelled = new DeadlineBarrier();
        $server = $this->server(static function (array $request) use ($answer, $cancelled): string {
            $barrier = $request['target'] === '/cancel' ? $cancelled : $answer;
            $barrier->hold();
            return TestServer::reply(200, 'late');
        });
        $http = $this->client(timeout: 2.0, connectTimeout: 5.0);
        $failure = $this->awaitTimeout(
            $this->requestTask($http, $server->url('/slow')),
            $answer,
            'the unanswered HTTP request',
        );
        self::assertSame(
            "GET http://127.0.0.1:{$server->port}/slow failed: it took more than 2 seconds, and was waiting for the answer when given up.",
            $failure->getMessage(),
        );
        self::assertSame(
            'late',
            (string) $http->timeout(5.0)->get($server->url('/slow'))->getBody(),
            'A timeout copy can still use the transport after the failed call.',
        );
        self::assertSame(
            2,
            $server->connections,
            'The client it was asked of is left as it was, and both use the same connections.',
        );

        $closed = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($closed, false), ':'), 1);
        fclose($closed);
        try {
            $http->get("http://127.0.0.1:{$port}/");
            self::fail('A server that is not there answered.');
        } catch (ClientExceptionInterface $error) {
            self::assertSame(
                "GET http://127.0.0.1:{$port}/ failed: 127.0.0.1 could not be reached: the connection was refused.",
                $error->getMessage(),
            );
        }
        $unresolved = $this->client(
            transport: new Network(resolver: new DnsResolver(servers: [], fallback: false, hostsFile: '/dev/null')),
        );
        try {
            $unresolved->get('http://no-such-host.invalid/');
            self::fail('A name that does not exist was found.');
        } catch (NetworkException $error) {
            self::assertSame(
                'GET http://no-such-host.invalid/ failed: no address was found for the name no-such-host.invalid.',
                $error->getMessage(),
            );
        }

        // Cancelling gives a request up at once, with the cancellation and not as a failure of the network.
        $loop = EventLoop::current();
        $call = $this->requestTask($this->client(timeout: 10.0), $server->url('/cancel'));
        try {
            Wait::until(
                $loop,
                static fn(): bool => $cancelled->entered || $call->isComplete(),
                'the request before cancellation',
            );
            self::assertTrue($cancelled->entered);
            $call->cancel();
            Wait::until($loop, $call->isComplete(...), 'cancellation to finish the held HTTP request');
            self::assertInstanceOf(CancelledException::class, $call->result());
        } finally {
            $call->cancel();
            $cancelled->release();
        }
    }

    public function testARetryPauseDoesNotStartAnotherAttemptAfterTheRequestDeadline(): void
    {
        $loop = EventLoop::current();
        $pauseElapsed = false;
        $timer = null;
        $server = $this->server(static function (array $request, TestServer $server) use (
            $loop,
            &$pauseElapsed,
            &$timer,
        ): string {
            $timer = $loop->delay(5.0, static function () use (&$pauseElapsed): void {
                $pauseElapsed = true;
            });
            return TestServer::reply(count($server->requests) === 1 ? 503 : 200, 'busy');
        });
        $http = $this->client()->timeout(2.0)->retry(1, 10.0);
        $completedBeforePause = false;
        $call = Task::spawn($loop, static function () use (
            $http,
            $server,
            $loop,
            &$pauseElapsed,
            &$timer,
            &$completedBeforePause,
        ): Response {
            try {
                $response = $http->get($server->url('/busy'));
                $completedBeforePause = !$pauseElapsed;
                return $response;
            } finally {
                if ($timer !== null) {
                    $loop->cancel($timer);
                }
            }
        });
        // Completion observers may hold the awaiter after the HTTP call has returned.
        $call->onComplete(static function (): void {
            usleep(200_000);
        });

        $response = $call->await();
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('busy', (string) $response->getBody());
        self::assertSame(['/busy'], array_column($server->requests, 'target'));
        self::assertTrue(
            $completedBeforePause,
            'The HTTP call returns before a timer well inside the shortest jittered pause fires.',
        );
    }

    /**
     * Both an unread request and a partly written one stop at the deadline.
     *
     * @return iterable<string, array{string}>
     */
    public static function bodyPrefixes(): iterable
    {
        yield 'nothing was sent' => [''];
        yield 'a first body chunk was sent' => [str_repeat('early', 13_107) . '!'];
    }

    /**
     * A blocking pipe may return after expiry, but must not authorize
     * a late first write or the remainder of a partly written body.
     */
    #[DataProvider('bodyPrefixes')]
    public function testABodyProducedAfterTheDeadlineCannotBeWritten(string $prefix): void
    {
        $server = $this->server(static fn(array $request): string => TestServer::reply(200, $request['target']));
        $transport = new DeadlineTransport();
        $http = $this->client(transport: $transport);
        self::assertSame('/warm', (string) $http->get($server->url('/warm'))->getBody());
        $before = $server->bytes[1];
        $producer = new BodyProducer();
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willReturn(null);
        $body->method('isSeekable')->willReturn(false);
        $body->method('eof')->willReturnCallback(static fn(): bool => $producer->body->eof());
        $read = false;
        $body->method('read')->willReturnCallback(static function (int $length) use (
            $producer,
            $transport,
            &$prefix,
            &$read,
        ): string {
            if ($prefix !== '') {
                $bytes = $prefix;
                $prefix = '';
                return $bytes;
            }
            $read = true;
            return $producer->readAfter($transport->deadlines[1], $length);
        });
        $unsent = $prefix === '';
        try {
            $http->timeout(2.0)->sendRequest(
                new Request('POST', $server->url('/late?token=secret#fragment-secret'), [], $body),
            );
            self::fail('The expired body was sent.');
        } catch (NetworkException $error) {
            self::assertStringContainsString('it took more than 2 seconds, and was sending', $error->getMessage());
            self::assertStringNotContainsString('secret', $error->getMessage());
            self::assertInstanceOf(TimedOut::class, $error->getPrevious());
            self::assertSame($unsent, $error->unsent);
        } finally {
            $producer->close();
        }
        self::assertTrue($read, 'The exchange reached body production before the child waited out its deadline.');
        self::assertSame('/after', (string) $http->get($server->url('/after'))->getBody());
        self::assertSame(['/warm', '/after'], array_column($server->requests, 'target'));
        if ($unsent) {
            self::assertSame($before, $server->bytes[1], 'Not even a header byte of the late POST was sent.');
        } else {
            self::assertGreaterThan(
                $before,
                $server->bytes[1],
                'The first chunk was sent before the deadline, so this is a partial send.',
            );
        }
    }

    /**
     * Invalid relative URLs still name the path and the correction,
     * without exposing credentials in a query or fragment.
     */
    public function testAnInvalidRelativeUrlIsReportedWithoutItsQueryOrFragment(): void
    {
        foreach ([
            '/charges?token=query-secret#fragment-secret',
            '/charges#fragment-secret?token=query-secret',
            '?token=query-secret',
            '#fragment-secret',
        ] as $url) {
            try {
                $this->client()->get($url);
                self::fail('A relative URL without a base was accepted.');
            } catch (\LogicException $error) {
                $path = str_starts_with($url, '/charges') ? '/charges' : '';
                self::assertStringContainsString('"' . $path . '" is not a whole URL', $error->getMessage());
                self::assertStringNotContainsString('secret', $error->getMessage());
                self::assertStringContainsString('make the client with a base URL', $error->getMessage());
            }
        }
    }

    public function testRetriesThatFitTheWholeCallDeadlineStillSucceed(): void
    {
        $server = $this->server(static fn(array $request, TestServer $server): string => TestServer::reply(
            count($server->requests) <= 3 ? 503 : 200,
            'done',
        ));
        $transport = new DeadlineTransport();
        $http = $this->client(transport: $transport)->timeout(2)->retry(3, 0.01);
        $call = Task::spawn(EventLoop::current(), static fn(): Response => $http->get($server->url('/busy')));

        self::assertSame('done', (string) $call->await()->getBody());
        self::assertCount(4, $server->requests);
        self::assertCount(4, $transport->deadlines);
        self::assertSame(array_fill(0, 4, $transport->deadlines[0]), $transport->deadlines);
    }

    public function testRetriesDoNotMultiplyTheWaitForAServerThatNeverAnswers(): void
    {
        $barrier = new DeadlineBarrier();
        $server = $this->server(static function () use ($barrier): string {
            $barrier->hold();
            return TestServer::reply(200, 'late');
        });
        $transport = new DeadlineTransport();
        $http = $this->client(transport: $transport)->timeout(2.0)->retry(3);
        $call = $this->requestTask($http, $server->url('/silent'));

        $failure = $this->awaitTimeout($call, $barrier, 'the silent server receiving its request');
        self::assertStringContainsString(
            'it took more than 2 seconds, and was waiting for the answer',
            $failure->getMessage(),
        );
        self::assertSame(['/silent'], array_column($server->requests, 'target'));
        self::assertCount(1, $transport->deadlines, 'The expired call does not start a fresh retry.');
        self::assertGreaterThanOrEqual($transport->deadlines[0], hrtime(true) / 1e9);
    }

    public function testARedirectChainAndItsSlowFinalHopShareTheCallDeadline(): void
    {
        $barrier = new DeadlineBarrier();
        $server = $this->server(static function (array $request) use ($barrier): string {
            if ($request['target'] === '/final') {
                $barrier->hold();
                return TestServer::reply(200, 'late');
            }
            return TestServer::reply(302, '', [
                'Location' => $request['target'] === '/first' ? '/second' : '/final',
            ]);
        });
        $transport = new DeadlineTransport();
        $http = $this->client(transport: $transport)->timeout(2.0)->retry(3, 0.0);
        $call = $this->requestTask($http, $server->url('/first'));

        $failure = $this->awaitTimeout($call, $barrier, 'the final redirect hop receiving its request');
        self::assertStringContainsString('/final failed: it took more than 2 seconds', $failure->getMessage());
        self::assertSame(['/first', '/second', '/final'], array_column($server->requests, 'target'));
        self::assertSame(array_fill(0, 3, $transport->deadlines[0]), $transport->deadlines);
        self::assertGreaterThanOrEqual($transport->deadlines[0], hrtime(true) / 1e9);
    }

    public function testALaterTryGetsOnlyTheTimeLeftByTheFirstTry(): void
    {
        $barrier = new DeadlineBarrier();
        $server = $this->server(static function (array $request, TestServer $server) use ($barrier): string {
            if (count($server->requests) === 1) {
                return TestServer::reply(503, 'busy');
            }
            $barrier->hold();
            return TestServer::reply(200, 'late');
        });
        $transport = new DeadlineTransport();
        $http = $this->client(transport: $transport)->timeout(2.0)->retry(1, 0.0);
        $call = $this->requestTask($http, $server->url('/busy'));

        $failure = $this->awaitTimeout($call, $barrier, 'the retry receiving its request');
        self::assertStringContainsString(
            'it took more than 2 seconds, and was waiting for the answer',
            $failure->getMessage(),
        );
        self::assertCount(2, $server->requests);
        self::assertSame([$transport->deadlines[0], $transport->deadlines[0]], $transport->deadlines);
        self::assertGreaterThan(0.0, $transport->remaining[1]);
        self::assertLessThan(
            $transport->remaining[0],
            $transport->remaining[1],
            'The second try gets less time, while keeping the first try\'s absolute deadline.',
        );
        self::assertGreaterThanOrEqual($transport->deadlines[0], hrtime(true) / 1e9);
    }

    public function testTheCallDeadlineAlsoBoundsAnUnfinishedTlsConnection(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('The openssl extension is needed to delay a TLS handshake.');
        }
        $certificate = $this->certificate('localhost');
        $barrier = new DeadlineBarrier();
        $server =
            $this->servers[] = new TestServer(
                static fn(array $request): string => TestServer::reply(200, 'late'),
                $certificate,
                beforeHandshake: $barrier->hold(...),
            );
        $http = $this->client(connectTimeout: 10.0, tls: ['cafile' => $certificate])->timeout(2.0)->retry(3);
        $call = $this->requestTask($http, $server->url('/slow', 'https', 'localhost'));
        $failure = $this->awaitTimeout($call, $barrier, 'the unfinished TLS handshake');

        self::assertStringContainsString('it took more than 2 seconds', $failure->getMessage());
        self::assertTrue($failure->unsent);
        self::assertSame([], $server->requests);
        self::assertSame(1, $server->connections);
    }

    public function testTheConnectTimeoutStillAppliesWhenTheCallHasMoreTime(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('The openssl extension is needed to delay a TLS handshake.');
        }
        $certificate = $this->certificate('localhost');
        $barrier = new DeadlineBarrier();
        $server =
            $this->servers[] = new TestServer(
                static fn(array $request): string => TestServer::reply(200, 'late'),
                $certificate,
                beforeHandshake: $barrier->hold(...),
            );
        $http = $this->client(timeout: 10.0, connectTimeout: 2.0, tls: ['cafile' => $certificate]);
        $call = $this->requestTask($http, $server->url('/slow', 'https', 'localhost'));
        $failure = $this->awaitTimeout($call, $barrier, 'the TLS handshake with a shorter connection budget');

        self::assertStringContainsString('connecting to localhost took more than 2 seconds', $failure->getMessage());
        self::assertTrue($failure->unsent);
        self::assertSame([], $server->requests);
        self::assertSame(1, $server->connections);
    }

    public function testWaitingForAProxyTunnelAlsoUsesTheCallDeadline(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('The openssl extension is needed for an HTTPS request through a proxy.');
        }
        $barrier = new DeadlineBarrier();
        $proxy = $this->server(static function () use ($barrier): string {
            $barrier->hold();
            return '';
        });
        $server = $this->server(static fn(array $request): string => TestServer::reply(200, 'never reached'));
        $http = $this->client(connectTimeout: 10.0, proxy: $proxy->url())->timeout(2.0)->retry(3);
        $call = $this->requestTask($http, $server->url('/private', 'https'));
        $failure = $this->awaitTimeout($call, $barrier, 'the proxy receiving CONNECT');

        self::assertStringContainsString('it took more than 2 seconds', $failure->getMessage());
        self::assertTrue($failure->unsent);
        self::assertSame(['CONNECT'], array_column($proxy->requests, 'method'));
        self::assertSame([], $server->requests);
        self::assertSame(0, $server->connections);
    }

    public function testAWaitingCallerOpensAReplacementWithItsOwnDeadline(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('The openssl extension is needed to delay a TLS handshake.');
        }
        $certificate = $this->certificate('localhost');
        $barrier = new DeadlineBarrier();
        $server =
            $this->servers[] = new TestServer(
                static fn(array $request): string => TestServer::reply(200, $request['target']),
                $certificate,
                beforeHandshake: $barrier->hold(...),
            );
        $transport = new DeadlineTransport(new Network(connections: 1, tls: ['cafile' => $certificate]));
        $http = $this->client(timeout: 10.0, transport: $transport);
        $loop = EventLoop::current();
        $first = $this->requestTask($http->timeout(2.0), $server->url('/expired', 'https', 'localhost'));
        $waiting = null;
        try {
            Wait::until(
                $loop,
                static fn(): bool => $barrier->entered || $first->isComplete(),
                'the first TLS handshake',
            );
            self::assertTrue($barrier->entered);
            $waiting = $this->requestTask($http, $server->url('/waiting', 'https', 'localhost'));
            Wait::until(
                $loop,
                static fn(): bool => count($transport->deadlines) === 2,
                'the waiting call entering the occupied pool',
            );
            self::assertFalse($waiting->isComplete());
            self::assertSame(1, $server->connections);

            $failure = $this->awaitTimeout($first, $barrier, 'the first TLS handshake');
            self::assertStringContainsString('it took more than 2 seconds', $failure->getMessage());
            Wait::until($loop, $waiting->isComplete(...), 'the waiting call to open its replacement connection');
            $response = $waiting->result();
            self::assertInstanceOf(Response::class, $response);
            self::assertSame('/waiting', (string) $response->getBody());
        } finally {
            $first->cancel();
            $waiting?->cancel();
            $barrier->release();
        }

        self::assertSame(['/waiting'], array_column($server->requests, 'target'));
        self::assertSame(
            2,
            $server->connections,
            'The expired handshake is closed; the waiter opens a replacement within its own budget.',
        );
    }

    /**
     * Either the call or the connection may give up first while finding an address.
     *
     * @return iterable<string, array{float, float, string}>
     */
    public static function addressDeadlines(): iterable
    {
        yield 'the whole call ends first' => [
            2.0,
            10.0,
            'it took more than 2 seconds, and was finding the server address',
        ];
        yield 'the connection ends first' => [10.0, 2.0, 'connecting to deadline.invalid took more than 2 seconds'];
    }

    #[DataProvider('addressDeadlines')]
    public function testFindingAnAddressStaysWithinBothDeadlines(
        float $timeout,
        float $connectTimeout,
        string $message,
    ): void {
        $loop = EventLoop::current();
        $nameserver = stream_socket_server('udp://127.0.0.1:0', $code, $reason, STREAM_SERVER_BIND);
        self::assertIsResource($nameserver);
        stream_set_blocking($nameserver, false);
        $address = stream_socket_get_name($nameserver, false);
        $queries = [];
        $barrier = new DeadlineBarrier();
        $reader = $loop->onReadable($nameserver, static function () use ($nameserver, $barrier, &$queries): void {
            $queries[] = stream_socket_recvfrom($nameserver, 65_535);
            $barrier->hold();
        });
        $server = $this->server(static fn(array $request): string => TestServer::reply(200, 'never reached'));
        $resolver = new DnsResolver(servers: [$address], patience: 20.0, rounds: 1, fallback: false);
        $http = $this->client(
            timeout: $timeout,
            transport: new Network(connectTimeout: $connectTimeout, resolver: $resolver),
        );
        $call = $this->requestTask($http, $server->url('/', host: 'deadline.invalid'));
        try {
            $failure = $this->awaitTimeout($call, $barrier, 'the local name server receiving its question');
            self::assertStringContainsString($message, $failure->getMessage());
            self::assertTrue($failure->unsent);
        } finally {
            $loop->cancel($reader);
            fclose($nameserver);
        }
        self::assertCount(1, $queries, 'The request reached the local name server and no retry lookup was sent.');
        self::assertSame([], $server->requests);
        self::assertSame(0, $server->connections);
    }

    public function testCancellationDuringConnectionSetupIsStillTheCallersCancellation(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('The openssl extension is needed to delay a TLS handshake.');
        }
        $certificate = $this->certificate('localhost');
        $barrier = new DeadlineBarrier();
        $server =
            $this->servers[] = new TestServer(
                static fn(array $request): string => TestServer::reply(200, 'never reached'),
                $certificate,
                beforeHandshake: $barrier->hold(...),
            );
        $loop = EventLoop::current();
        $http = $this->client(timeout: 10.0, connectTimeout: 10.0, tls: ['cafile' => $certificate]);
        $call = $this->requestTask($http, $server->url('/', 'https', 'localhost'));
        try {
            Wait::until(
                $loop,
                static fn(): bool => $barrier->entered || $call->isComplete(),
                'the TLS handshake before cancellation',
            );
            self::assertTrue($barrier->entered);
            $call->cancel();
            Wait::until($loop, $call->isComplete(...), 'cancellation to end the held TLS handshake');

            self::assertInstanceOf(CancelledException::class, $call->result());
        } finally {
            $call->cancel();
            $barrier->release();
        }
        self::assertSame([], $server->requests);
        self::assertSame(1, $server->connections);
    }

    public function testAKeptConnectionTheServerClosedIsReplacedAndOnlyASafeRequestIsSentAgain(): void
    {
        $server = $this->server(static fn(array $request): ?string => $request['target'] === '/vanish'
            ? null
            : TestServer::reply(200, $request['method']));
        $network = new Network();
        $http = $this->client(transport: $network);
        self::assertSame('GET', (string) $http->get($server->url('/'))->getBody());
        $pools = new \ReflectionProperty($network, 'pools')->getValue($network);
        $pool = reset($pools);
        $kept = $pool->acquire();
        $pool->release($kept);
        $server->dropConnections();
        Wait::until(
            EventLoop::current(),
            static fn(): bool => !$kept->connection->isReusable(),
            'EOF on the kept HTTP connection',
        );
        self::assertSame(
            'POST',
            (string) $http->post($server->url('/'), json: [])->getBody(),
            'A connection found closed before sending is replaced, whatever the request.',
        );
        self::assertSame(2, $server->connections);

        // Closed by the server as the request arrives: a GET is sent once more on a new connection.
        $flaky = $this->server(static function (array $request, TestServer $server): ?string {
            return count($server->requests) === 2 ? null : TestServer::reply(200, 'answer ' . count($server->requests));
        });
        self::assertSame('answer 1', (string) $http->get($flaky->url('/'))->getBody());
        self::assertSame('answer 3', (string) $http->get($flaky->url('/'))->getBody());
        self::assertSame(2, $flaky->connections);

        // A POST is not: the server may have acted on it.
        $once = $this->server(static function (array $request, TestServer $server): ?string {
            return count($server->requests) === 2 ? null : TestServer::reply(200, 'answer');
        });
        $http->get($once->url('/'));
        try {
            $http->post($once->url('/pay'), json: ['amount' => 5]);
            self::fail('A payment was sent twice.');
        } catch (NetworkException $error) {
            self::assertStringContainsString(
                'the server closed the connection without answering',
                $error->getMessage(),
            );
        }
        self::assertCount(2, $once->requests);
    }

    public function testRedirectsAreFollowedAsABrowserFollowsThem(): void
    {
        $other = $this->server(static fn(array $request): string => TestServer::reply(200, json_encode($request)));
        $server = $this->server(static fn(array $request): string => match (strtok($request['target'], '?')) {
            '/old' => TestServer::reply(301, '', ['Location' => '/new?moved=1']),
            '/folder/relative' => TestServer::reply(302, '', ['Location' => 'sibling']),
            '/see-other' => TestServer::reply(303, '', ['Location' => '/new']),
            '/keep' => TestServer::reply(307, '', ['Location' => '/new']),
            '/away' => TestServer::reply(302, '', ['Location' => $other->url('/landed')]),
            '/loop' => TestServer::reply(302, '', ['Location' => '/loop']),
            '/nowhere' => TestServer::reply(302, 'no location'),
            default => TestServer::reply(200, json_encode($request)),
        });
        $http = $this->client(headers: ['Authorization' => 'Bearer secret']);

        self::assertSame('/new?moved=1', $http->get($server->url('/old'))->json()['target']);
        self::assertSame('/folder/sibling', $http->get($server->url('/folder/relative'))->json()['target']);

        $after = $http->post($server->url('/see-other'), json: ['a' => 1])->json();
        self::assertSame(
            ['GET', '', false],
            [$after['method'], $after['body'], isset($after['headers']['content-type'])],
            'What was posted is fetched, not posted again.',
        );
        $kept = $http->post($server->url('/keep'), json: ['a' => 1])->json();
        self::assertSame(['POST', '{"a":1}', 'Bearer secret'], [
            $kept['method'],
            $kept['body'],
            $kept['headers']['authorization'],
        ]);

        $away = $http->get($server->url('/away'))->json();
        self::assertSame(
            ['/landed', false, "127.0.0.1:{$other->port}"],
            [$away['target'], isset($away['headers']['authorization']), $away['headers']['host']],
            'Another server is not sent what proved who is asking.',
        );
        self::assertSame('no location', (string) $http->get($server->url('/nowhere'))->getBody());

        try {
            $http->get($server->url('/loop'));
            self::fail('A redirect that never ends was followed.');
        } catch (ClientException $error) {
            self::assertSame(
                "GET http://127.0.0.1:{$server->port}/loop was redirected more than 5 times.",
                $error->getMessage(),
            );
        }
        $asIs = $this->client(redirects: 0)->get($server->url('/old'));
        self::assertSame([301, '/new?moved=1'], [$asIs->getStatusCode(), $asIs->getHeaderLine('Location')]);
    }

    /**
     * Covers redirects that turn a POST into a GET and ones that retain
     * its method, followed by either status that normally permits retry.
     *
     * @return array<string, array{int, int, string}>
     */
    public static function redirectedFailures(): array
    {
        return [
            '303 then 429' => [303, 429, 'GET'],
            '303 then 503' => [303, 503, 'GET'],
            '307 then 429' => [307, 429, 'POST'],
            '307 then 503' => [307, 503, 'POST'],
        ];
    }

    #[DataProvider('redirectedFailures')]
    public function testARedirectedPostIsNotSentAgainWhenTheNextHopAsksForARetry(
        int $redirect,
        int $status,
        string $nextMethod,
    ): void {
        $server = $this->server(static fn(array $request): string => (
            $request['target'] === '/charge' ? TestServer::reply($redirect, '', [
                    'Location' => '/receipt',
                ]) : TestServer::reply($status, 'busy after redirect', ['Retry-After' => '0'])
        ));
        $http = $this->client()->retry(2, 0.0);

        $answer = $http->post($server->url('/charge'), json: ['amount' => 500]);

        self::assertSame($status, $answer->getStatusCode());
        self::assertSame('busy after redirect', (string) $answer->getBody());
        self::assertSame(
            ['/charge', '/receipt'],
            array_column($server->requests, 'target'),
            'The original payment and its redirected hop are each sent only once.',
        );
        self::assertSame(['POST', $nextMethod], array_column($server->requests, 'method'));
        self::assertSame('{"amount":500}', $server->requests[0]['body']);
        self::assertSame($nextMethod === 'POST' ? '{"amount":500}' : '', $server->requests[1]['body']);
    }

    public function testARedirectedPostIsNotSentAgainWhenTheNextHopWasNeverSent(): void
    {
        $closed = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($closed, false), ':'), 1);
        fclose($closed);
        $server = $this->server(static fn(array $request): string => TestServer::reply(303, '', [
            'Location' => "http://127.0.0.1:{$port}/receipt",
        ]));
        $http = $this->client()->retry(2, 0.0);

        try {
            $http->post($server->url('/charge'), json: ['amount' => 500]);
            self::fail('The unreachable redirect should report its network failure.');
        } catch (NetworkException $error) {
            self::assertTrue($error->unsent, 'The redirected hop was not sent, but the payment already was.');
            self::assertSame('GET', $error->getRequest()->getMethod());
            self::assertStringContainsString('the connection was refused', $error->getMessage());
        }
        self::assertSame(
            ['/charge'],
            array_column($server->requests, 'target'),
            'A failure before sending the next hop must not repeat the original POST.',
        );
    }

    public function testASafeRedirectedRequestCanStillBeRetried(): void
    {
        $receipts = 0;
        $server = $this->server(static function (array $request) use (&$receipts): string {
            if ($request['target'] === '/order') {
                return TestServer::reply(303, '', ['Location' => '/receipt']);
            }
            ++$receipts;
            return TestServer::reply($receipts === 1 ? 503 : 200, 'receipt', ['Retry-After' => '0']);
        });
        $http = $this->client()->retry(1, 0.0);

        self::assertSame(200, $http->get($server->url('/order'))->getStatusCode());
        self::assertSame(['/order', '/receipt', '/order', '/receipt'], array_column($server->requests, 'target'));
        self::assertSame(['GET', 'GET', 'GET', 'GET'], array_column($server->requests, 'method'));
    }

    public function testItIsAPsr18ClientAndSendsABodyOfUnknownLengthInChunks(): void
    {
        $server = $this->server(static fn(array $request): string => TestServer::reply(200, json_encode($request)));
        $http = $this->client();
        self::assertInstanceOf(ClientInterface::class, $http);
        self::assertInstanceOf(ClientContract::class, $http);

        // A pipe: nobody knows how much will come out of it.
        $read = popen(
            escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo str_repeat("streamed ", 20000);'),
            'r',
        );
        self::assertNull(new Stream($read)->getSize());
        $answer = $http->sendRequest(
            new Request('PUT', $server->url('/files/1'), ['Content-Type' => 'text/plain'], new Stream($read)),
        );
        $seen = json_decode((string) $answer->getBody(), true);
        self::assertSame(['PUT', 'chunked', 180_000, false], [
            $seen['method'],
            $seen['headers']['transfer-encoding'],
            strlen($seen['body']),
            isset($seen['headers']['content-length']),
        ]);

        $file = fopen('php://temp', 'w+b');
        fwrite($file, str_repeat('x', 300_000));
        $sized = json_decode(
            (string) $http->sendRequest(new Request('POST', $server->url('/files'), [], new Stream($file)))->getBody(),
            true,
        );
        self::assertSame(['300000', 300_000], [$sized['headers']['content-length'], strlen($sized['body'])]);
    }

    public function testAnEncryptedConnectionIsVerifiedAndUsed(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('The openssl extension is not loaded.');
        }
        $certificate = $this->certificate('localhost');
        $server = $this->server(static fn(array $request): string => TestServer::reply(
            200,
            'over tls ' . $request['target'],
        ), $certificate);

        $trusting = $this->client(tls: ['cafile' => $certificate]);
        self::assertSame('over tls /a', (string) $trusting->get($server->url('/a', 'https', 'localhost'))->getBody());
        self::assertSame('over tls /b', (string) $trusting->get($server->url('/b', 'https', 'localhost'))->getBody());
        self::assertSame(1, $server->connections, 'An encrypted connection is kept too.');

        try {
            $this->client()->get($server->url('/', 'https', 'localhost'));
            self::fail('A certificate nobody vouches for was accepted.');
        } catch (NetworkException $error) {
            self::assertStringContainsString(
                'localhost could not be reached: the encrypted connection could not be made',
                $error->getMessage(),
            );
        }
        try {
            $trusting->get($server->url('/', 'https'));
            self::fail('A certificate for another name was accepted.');
        } catch (NetworkException $error) {
            self::assertStringContainsString('the encrypted connection could not be made', $error->getMessage());
        }
        self::assertSame(
            'over tls /c',
            (string) $this->client(tls: ['verify_peer' => false, 'verify_peer_name' => false])->get($server->url(
                '/c',
                'https',
            ))->getBody(),
        );
    }

    public function testAnErrorStatusIsThrownWhenAskedTo(): void
    {
        $server = $this->server(static fn(array $request): string => TestServer::reply(
            str_starts_with($request['target'], '/gone') ? 410 : 200,
            'body',
        ));
        $http = $this->client();
        self::assertSame('body', (string) $http->get($server->url('/here'))->throw()->getBody());
        try {
            $http->get($server->url('/gone'), query: ['token' => 'secret'])->throw();
            self::fail('An error status passed for a success.');
        } catch (ResponseException $error) {
            self::assertSame(
                "GET http://127.0.0.1:{$server->port}/gone was answered with 410 Reply.",
                $error->getMessage(),
            );
            self::assertSame('body', (string) $error->response->getBody());
            self::assertInstanceOf(ClientExceptionInterface::class, $error);
        }
    }

    public function testFilesAreSentAsAFormAndAReceivedUploadIsPassedOn(): void
    {
        $server = $this->server(static function (array $request): string {
            // Read as the framework reads an upload it receives.
            $received = new Request(
                'POST',
                '/',
                ['Content-Type' => $request['headers']['content-type']],
                $request['body'],
            );
            $files = [];
            $tree = $received->getUploadedFiles();
            array_walk_recursive($tree, static function ($file) use (&$files): void {
                $files[] = [
                    $file->getClientFilename(),
                    $file->getClientMediaType(),
                    $file->getSize(),
                    md5((string) $file->getStream()),
                ];
            });
            return TestServer::reply(200, json_encode([
                'fields' => $received->getParsedBody(),
                'names' => array_keys($received->getUploadedFiles()),
                'files' => $files,
                'length' => $request['headers']['content-length'],
            ]));
        });
        $http = $this->client();
        $path = sys_get_temp_dir() . '/crab-send-' . bin2hex(random_bytes(6)) . '.bin';
        $this->files[] = $path;
        $content = random_bytes(300_000);
        file_put_contents($path, $content);
        $upload = new Request(
            'POST',
            '/',
            ['Content-Type' => 'multipart/form-data; boundary=B'],
            "--B\r\nContent-Disposition: form-data; name=\"f\"; filename=\"given.txt\"\r\nContent-Type: text/plain\r\n\r\nreceived earlier\r\n--B--\r\n",
        )->file('f');

        $seen = $http->post(
            $server->url('/upload'),
            form: ['title' => 'Q3 "final"', 'tags' => ['a', 'b'], 'draft' => false],
            files: [
                'report' => File::fromPath($path, type: 'application/pdf'),
                'note' => File::fromContents("line one\r\n--not a boundary", 'my "note".txt', 'text/plain'),
                'passed' => $upload,
                'photos' => [
                    File::fromContents('one', '1.png', 'image/png'),
                    File::fromContents('two', '2.png', 'image/png'),
                ],
            ],
        )->json();

        self::assertSame(['title' => 'Q3 "final"', 'tags' => ['a', 'b'], 'draft' => '0'], $seen['fields']);
        self::assertSame(['report', 'note', 'passed', 'photos'], $seen['names']);
        self::assertSame(
            [
                [basename($path),  'application/pdf', 300_000, md5($content)],
                ['my %22note%22.txt', 'text/plain',      26,      md5("line one\r\n--not a boundary")],
                ['given.txt',         'text/plain',      16,      md5('received earlier')],
                ['1.png',             'image/png',       3,       md5('one')],
                ['2.png',             'image/png',       3,       md5('two')],
            ],
            $seen['files'],
        );
        self::assertGreaterThan(300_000, (int) $seen['length'], 'Its length is known, so it is not sent in chunks.');

        $mistakes = [
            'files: takes File::fromPath(), File::fromContents() or an uploaded file for "f", not string' =>
                static fn() => $http->post($server->url(), files: ['f' => '/etc/hosts']),
            'The file to send cannot be read: /no/such/file' => static fn() => $http->post($server->url(), files: [
                'f' => File::fromPath('/no/such/file'),
            ]),
            'A request has one body' => static fn() => $http->post(
                $server->url(),
                json: ['a' => 1],
                files: ['f' => File::fromContents('x', 'x')],
            ),
        ];
        foreach ($mistakes as $expected => $mistake) {
            $caught = null;
            try {
                $mistake();
            } catch (\LogicException|\RuntimeException $error) {
                $caught = $error;
            }
            self::assertNotNull($caught, "Not refused: {$expected}");
            self::assertStringContainsString($expected, $caught->getMessage());
        }
    }

    public function testCookiesAreKeptOnlyWhereThereIsAJar(): void
    {
        $server = $this->server(static fn(array $request): string => match (strtok($request['target'], '?')) {
            '/login'
                => "HTTP/1.1 302 Found\r\nLocation: /account\r\nSet-Cookie: session=abc; Path=/; HttpOnly\r\nSet-Cookie: theme=dark; Path=/account\r\nContent-Length: 0\r\n\r\n",
            '/logout' => TestServer::reply(200, '', ['Set-Cookie' => 'session=; Max-Age=0; Path=/']),
            default => TestServer::reply(200, $request['headers']['cookie'] ?? 'no cookie'),
        });
        $http = $this->client();
        $http->post($server->url('/login'));
        self::assertSame(
            'no cookie',
            (string) $http->get($server->url('/account'))->getBody(),
            'A client keeps none by itself.',
        );

        $jar = new CookieJar();
        $session = $http->cookies($jar);
        self::assertSame(
            'theme=dark; session=abc',
            (string) $session->post($server->url('/login'))->getBody(),
            'A cookie set by a redirect goes to where it points.',
        );
        self::assertSame('session=abc', (string) $session->get($server->url('/orders'))->getBody());
        self::assertSame('mine=1; session=abc', (string) $session->get($server->url('/orders'), headers: [
            'Cookie' => 'mine=1',
        ])->getBody());
        self::assertSame(
            'no cookie',
            (string) $http->get($server->url('/orders'))->getBody(),
            'The client it was made from still keeps none.',
        );
        self::assertSame(
            'no cookie',
            (string) $http->cookies(new CookieJar())->get($server->url('/orders'))->getBody(),
            'Another jar is another caller.',
        );
        $session->get($server->url('/logout'));
        self::assertSame(
            'no cookie',
            (string) $session->get($server->url('/orders'))->getBody(),
            'A cookie the server took back is gone.',
        );
    }

    public function testRequestsGoThroughAProxyWhenThereIsOne(): void
    {
        // What is not encrypted is handed to the proxy whole, to pass on.
        $proxy = $this->server(static fn(array $request): string => TestServer::reply(200, json_encode($request)));
        $through = $this->client(proxy: $proxy->url('', 'http', 'user:p%40ss@127.0.0.1'));
        $seen = $through->get('http://api.example.test/users', query: ['page' => 2])->json();
        self::assertSame([
            'http://api.example.test/users?page=2',
            'api.example.test',
            'Basic ' . base64_encode('user:p@ss'),
        ], [$seen['target'], $seen['headers']['host'], $seen['headers']['proxy-authorization']]);
        self::assertSame(
            'http://api.example.test:8080/',
            $through->get('http://api.example.test:8080/')->json()['target'],
        );

        try {
            new Client(proxy: 'socks5://127.0.0.1:1080');
            self::fail('A proxy of a kind that is not supported was taken.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('A proxy is given as "http://host:port"', $error->getMessage());
        }

        if (!extension_loaded('openssl')) {
            return;
        }
        // What is encrypted goes through a tunnel the proxy makes and cannot read.
        $certificate = $this->certificate('localhost');
        $server = $this->server(static fn(array $request): string => TestServer::reply(
            200,
            'tunnelled ' . $request['target'] . ' ' . ($request['headers']['proxy-authorization'] ?? 'unseen'),
        ), $certificate);
        $tunnel = new TestTunnel();
        $refusing = new TestTunnel('HTTP/1.1 407 Proxy Authentication Required');
        try {
            $secure = $this->client(proxy: $tunnel->url('user:secret'), tls: ['cafile' => $certificate]);
            self::assertSame(
                'tunnelled /a unseen',
                (string) $secure->get($server->url('/a', 'https', 'localhost'))->getBody(),
            );
            self::assertSame(
                'tunnelled /b unseen',
                (string) $secure->get($server->url('/b', 'https', 'localhost'))->getBody(),
            );
            self::assertSame(
                [["CONNECT localhost:{$server->port} HTTP/1.1", 'Basic ' . base64_encode('user:secret')]],
                array_map(static fn(array $asked): array => [
                    $asked['line'],
                    $asked['headers']['proxy-authorization'],
                ], $tunnel->asked),
                'One tunnel carried both.',
            );

            try {
                $this->client(proxy: $refusing->url(), tls: ['cafile' => $certificate])->get($server->url(
                    '/',
                    'https',
                    'localhost',
                ));
                self::fail('A proxy that refused was passed through.');
            } catch (NetworkException $error) {
                self::assertStringContainsString(
                    'the proxy would not connect to localhost: it answered "HTTP/1.1 407 Proxy Authentication Required"',
                    $error->getMessage(),
                );
            }
        } finally {
            $tunnel->close();
            $refusing->close();
        }
    }

    public function testWaitingForAConnectionEndsWithTheRequestsOwnTimeout(): void
    {
        $barrier = new DeadlineBarrier();
        $server = $this->server(static function () use ($barrier): string {
            $barrier->hold();
            return TestServer::reply(200, 'slow');
        });
        $http = $this->client(connections: 1);
        $loop = EventLoop::current();
        $first = $this->requestTask($http, $server->url('/'));
        try {
            Wait::until(
                $loop,
                static fn(): bool => $barrier->entered || $first->isComplete(),
                'the first request holding the only HTTP connection',
            );
            self::assertTrue($barrier->entered);
            $waiting = $this->requestTask($http->timeout(2.0), $server->url('/'));
            $failure = $this->awaitTimeout($waiting, $barrier, 'the occupied HTTP connection');
            self::assertStringContainsString(
                'it took more than 2 seconds, and every one of the 1 connections to 127.0.0.1 was still busy',
                $failure->getMessage(),
            );
            self::assertCount(1, $server->requests);
            Wait::until($loop, $first->isComplete(...), 'the released first HTTP request to finish');
            $response = $first->result();
            self::assertInstanceOf(Response::class, $response);
            self::assertSame('slow', (string) $response->getBody());
        } finally {
            $first->cancel();
            $barrier->release();
        }
    }

    public function testARequestWhoseDeadlinePassesWhileConnectingIsNeverSent(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('The openssl extension is needed to delay a TLS handshake.');
        }
        $certificate = $this->certificate('localhost');
        $barrier = new DeadlineBarrier();
        $server =
            $this->servers[] = new TestServer(
                static fn(array $request): string => TestServer::reply(200, $request['target']),
                $certificate,
                beforeHandshake: $barrier->hold(...),
            );
        $http = $this->client(timeout: 2.0, connectTimeout: 10.0, connections: 1, tls: ['cafile' => $certificate]);
        $call = $this->requestTask($http, $server->url('/expired', 'https', 'localhost'), 'POST', ['amount' => 500]);
        $failure = $this->awaitTimeout($call, $barrier, 'the POST connection before any request bytes are sent');

        // The successful follow-up below also confirms that no late request arrived.
        self::assertSame(
            [],
            $server->requests,
            'Finishing a connection after the request deadline must not send the request.',
        );
        self::assertStringContainsString('it took more than 2 seconds', $failure->getMessage());
        self::assertTrue($failure->unsent);

        self::assertSame(
            '/after',
            (string) $http
                ->timeout(10.0)
                ->get($server->url('/after', 'https', 'localhost'))
                ->getBody(),
            'Giving up the expired request must leave the connection pool usable.',
        );
        self::assertSame(['/after'], array_column($server->requests, 'target'));
        self::assertSame(
            2,
            $server->connections,
            'The expired handshake is closed; a later request opens a replacement.',
        );
    }

    public function testARequestWhoseDeadlinePassesAfterAPoolSlotIsHandedOverIsNeverSent(): void
    {
        $barrier = new DeadlineBarrier();
        $server = $this->server(static function (array $request) use ($barrier): string {
            if ($request['target'] === '/first') {
                $barrier->hold();
            }
            return TestServer::reply(200, $request['target']);
        });
        $transport = new DeadlineTransport(new Network(connections: 1));
        $http = $this->client(transport: $transport, timeout: 10.0);
        $loop = EventLoop::current();
        $first = $this->requestTask($http, $server->url('/first'));
        $waiting = null;
        $heldAfterHandoff = false;
        try {
            Wait::until(
                $loop,
                static fn(): bool => $barrier->entered || $first->isComplete(),
                'the first request holding the pool slot',
            );
            self::assertTrue($barrier->entered);
            $waiting = $this->requestTask($http->timeout(2.0), $server->url('/expired'), 'POST', ['amount' => 500]);
            Wait::until(
                $loop,
                static fn(): bool => count($transport->deadlines) === 2,
                'the POST waiting for the occupied pool slot',
            );
            self::assertFalse($waiting->isComplete());
            // Pool release queues the waiter before this callback runs. Hold the
            // worker past that caller's recorded deadline before its fiber resumes.
            $first->onComplete(static function () use ($transport, &$heldAfterHandoff): void {
                $heldAfterHandoff = true;
                while ((hrtime(true) / 1e9) <= $transport->deadlines[1]) {
                    usleep(1000);
                }
            });
            $barrier->release();

            $response = $first->await();
            self::assertInstanceOf(Response::class, $response);
            self::assertSame('/first', (string) $response->getBody());
            Wait::until($loop, $waiting->isComplete(...), 'the handed-over POST to finish');
            $failure = $waiting->result();
        } finally {
            $first->cancel();
            $waiting?->cancel();
            $barrier->release();
        }

        self::assertTrue($heldAfterHandoff);
        self::assertSame(
            ['/first'],
            array_column($server->requests, 'target'),
            'A queued fiber must recheck its deadline after receiving the pooled connection.',
        );
        self::assertInstanceOf(NetworkException::class, $failure);
        self::assertStringContainsString('it took more than 2 seconds', $failure->getMessage());
        self::assertTrue($failure->unsent);
        self::assertSame('/after', (string) $http->get($server->url('/after'))->getBody());
        self::assertSame(['/first', '/after'], array_column($server->requests, 'target'));
    }

    public function testTheProviderBindsOneClientPerWorkerFromConfiguration(): void
    {
        $container = new \Crab\Container\Container();
        $config = new \Crab\Config\Config(['http_client' => ['headers' => ['X-App' => 'shop'], 'connections' => 2]]);
        $container->instance(\Crab\Contracts\Config\Config::class, $config);
        $answer = WorkerHarness::run(
            [new HttpClientProvider()],
            static function (Worker $worker, Container $container): string {
                // Made here, on the worker's own event loop.
                $server = new TestServer(static fn(array $request): string => TestServer::reply(
                    200,
                    $request['target'] . ' ' . ($request['headers']['x-app'] ?? ''),
                ));
                $http = $container->get(ClientContract::class);
                self::assertSame($http, $container->get(ClientInterface::class));
                self::assertSame($http, $container->get(Client::class));
                try {
                    return (string) $http->get($server->url('/orders'))->getBody();
                } finally {
                    $server->close();
                }
            },
            $container,
        );
        self::assertSame('/orders shop', $answer);
        self::assertSame([30.0, 5.0, 5, ''], [
            $config->get('http_client.timeout'),
            $config->get('http_client.connect_timeout'),
            $config->get('http_client.redirects'),
            $config->get('http_client.base_url'),
        ]);
    }

    public function testTheProviderUsesTheApplicationClockForRetryDates(): void
    {
        $app = new \Crab\App(new \Crab\Config\Config([
            'http_client' => ['timeout' => 2.0, 'retries' => 1],
        ]));
        $app->container->instance(
            \Crab\Contracts\Clock\Clock::class,
            new \Crab\Testing\FrozenClock('2001-02-03T04:05:06Z'),
        );
        $app->register(HttpClientProvider::class);
        $server = $this->server(static fn(): string => TestServer::reply(429, 'slow down', [
            'Retry-After' => 'Sat, 03 Feb 2001 04:05:16 GMT',
        ]));
        $http = $this->clients[] = $app->container->get(ClientContract::class);

        $response = $http->retry(1, 0.0)->get($server->url());

        self::assertSame(429, $response->getStatusCode());
        self::assertCount(
            1,
            $server->requests,
            'The provider passes the frozen clock, so the ten-second wait cannot fit.',
        );
    }

    /**
     * Keeps cancellation available for cleanup if an expected stage never arrives.
     */
    private function requestTask(Client $http, string $url, string $method = 'GET', mixed $json = null): Task
    {
        return Task::spawn(EventLoop::current(), static function (Cancellation $cancellation) use (
            $http,
            $url,
            $method,
            $json,
        ): Response|\Throwable {
            try {
                return $http->request($method, $url, json: $json, cancellation: $cancellation);
            } catch (\Throwable $error) {
                return $error;
            }
        });
    }

    /**
     * Checks that a call reached the held stage before its deadline expired.
     * A separate five-second wait diagnoses a missing timeout, without measuring throughput.
     */
    private function awaitTimeout(Task $call, DeadlineBarrier $barrier, string $stage): NetworkException
    {
        $loop = EventLoop::current();
        try {
            Wait::until($loop, static fn(): bool => $barrier->entered || $call->isComplete(), $stage);
            self::assertTrue($barrier->entered, 'The call must reach ' . $stage . ' before its deadline.');
            Wait::until($loop, $call->isComplete(...), 'the call deadline while holding ' . $stage);
            $failure = $call->result();
            self::assertInstanceOf(NetworkException::class, $failure);
            return $failure;
        } finally {
            $call->cancel();
            $barrier->release();
        }
    }

    private function server(\Closure $reply, ?string $certificate = null): TestServer
    {
        return $this->servers[] = new TestServer($reply, $certificate);
    }

    private function client(mixed ...$options): Client
    {
        return $this->clients[] = new Client(...$options);
    }

    private function certificate(string $name): string
    {
        return $this->files[] = TestServer::certificate($name);
    }
}
