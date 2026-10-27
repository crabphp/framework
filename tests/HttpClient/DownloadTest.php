<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Config\Config;
use Crab\Container\Container;
use Crab\Contracts\Config\Config as ConfigContract;
use Crab\Engine\EventLoop;
use Crab\Http\Request;
use Crab\Http\Stream;
use Crab\HttpClient\Client;
use Crab\HttpClient\FakeClient;
use Crab\HttpClient\HttpClientProvider;
use Crab\HttpClient\NetworkException;
use Crab\HttpClient\Response;
use Crab\HttpClient\TooLargeException;
use PHPUnit\Framework\TestCase;

/**
 * Bodies that go into a file or a stream as they arrive, and bodies
 * longer than a call allows, which are given up as soon as that is known.
 */
final class DownloadTest extends TestCase
{
    /**
     * @var list<TestServer>
     */
    private array $servers = [];
    /**
     * @var list<Client>
     */
    private array $clients = [];
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/crab-download-' . bin2hex(random_bytes(6));
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        foreach ($this->servers as $server) {
            $server->close();
        }
        array_map(unlink(...), array_filter((array) glob($this->folder . '/{,.}*', GLOB_BRACE), is_file(...)));
        rmdir($this->folder);
        // Lets the loop these tests waited on come to its end, so that the next test may install its own.
        EventLoop::current()->run();
    }

    public function testADownloadAppearsAtItsPathOnlyOnceItIsAllThere(): void
    {
        $rom = random_bytes((3 * 1024 * 1024) + 7);
        $server = $this->server(static fn(array $request): ?string => match ($request['target']) {
            '/rom' => TestServer::reply(200, $rom, ['Content-Type' => 'application/octet-stream']),
            '/chunked' => "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n0\r\n\r\n",
            '/moved' => "HTTP/1.1 302 Found\r\nLocation: /rom\r\nContent-Length: 9\r\n\r\nmoved off",
            '/missing' => TestServer::reply(404, '{"error":"no such rom"}', ['Content-Type' => 'application/json']),
            '/short' => "HTTP/1.1 200 OK\r\nContent-Length: 50\r\nConnection: close\r\n\r\nonly this",
            default => null,
        });
        $http = $this->client();
        $path = $this->folder . '/rom.bin';

        $response = $http->request('GET', $server->url('/rom'), sink: $path);
        self::assertSame(md5($rom), md5_file($path));
        self::assertSame([strlen($rom), md5($rom)], [
            $response->getBody()->getSize(),
            md5((string) $response->getBody()),
        ]);
        self::assertSame(['rom.bin'], $this->files(), 'Nothing is left beside it.');
        self::assertSame(0o666 & ~umask(), fileperms($path) & 0o777);

        // A redirect's own body never reaches the file; the answer it leads to does.
        $http->request('GET', $server->url('/moved'), sink: $path);
        self::assertSame(md5($rom), md5_file($path));

        $http->request('GET', $server->url('/chunked'), sink: $this->folder . '/hello.txt');
        self::assertSame('hello', file_get_contents($this->folder . '/hello.txt'));

        // An error's body is kept to be read, and the file is not made.
        $missing = $http->request('GET', $server->url('/missing'), sink: $this->folder . '/missing.bin');
        self::assertSame([404, ['error' => 'no such rom']], [$missing->getStatusCode(), $missing->json()]);
        self::assertFileDoesNotExist($this->folder . '/missing.bin');

        try {
            $http->request('GET', $server->url('/short'), sink: $this->folder . '/short.bin');
            self::fail('A body cut short was taken as the download.');
        } catch (NetworkException $error) {
            self::assertStringContainsString(
                'the connection was lost in the middle of the answer',
                $error->getMessage(),
            );
        }
        self::assertSame(['hello.txt', 'rom.bin'], $this->files(), 'A failed download leaves nothing behind.');
    }

    public function testADownloadIntoAStreamIsWrittenWhereTheStreamIs(): void
    {
        $server = $this->server(static fn(array $request): ?string => match ($request['target']) {
            '/rom' => TestServer::reply(200, 'the rom'),
            '/short' => "HTTP/1.1 200 OK\r\nContent-Length: 50\r\nConnection: close\r\n\r\nonly this",
            default => null,
        });
        $http = $this->client();
        $stream = new Stream('header:');
        $stream->seek(0, SEEK_END);

        $response = $http->request('GET', $server->url('/rom'), sink: $stream);
        self::assertSame($stream, $response->getBody());
        self::assertSame('header:the rom', (string) $stream);

        // What went into a stream cannot be taken back, so it is not tried again into it.
        $into = new Stream();
        try {
            $http->retry(2)->request('GET', $server->url('/short'), sink: $into);
            self::fail('A body cut short was taken as the download.');
        } catch (NetworkException) {
        }
        self::assertSame('only this', (string) $into);
        self::assertCount(2, $server->requests, 'The cut-short download was asked for once.');

        // Into a file it is tried again, from the start.
        try {
            $http->retry(2, 0.0)->request('GET', $server->url('/short'), sink: $this->folder . '/short.bin');
        } catch (NetworkException) {
        }
        self::assertCount(5, $server->requests);
        self::assertSame([], $this->files());
    }

    public function testASinkThatCannotBeWrittenIsRefusedBeforeAnythingIsSent(): void
    {
        $http = new FakeClient(['*' => 'never']);
        foreach ([
            [$this->folder . '/no-such-folder/rom.bin', 'is not a folder this process may write in'],
            [$this->folder, 'is not a file\'s path'],
            [$this->folder . '/', 'is not a file\'s path'],
            [new Stream(fopen('php://memory', 'rb')), 'cannot be written'],
        ] as [$sink, $expected]) {
            try {
                $http->request('GET', 'https://roms.example/1', sink: $sink);
                self::fail('A sink that cannot be written was taken.');
            } catch (\LogicException|\RuntimeException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        self::assertSame([], $http->requests);
    }

    public function testABodyLongerThanAllowedIsGivenUpAsItArrives(): void
    {
        $bomb = gzencode(str_repeat('a', 20 * 1024 * 1024));
        $server = $this->server(static fn(array $request): ?string => match (strtok($request['target'], '?')) {
            '/said' => TestServer::reply(200, str_repeat('x', 2048)),
            '/chunked' => "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n"
                . str_repeat("400\r\n" . str_repeat('y', 1024) . "\r\n", 4)
                . "0\r\n\r\n",
            '/until-closed' => "HTTP/1.0 200 OK\r\nConnection: close\r\n\r\n" . str_repeat('z', 2048),
            '/bomb' => TestServer::reply(200, $bomb, ['Content-Encoding' => 'gzip']),
            '/small' => TestServer::reply(200, 'fits'),
            default => null,
        });
        $http = $this->client(maxBytes: 1024);

        foreach ([
            '/said' => 'with a body of 2,048 bytes, more than the 1,024 allowed',
            '/chunked' => 'with more than 1,024 bytes of body',
            '/until-closed' => 'with more than 1,024 bytes of body',
        ] as $target => $expected) {
            try {
                $http->get($server->url($target . '?token=secret'));
                self::fail("{$target} was taken whole.");
            } catch (TooLargeException $error) {
                self::assertSame(
                    "GET {$server->url(
                        $target,
                    )} was answered {$expected}. Give request() a larger maxBytes: to take it.",
                    $error->getMessage(),
                );
                self::assertSame(1024, $error->limit);
            }
        }
        self::assertSame('fits', (string) $http->get($server->url('/small'))->getBody());
        self::assertSame(
            2048,
            strlen((string) $http->request('GET', $server->url('/said'), maxBytes: 2048)->getBody()),
        );

        // What it decompresses to counts as well as what was sent.
        try {
            $http->request('GET', $server->url('/bomb'), maxBytes: 1024 * 1024, sink: $this->folder . '/bomb');
            self::fail('A small body that decompresses to far more was taken whole.');
        } catch (TooLargeException) {
        }
        self::assertSame([], $this->files());
        self::assertSame(
            strlen($bomb),
            $http
                ->request('GET', $server->url('/bomb'), maxBytes: 1024 * 1024, decode: false)
                ->getBody()
                ->getSize(),
        );
    }

    public function testDecodingCanBeTurnedOffToKeepTheBytesAsSent(): void
    {
        $compressed = gzencode('{"compressed":true}');
        $server = $this->server(static fn(): string => TestServer::reply(200, $compressed, [
            'Content-Encoding' => 'gzip',
        ]));
        $http = $this->client();

        $raw = $http->request('GET', $server->url('/'), decode: false);
        self::assertSame([$compressed, 'gzip', (string) strlen($compressed)], [
            (string) $raw->getBody(),
            $raw->getHeaderLine('Content-Encoding'),
            $raw->getHeaderLine('Content-Length'),
        ]);
        self::assertArrayNotHasKey('accept-encoding', $server->requests[0]['headers']);
        self::assertSame(['compressed' => true], $http->get($server->url('/'))->json());
    }

    public function testEveryRequestHasALimitByDefault(): void
    {
        $server = $this->server(static fn(): string => TestServer::reply(200, 'fits'));
        $http = $this->client();
        self::assertSame(Client::MAX_BYTES, 64 * 1024 * 1024);
        self::assertSame('fits', (string) $http->get($server->url('/'))->getBody());

        $container = new Container();
        $config = new Config(['http_client' => ['max_bytes' => 3]]);
        $container->instance(ConfigContract::class, $config);
        new HttpClientProvider()->register($container);
        $configured = $container->get(Client::class);
        $this->clients[] = $configured;
        $this->expectException(TooLargeException::class);
        $configured->sendRequest(new Request('GET', $server->url('/')));
    }

    public function testTheFakeClientReceivesBodiesAsTheNetworkDoes(): void
    {
        $http = new FakeClient([
            'https://roms.example/1' => FakeClient::response(200, 'the rom'),
            'https://roms.example/big' => FakeClient::response(200, str_repeat('x', 4096)),
            'https://roms.example/said' => new Response(200, ['Content-Length' => ['999999999']], ''),
            'https://roms.example/gzip' => FakeClient::response(200, gzencode('unpacked'), [
                'Content-Encoding' => 'gzip',
            ]),
            'https://roms.example/gone' => 404,
        ], maxBytes: 1024);
        $path = $this->folder . '/rom.bin';

        self::assertSame('the rom', (string) $http->request('GET', 'https://roms.example/1', sink: $path)->getBody());
        self::assertSame('the rom', file_get_contents($path));
        self::assertSame('unpacked', (string) $http->get('https://roms.example/gzip')->getBody());
        self::assertSame(
            gzencode('unpacked'),
            (string) $http->request('GET', 'https://roms.example/gzip', decode: false)->getBody(),
        );
        self::assertSame(
            404,
            $http->request('GET', 'https://roms.example/gone', sink: $this->folder . '/gone')->getStatusCode(),
        );
        self::assertFileDoesNotExist($this->folder . '/gone');

        foreach (['big', 'said'] as $name) {
            try {
                $http->request('GET', "https://roms.example/{$name}", sink: $this->folder . "/{$name}");
                self::fail("{$name} was taken whole.");
            } catch (TooLargeException $error) {
                self::assertStringStartsWith(
                    "GET https://roms.example/{$name} was answered with",
                    $error->getMessage(),
                );
            }
        }
        self::assertSame(['rom.bin'], $this->files());
        self::assertSame(
            4096,
            strlen((string) $http->request('GET', 'https://roms.example/big', maxBytes: 4096)->getBody()),
        );
    }

    /**
     * @return list<string> The files in the folder, hidden ones included, by name.
     */
    private function files(): array
    {
        $names = array_values(array_diff((array) scandir($this->folder), ['.', '..']));
        sort($names);
        return $names;
    }

    private function server(\Closure $reply): TestServer
    {
        return $this->servers[] = new TestServer($reply);
    }

    private function client(mixed ...$options): Client
    {
        return $this->clients[] = new Client(...$options);
    }
}
