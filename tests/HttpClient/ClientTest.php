<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Contracts\Container\Container;
use Crab\Contracts\HttpClient\Client as ClientContract;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\Process\Worker;
use Crab\Http\Request;
use Crab\Http\Stream;
use Crab\HttpClient\Client;
use Crab\Contracts\HttpClient\File;
use Crab\HttpClient\ClientException;
use Crab\HttpClient\CookieJar;
use Crab\HttpClient\ResponseException;
use Crab\HttpClient\HttpClientProvider;
use Crab\HttpClient\NetworkException;
use Crab\HttpClient\RequestException;
use Crab\Testing\WorkerHarness;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;

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
        $server = $this->server(static fn (array $request): string => TestServer::reply(
            $request['target'] === '/missing' ? 404 : 200,
            json_encode(['method' => $request['method'], 'target' => $request['target'], 'body' => $request['body']]),
            ['Content-Type' => 'application/json', 'X-Served' => 'yes'],
        ));
        $http = $this->client(headers: ['X-Api-Key' => 'k', 'Accept' => 'text/plain']);

        $got = $http->get($server->url('/users?sort=name'), query: ['page' => 2, 'tags' => ['a', 'b c']]);
        self::assertSame([200, true, 'yes', 'Reply'], [$got->getStatusCode(), $got->ok(), $got->getHeaderLine('x-served'), $got->getReasonPhrase()]);
        self::assertSame(['method' => 'GET', 'target' => '/users?sort=name&page=2&tags%5B0%5D=a&tags%5B1%5D=b%20c', 'body' => ''], $got->json());
        self::assertSame(['k', 'text/plain', 'Crab', "127.0.0.1:{$server->port}", false], [
            $server->requests[0]['headers']['x-api-key'], $server->requests[0]['headers']['accept'], $server->requests[0]['headers']['user-agent'],
            $server->requests[0]['headers']['host'], isset($server->requests[0]['headers']['content-length']),
        ]);

        $posted = $http->post($server->url('/users'), json: ['name' => 'Ada', 'url' => 'https://a.example/ü'], headers: ['x-api-key' => 'other']);
        self::assertSame('{"name":"Ada","url":"https://a.example/ü"}', $posted->json()['body']);
        self::assertSame(['application/json', '43', 'other'], [
            $server->requests[1]['headers']['content-type'], $server->requests[1]['headers']['content-length'], $server->requests[1]['headers']['x-api-key'],
        ]);
        self::assertSame('PUT', $http->put($server->url('/users/1'))->json()['method']);
        self::assertSame(['PATCH', '{"a":1}'], array_values(array_intersect_key($http->patch($server->url('/users/1'), json: ['a' => 1])->json(), ['method' => 1, 'body' => 1])));
        self::assertSame('/users/1?hard=1', $http->delete($server->url('/users/1'), query: ['hard' => 1])->json()['target']);
        self::assertSame('0', $server->requests[2]['headers']['content-length'], 'A PUT with nothing to send says so.');

        // An error status is an answer, not a failure.
        $missing = $http->get($server->url('/missing'));
        self::assertSame([404, false], [$missing->getStatusCode(), $missing->ok()]);

        $form = $http->request('POST', $server->url('/login'), form: ['user' => 'ada', 'pass' => 'a b']);
        self::assertSame('user=ada&pass=a+b', $form->json()['body']);
        self::assertSame('application/x-www-form-urlencoded', $server->requests[6]['headers']['content-type']);
        $raw = $http->request('post', $server->url('/raw'), body: 'plain text', headers: ['Content-Type' => 'text/plain']);
        self::assertSame(['POST', 'plain text'], [$raw->json()['method'], $raw->json()['body']]);

        self::assertSame(1, $server->connections, 'Every request went over one connection.');
    }

    public function testAClientForOneApiAddsPathsToItsBaseUrl(): void
    {
        $server = $this->server(static fn (array $request): string => TestServer::reply(200, $request['target']));
        $api = $this->client($server->url('/v1/'));
        self::assertSame('/v1/charges', (string) $api->get('/charges')->getBody());
        self::assertSame('/v1/charges?limit=3', (string) $api->get('charges', query: ['limit' => 3])->getBody());
        self::assertSame('/elsewhere', (string) $api->get($server->url('/elsewhere'))->getBody(), 'A whole URL is used as it is.');

        $mistakes = [
            '"/charges" is not a whole URL, and this client has no base URL' => fn () => $this->client()->get('/charges'),
            'A request has one body: give json:, a form (form: and files:) or body:' => fn () => $api->request('POST', '/x', json: [], body: 'x'),
            'A timeout is a number of seconds above zero' => fn () => $api->timeout(0),
            'needs timeouts above zero' => static fn () => new Client(timeout: 0),
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
            self::assertStringContainsString('GET ftp://files.example.com/a cannot be sent: a request needs an http:// or https:// URL', $error->getMessage());
            self::assertSame('GET', $error->getRequest()->getMethod());
        }
    }

    public function testEveryWayABodyIsFramedIsRead(): void
    {
        $big = str_repeat('0123456789abcdef', 200000);
        $server = $this->server(static fn (array $request): ?string => match (strtok($request['target'], '?')) {
            '/chunked' => "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n1;note=x\r\n \r\n5\r\nworld\r\n0\r\nX-Trailer: t\r\n\r\n",
            '/gzip' => TestServer::reply(200, gzencode('{"compressed":true}'), ['Content-Encoding' => 'gzip', 'Content-Type' => 'application/json']),
            '/deflate' => TestServer::reply(200, gzcompress('deflated'), ['Content-Encoding' => 'deflate']),
            '/deflate-raw' => TestServer::reply(200, gzdeflate('deflated without its header'), ['Content-Encoding' => 'deflate']),
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
        self::assertSame([['compressed' => true], false, false], [$gzip->json(), $gzip->hasHeader('Content-Encoding'), $gzip->hasHeader('Content-Length')]);
        self::assertSame('gzip, deflate', $server->requests[1]['headers']['accept-encoding']);
        self::assertSame('deflated', (string) $http->get($server->url('/deflate'))->getBody());
        self::assertSame('deflated without its header', (string) $http->get($server->url('/deflate-raw'))->getBody());
        self::assertSame('all there is', (string) $http->get($server->url('/until-closed'))->getBody());
        $large = $http->get($server->url('/big'));
        self::assertSame([strlen($big), md5($big)], [$large->getBody()->getSize(), md5((string) $large->getBody())]);
        self::assertSame([204, ''], [$http->get($server->url('/nothing'))->getStatusCode(), (string) $http->get($server->url('/nothing'))->getBody()]);
        self::assertSame('after', (string) $http->get($server->url('/interim'))->getBody());
        self::assertSame('one two', $http->get($server->url('/folded'))->getHeaderLine('X-Long'));
        $head = $http->request('HEAD', $server->url('/head'));
        self::assertSame(['500', ''], [$head->getHeaderLine('Content-Length'), (string) $head->getBody()]);
        self::assertSame('after', (string) $http->get($server->url('/interim'))->getBody(), 'The connection was left where the next answer starts.');

        $failures = [
            '/not-gzip' => 'failed: the answer says it is compressed and is not.',
            '/garbage' => 'failed: the answer is not HTTP/1.1.',
            '/short' => 'failed: the connection was lost in the middle of the answer.',
            '/silent' => 'failed: the server closed the connection without answering.',
        ];
        foreach ($failures as $path => $expected) {
            try {
                $http->request('POST', $server->url($path . '?token=secret'), headers: ['Authorization' => 'Bearer secret']);
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
        $server = $this->server(static fn (array $request): array => [TestServer::reply(200, $request['target']), 0.15]);
        $http = $this->client();
        $loop = EventLoop::current();
        $ticks = 0;
        $timer = $loop->repeat(0.01, static function () use (&$ticks): void {
            ++$ticks;
        });

        $started = hrtime(true);
        $tasks = [];
        foreach (['/a', '/b', '/c'] as $path) {
            $tasks[] = Task::spawn($loop, static fn (): string => (string) $http->get($server->url($path))->getBody());
        }
        $answers = array_map(static fn (Task $task): string => $task->await(), $tasks);
        $took = (hrtime(true) - $started) / 1e9;
        $loop->cancel($timer);

        self::assertSame(['/a', '/b', '/c'], $answers);
        self::assertLessThan(0.35, $took, 'Three requests of 0.15 seconds each were waited for together, not one after another.');
        self::assertGreaterThan(5, $ticks, 'A timer went on firing while they waited.');
        self::assertSame(3, $server->connections);

        // With one connection allowed, the second request waits for the first to give it back.
        $one = $this->client(connections: 1);
        $started = hrtime(true);
        $tasks = [
            Task::spawn($loop, static fn (): string => (string) $one->get($server->url('/d'))->getBody()),
            Task::spawn($loop, static fn (): string => (string) $one->get($server->url('/e'))->getBody()),
        ];
        self::assertSame(['/d', '/e'], array_map(static fn (Task $task): string => $task->await(), $tasks));
        self::assertGreaterThan(0.29, (hrtime(true) - $started) / 1e9);
        self::assertSame(4, $server->connections, 'Both went over the one connection.');
    }

    public function testARequestThatGetsNoAnswerSaysWhy(): void
    {
        $server = $this->server(static fn (array $request): array => [TestServer::reply(200, 'late'), 0.5]);
        $http = $this->client(timeout: 0.1, connectTimeout: 0.5);
        try {
            $http->get($server->url('/slow'));
            self::fail('A request that took too long was waited for.');
        } catch (NetworkException $error) {
            self::assertSame("GET http://127.0.0.1:{$server->port}/slow failed: it took more than 0.1 seconds, and was waiting for the answer when given up.", $error->getMessage());
        }
        self::assertSame('late', (string) $http->timeout(2.0)->get($server->url('/slow'))->getBody(), 'One request may be given longer.');
        self::assertSame(2, $server->connections, 'The client it was asked of is left as it was, and both use the same connections.');

        $closed = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($closed, false), ':'), 1);
        fclose($closed);
        try {
            $http->get("http://127.0.0.1:{$port}/");
            self::fail('A server that is not there answered.');
        } catch (ClientExceptionInterface $error) {
            self::assertSame("GET http://127.0.0.1:{$port}/ failed: 127.0.0.1 could not be reached: the connection was refused.", $error->getMessage());
        }
        try {
            $http->get('http://no-such-host.invalid/');
            self::fail('A name that does not exist was found.');
        } catch (NetworkException $error) {
            self::assertSame('GET http://no-such-host.invalid/ failed: no address was found for the name no-such-host.invalid.', $error->getMessage());
        }

        // Cancelling gives a request up at once, with the cancellation and not as a failure of the network.
        $cancellation = new Cancellation();
        EventLoop::current()->delay(0.02, static fn () => $cancellation->cancel());
        $started = hrtime(true);
        try {
            $this->client()->request('GET', $server->url('/slow'), cancellation: $cancellation);
            self::fail('A cancelled request went on.');
        } catch (CancelledException) {
            self::assertLessThan(0.2, (hrtime(true) - $started) / 1e9);
        }
    }

    public function testAKeptConnectionTheServerClosedIsReplacedAndOnlyASafeRequestIsSentAgain(): void
    {
        $server = $this->server(static fn (array $request): ?string => $request['target'] === '/vanish' ? null : TestServer::reply(200, $request['method']));
        $http = $this->client();
        self::assertSame('GET', (string) $http->get($server->url('/'))->getBody());
        $server->dropConnections();
        Async::sleep(EventLoop::current(), 0.01);
        self::assertSame('POST', (string) $http->post($server->url('/'), json: [])->getBody(), 'A connection found closed before sending is replaced, whatever the request.');
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
            self::assertStringContainsString('the server closed the connection without answering', $error->getMessage());
        }
        self::assertCount(2, $once->requests);
    }

    public function testRedirectsAreFollowedAsABrowserFollowsThem(): void
    {
        $other = $this->server(static fn (array $request): string => TestServer::reply(200, json_encode($request)));
        $server = $this->server(static fn (array $request): string => match (strtok($request['target'], '?')) {
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
        self::assertSame(['GET', '', false], [$after['method'], $after['body'], isset($after['headers']['content-type'])], 'What was posted is fetched, not posted again.');
        $kept = $http->post($server->url('/keep'), json: ['a' => 1])->json();
        self::assertSame(['POST', '{"a":1}', 'Bearer secret'], [$kept['method'], $kept['body'], $kept['headers']['authorization']]);

        $away = $http->get($server->url('/away'))->json();
        self::assertSame(['/landed', false, "127.0.0.1:{$other->port}"], [$away['target'], isset($away['headers']['authorization']), $away['headers']['host']], 'Another server is not sent what proved who is asking.');
        self::assertSame('no location', (string) $http->get($server->url('/nowhere'))->getBody());

        try {
            $http->get($server->url('/loop'));
            self::fail('A redirect that never ends was followed.');
        } catch (ClientException $error) {
            self::assertSame("GET http://127.0.0.1:{$server->port}/loop was redirected more than 5 times.", $error->getMessage());
        }
        $asIs = $this->client(redirects: 0)->get($server->url('/old'));
        self::assertSame([301, '/new?moved=1'], [$asIs->getStatusCode(), $asIs->getHeaderLine('Location')]);
    }

    public function testItIsAPsr18ClientAndSendsABodyOfUnknownLengthInChunks(): void
    {
        $server = $this->server(static fn (array $request): string => TestServer::reply(200, json_encode($request)));
        $http = $this->client();
        self::assertInstanceOf(ClientInterface::class, $http);
        self::assertInstanceOf(ClientContract::class, $http);

        // A pipe: nobody knows how much will come out of it.
        $read = popen(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo str_repeat("streamed ", 20000);'), 'r');
        self::assertNull((new Stream($read))->getSize());
        $answer = $http->sendRequest(new Request('PUT', $server->url('/files/1'), ['Content-Type' => 'text/plain'], new Stream($read)));
        $seen = json_decode((string) $answer->getBody(), true);
        self::assertSame(['PUT', 'chunked', 180000, false], [$seen['method'], $seen['headers']['transfer-encoding'], strlen($seen['body']), isset($seen['headers']['content-length'])]);

        $file = fopen('php://temp', 'w+b');
        fwrite($file, str_repeat('x', 300000));
        $sized = json_decode((string) $http->sendRequest(new Request('POST', $server->url('/files'), [], new Stream($file)))->getBody(), true);
        self::assertSame(['300000', 300000], [$sized['headers']['content-length'], strlen($sized['body'])]);
    }

    public function testAnEncryptedConnectionIsVerifiedAndUsed(): void
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('The openssl extension is not loaded.');
        }
        $certificate = $this->certificate('localhost');
        $server = $this->server(static fn (array $request): string => TestServer::reply(200, 'over tls ' . $request['target']), $certificate);

        $trusting = $this->client(tls: ['cafile' => $certificate]);
        self::assertSame('over tls /a', (string) $trusting->get($server->url('/a', 'https', 'localhost'))->getBody());
        self::assertSame('over tls /b', (string) $trusting->get($server->url('/b', 'https', 'localhost'))->getBody());
        self::assertSame(1, $server->connections, 'An encrypted connection is kept too.');

        try {
            $this->client()->get($server->url('/', 'https', 'localhost'));
            self::fail('A certificate nobody vouches for was accepted.');
        } catch (NetworkException $error) {
            self::assertStringContainsString('localhost could not be reached: the encrypted connection could not be made', $error->getMessage());
        }
        try {
            $trusting->get($server->url('/', 'https'));
            self::fail('A certificate for another name was accepted.');
        } catch (NetworkException $error) {
            self::assertStringContainsString('the encrypted connection could not be made', $error->getMessage());
        }
        self::assertSame('over tls /c', (string) $this->client(tls: ['verify_peer' => false, 'verify_peer_name' => false])->get($server->url('/c', 'https'))->getBody());
    }

    public function testAnErrorStatusIsThrownWhenAskedTo(): void
    {
        $server = $this->server(static fn (array $request): string => TestServer::reply(str_starts_with($request['target'], '/gone') ? 410 : 200, 'body'));
        $http = $this->client();
        self::assertSame('body', (string) $http->get($server->url('/here'))->throw()->getBody());
        try {
            $http->get($server->url('/gone'), query: ['token' => 'secret'])->throw();
            self::fail('An error status passed for a success.');
        } catch (ResponseException $error) {
            self::assertSame("GET http://127.0.0.1:{$server->port}/gone was answered with 410 Reply.", $error->getMessage());
            self::assertSame('body', (string) $error->response->getBody());
            self::assertInstanceOf(ClientExceptionInterface::class, $error);
        }
    }

    public function testFilesAreSentAsAFormAndAReceivedUploadIsPassedOn(): void
    {
        $server = $this->server(static function (array $request): string {
            // Read as the framework reads an upload it receives.
            $received = new Request('POST', '/', ['Content-Type' => $request['headers']['content-type']], $request['body']);
            $files = [];
            $tree = $received->getUploadedFiles();
            array_walk_recursive($tree, static function ($file) use (&$files): void {
                $files[] = [$file->getClientFilename(), $file->getClientMediaType(), $file->getSize(), md5((string) $file->getStream())];
            });
            return TestServer::reply(200, json_encode(['fields' => $received->getParsedBody(), 'names' => array_keys($received->getUploadedFiles()), 'files' => $files, 'length' => $request['headers']['content-length']]));
        });
        $http = $this->client();
        $path = sys_get_temp_dir() . '/crab-send-' . bin2hex(random_bytes(6)) . '.bin';
        $this->files[] = $path;
        $content = random_bytes(300000);
        file_put_contents($path, $content);
        $upload = (new Request('POST', '/', ['Content-Type' => 'multipart/form-data; boundary=B'],
            "--B\r\nContent-Disposition: form-data; name=\"f\"; filename=\"given.txt\"\r\nContent-Type: text/plain\r\n\r\nreceived earlier\r\n--B--\r\n"))->file('f');

        $seen = $http->post($server->url('/upload'), form: ['title' => 'Q3 "final"', 'tags' => ['a', 'b'], 'draft' => false], files: [
            'report' => File::fromPath($path, type: 'application/pdf'),
            'note' => File::fromContents("line one\r\n--not a boundary", 'my "note".txt', 'text/plain'),
            'passed' => $upload,
            'photos' => [File::fromContents('one', '1.png', 'image/png'), File::fromContents('two', '2.png', 'image/png')],
        ])->json();

        self::assertSame(['title' => 'Q3 "final"', 'tags' => ['a', 'b'], 'draft' => '0'], $seen['fields']);
        self::assertSame(['report', 'note', 'passed', 'photos'], $seen['names']);
        self::assertSame([
            [basename($path), 'application/pdf', 300000, md5($content)],
            ['my %22note%22.txt', 'text/plain', 26, md5("line one\r\n--not a boundary")],
            ['given.txt', 'text/plain', 16, md5('received earlier')],
            ['1.png', 'image/png', 3, md5('one')],
            ['2.png', 'image/png', 3, md5('two')],
        ], $seen['files']);
        self::assertGreaterThan(300000, (int) $seen['length'], 'Its length is known, so it is not sent in chunks.');

        $mistakes = [
            'files: takes File::fromPath(), File::fromContents() or an uploaded file for "f", not string' => fn () => $http->post($server->url(), files: ['f' => '/etc/hosts']),
            'The file to send cannot be read: /no/such/file' => fn () => $http->post($server->url(), files: ['f' => File::fromPath('/no/such/file')]),
            'A request has one body' => fn () => $http->post($server->url(), json: ['a' => 1], files: ['f' => File::fromContents('x', 'x')]),
        ];
        foreach ($mistakes as $expected => $mistake) {
            try {
                $mistake();
                self::fail("Not refused: {$expected}");
            } catch (\LogicException | \RuntimeException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
    }

    public function testCookiesAreKeptOnlyWhereThereIsAJar(): void
    {
        $server = $this->server(static fn (array $request): string => match (strtok($request['target'], '?')) {
            '/login' => "HTTP/1.1 302 Found\r\nLocation: /account\r\nSet-Cookie: session=abc; Path=/; HttpOnly\r\nSet-Cookie: theme=dark; Path=/account\r\nContent-Length: 0\r\n\r\n",
            '/logout' => TestServer::reply(200, '', ['Set-Cookie' => 'session=; Max-Age=0; Path=/']),
            default => TestServer::reply(200, $request['headers']['cookie'] ?? 'no cookie'),
        });
        $http = $this->client();
        $http->post($server->url('/login'));
        self::assertSame('no cookie', (string) $http->get($server->url('/account'))->getBody(), 'A client keeps none by itself.');

        $jar = new CookieJar();
        $session = $http->cookies($jar);
        self::assertSame('theme=dark; session=abc', (string) $session->post($server->url('/login'))->getBody(), 'A cookie set by a redirect goes to where it points.');
        self::assertSame('session=abc', (string) $session->get($server->url('/orders'))->getBody());
        self::assertSame('mine=1; session=abc', (string) $session->get($server->url('/orders'), headers: ['Cookie' => 'mine=1'])->getBody());
        self::assertSame('no cookie', (string) $http->get($server->url('/orders'))->getBody(), 'The client it was made from still keeps none.');
        self::assertSame('no cookie', (string) $http->cookies(new CookieJar())->get($server->url('/orders'))->getBody(), 'Another jar is another caller.');
        $session->get($server->url('/logout'));
        self::assertSame('no cookie', (string) $session->get($server->url('/orders'))->getBody(), 'A cookie the server took back is gone.');
    }

    public function testRequestsGoThroughAProxyWhenThereIsOne(): void
    {
        // What is not encrypted is handed to the proxy whole, to pass on.
        $proxy = $this->server(static fn (array $request): string => TestServer::reply(200, json_encode($request)));
        $through = $this->client(proxy: $proxy->url('', 'http', 'user:p%40ss@127.0.0.1'));
        $seen = $through->get('http://api.example.test/users', query: ['page' => 2])->json();
        self::assertSame(['http://api.example.test/users?page=2', 'api.example.test', 'Basic ' . base64_encode('user:p@ss')], [$seen['target'], $seen['headers']['host'], $seen['headers']['proxy-authorization']]);
        self::assertSame('http://api.example.test:8080/', $through->get('http://api.example.test:8080/')->json()['target']);

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
        $server = $this->server(static fn (array $request): string => TestServer::reply(200, 'tunnelled ' . $request['target'] . ' ' . ($request['headers']['proxy-authorization'] ?? 'unseen')), $certificate);
        $tunnel = new TestTunnel();
        $refusing = new TestTunnel('HTTP/1.1 407 Proxy Authentication Required');
        try {
            $secure = $this->client(proxy: $tunnel->url('user:secret'), tls: ['cafile' => $certificate]);
            self::assertSame('tunnelled /a unseen', (string) $secure->get($server->url('/a', 'https', 'localhost'))->getBody());
            self::assertSame('tunnelled /b unseen', (string) $secure->get($server->url('/b', 'https', 'localhost'))->getBody());
            self::assertSame([["CONNECT localhost:{$server->port} HTTP/1.1", 'Basic ' . base64_encode('user:secret')]], array_map(static fn (array $asked): array => [$asked['line'], $asked['headers']['proxy-authorization']], $tunnel->asked), 'One tunnel carried both.');

            try {
                $this->client(proxy: $refusing->url(), tls: ['cafile' => $certificate])->get($server->url('/', 'https', 'localhost'));
                self::fail('A proxy that refused was passed through.');
            } catch (NetworkException $error) {
                self::assertStringContainsString('the proxy would not connect to localhost: it answered "HTTP/1.1 407 Proxy Authentication Required"', $error->getMessage());
            }
        } finally {
            $tunnel->close();
            $refusing->close();
        }
    }

    public function testWaitingForAConnectionEndsWithTheRequestsOwnTimeout(): void
    {
        $server = $this->server(static fn (array $request): array => [TestServer::reply(200, 'slow'), 0.4]);
        $http = $this->client(connections: 1);
        $first = Task::spawn(EventLoop::current(), static fn (): string => (string) $http->get($server->url('/'))->getBody());
        Async::sleep(EventLoop::current(), 0.02);
        $started = hrtime(true);
        try {
            $http->timeout(0.1)->get($server->url('/'));
            self::fail('A request waited past its own timeout for a connection.');
        } catch (NetworkException $error) {
            self::assertStringContainsString('it took more than 0.1 seconds, and every one of the 1 connections to 127.0.0.1 was still busy', $error->getMessage());
            self::assertLessThan(0.25, (hrtime(true) - $started) / 1e9);
        }
        self::assertSame('slow', $first->await());
    }

    public function testTheProviderBindsOneClientPerWorkerFromConfiguration(): void
    {
        $container = new \Crab\Container\Container();
        $config = new \Crab\Config\Config(['http_client' => ['headers' => ['X-App' => 'shop'], 'connections' => 2]]);
        $container->instance(\Crab\Contracts\Config\Config::class, $config);
        $answer = WorkerHarness::run([new HttpClientProvider()], static function (Worker $worker, Container $container): string {
            // Made here, on the worker's own event loop.
            $server = new TestServer(static fn (array $request): string => TestServer::reply(200, $request['target'] . ' ' . ($request['headers']['x-app'] ?? '')));
            $http = $container->get(ClientContract::class);
            self::assertSame($http, $container->get(ClientInterface::class));
            self::assertSame($http, $container->get(Client::class));
            try {
                return (string) $http->get($server->url('/orders'))->getBody();
            } finally {
                $server->close();
            }
        }, $container);
        self::assertSame('/orders shop', $answer);
        self::assertSame([30.0, 5.0, 5, ''], [$config->get('http_client.timeout'), $config->get('http_client.connect_timeout'), $config->get('http_client.redirects'), $config->get('http_client.base_url')]);
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
