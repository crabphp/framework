<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\Engine\Http\Body;
use Crab\Engine\Http\Request as EngineRequest;
use Crab\Http\Factory;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Http\Stream;
use Crab\Http\UploadedFile;
use Crab\Http\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    public function testHeadersAreCaseInsensitiveAndKeepTheirSpelling(): void
    {
        $response = new Response(200, ['X-Trace' => 'a', 'accept' => ['b', 'c']]);
        self::assertSame(['X-Trace' => ['a'], 'accept' => ['b', 'c']], $response->getHeaders());
        self::assertTrue($response->hasHeader('x-trace'));
        self::assertSame(['a'], $response->getHeader('X-TRACE'));
        self::assertSame('b, c', $response->getHeaderLine('Accept'));
        self::assertSame([], $response->getHeader('missing'));
        self::assertSame('', $response->getHeaderLine('missing'));
    }

    public function testHeaderChangesReturnNewMessages(): void
    {
        $response = new Response(200, ['X-Trace' => 'a']);
        $added = $response->withAddedHeader('x-trace', ' b ');
        $replaced = $added->withHeader('X-TRACE', 7);
        $removed = $replaced->withoutHeader('x-trace');
        self::assertSame(['X-Trace' => ['a']], $response->getHeaders());
        self::assertSame(['X-Trace' => ['a', 'b']], $added->getHeaders());
        self::assertSame(['X-TRACE' => ['7']], $replaced->getHeaders());
        self::assertSame([], $removed->getHeaders());
        self::assertSame($removed, $removed->withoutHeader('x-trace'));
        self::assertSame('2', $response->withProtocolVersion('2')->getProtocolVersion());
        self::assertSame('1.1', $response->getProtocolVersion());
    }

    #[DataProvider('invalidHeaderProvider')]
    public function testInvalidHeadersAreRejected(string $name, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Response()->withHeader($name, $value);
    }

    public static function invalidHeaderProvider(): array
    {
        return [
            'empty name' => ['', 'a'],
            'name with space' => ['X Trace', 'a'],
            'name with newline' => ["X-Trace\n", 'a'],
            'injected line' => ['X-Trace', "a\r\nSet-Cookie: b"],
            'no values' => ['X-Trace', []],
            'non-scalar value' => ['X-Trace', [null]],
        ];
    }

    public function testResponseStatusAndFactories(): void
    {
        $response = Response::json(['ok' => true], 201, ['X-Trace' => 'a']);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('Created', $response->getReasonPhrase());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('a', $response->getHeaderLine('X-Trace'));
        self::assertSame('{"ok":true}', (string) $response->getBody());
        self::assertSame('text/html; charset=utf-8', Response::html('<p>')->getHeaderLine('content-type'));
        self::assertSame(
            'text/plain',
            Response::text('a', headers: ['Content-Type' => 'text/plain'])->getHeaderLine('content-type'),
        );

        $changed = $response->withStatus(404, 'Gone Fishing');
        self::assertSame([404, 'Gone Fishing'], [$changed->getStatusCode(), $changed->getReasonPhrase()]);
        self::assertSame(201, $response->getStatusCode());

        $this->expectException(\InvalidArgumentException::class);
        $response->withStatus(600);
    }

    public function testStringStreamAllocatesNoResourceUntilUsedAsAStream(): void
    {
        $stream = new Stream('hello');
        self::assertSame('hello', $stream->text());
        self::assertSame(5, $stream->getSize());
        self::assertSame('hello', (string) $stream);
        self::assertSame('hello', $stream->text());

        self::assertSame('he', $stream->read(2));
        self::assertNull($stream->text());
        self::assertSame(2, $stream->tell());
        self::assertSame('llo', $stream->getContents());
        self::assertTrue($stream->eof());
        self::assertSame(6, $stream->write(' world'));
        self::assertSame('hello world', (string) $stream);
        self::assertSame(11, $stream->getSize());
        $stream->seek(6);
        self::assertSame('world', $stream->read(100));
        self::assertTrue($stream->isSeekable() && $stream->isReadable() && $stream->isWritable());
        self::assertSame('PHP', $stream->getMetadata('wrapper_type'));
    }

    public function testDetachedAndClosedStreams(): void
    {
        $stream = new Stream('hello');
        $resource = $stream->detach();
        self::assertIsResource($resource);
        self::assertSame('hello', stream_get_contents($resource));
        self::assertNull($stream->detach());
        self::assertNull($stream->getSize());
        self::assertSame('', (string) $stream);
        self::assertTrue($stream->eof());
        self::assertFalse($stream->isSeekable() || $stream->isReadable() || $stream->isWritable());
        self::assertSame([], $stream->getMetadata());
        self::assertNull($stream->getMetadata('mode'));

        $closed = new Stream($resource);
        $closed->close();
        self::assertFalse(is_resource($resource));
        $this->expectException(\RuntimeException::class);
        $closed->read(1);
    }

    public function testResourceStreamReportsItsMode(): void
    {
        $factory = new Factory();
        $stream = $factory->createStreamFromFile(__FILE__);
        self::assertTrue($stream->isReadable());
        self::assertFalse($stream->isWritable());
        self::assertSame(filesize(__FILE__), $stream->getSize());
        self::assertStringStartsWith('<?php', (string) $stream);
        try {
            $stream->write('x');
            self::fail('A read-only stream accepted a write.');
        } catch (\RuntimeException) {
        }
        try {
            $factory->createStreamFromFile(__DIR__ . '/missing');
            self::fail('A missing file was opened.');
        } catch (\RuntimeException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        new Stream(42);
    }

    public function testUriParsesNormalizesAndRebuilds(): void
    {
        $uri = new Uri('HTTPS://User:Pass@Example.COM:8443/a b/%7Ec?q=1 2&r=%20#frag ment');
        self::assertSame('https', $uri->getScheme());
        self::assertSame('User:Pass', $uri->getUserInfo());
        self::assertSame('example.com', $uri->getHost());
        self::assertSame(8443, $uri->getPort());
        self::assertSame('User:Pass@example.com:8443', $uri->getAuthority());
        self::assertSame('/a%20b/%7Ec', $uri->getPath());
        self::assertSame('q=1%202&r=%20', $uri->getQuery());
        self::assertSame('frag%20ment', $uri->getFragment());
        self::assertSame('https://User:Pass@example.com:8443/a%20b/%7Ec?q=1%202&r=%20#frag%20ment', (string) $uri);

        self::assertNull(new Uri('https://example.com:443/')->getPort());
        self::assertSame(443, new Uri('http://example.com:443/')->getPort());
        self::assertSame('', (string) new Uri());
        self::assertSame('/only?x=1', (string) new Uri('/only?x=1'));
    }

    public function testUriChangesReturnNewUris(): void
    {
        $uri = new Uri('http://example.com/a');
        $changed = $uri
            ->withScheme('HTTPS')
            ->withHost('Other.test')
            ->withPort(8080)
            ->withPath('b c')
            ->withQuery('x=é')
            ->withFragment('top')
            ->withUserInfo('me', 'secret');
        self::assertSame('http://example.com/a', (string) $uri);
        self::assertSame('https://me:secret@other.test:8080/b%20c?x=%C3%A9#top', (string) $changed);
        self::assertSame($uri, $uri->withHost('example.com'));
        self::assertSame('/a', (string) new Uri()->withPath('//a'));

        try {
            new Uri('http:///broken');
            self::fail('A malformed URI was accepted.');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        $uri->withPort(70_000);
    }

    public function testConstructedRequest(): void
    {
        $request = new Request(
            'POST',
            'http://example.com:8080/form?page=2&tags[]=a',
            [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
                'Cookie' => 'session=abc%20def; theme=dark; session=later',
            ],
            'name=Crab&n=1',
            serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
        );

        self::assertSame('POST', $request->getMethod());
        self::assertSame('/form?page=2&tags%5B%5D=a', $request->getRequestTarget());
        self::assertSame('example.com:8080', $request->getHeaderLine('host'));
        self::assertSame('Host', array_key_first($request->getHeaders()));
        self::assertSame(['page' => '2', 'tags' => ['a']], $request->getQueryParams());
        self::assertSame(['session' => 'abc def', 'theme' => 'dark'], $request->getCookieParams());
        self::assertSame(['name' => 'Crab', 'n' => '1'], $request->getParsedBody());
        self::assertSame(['REMOTE_ADDR' => '127.0.0.1'], $request->getServerParams());
        self::assertSame('2', $request->query('page'));
        self::assertSame('none', $request->query('missing', 'none'));
        self::assertSame('Crab', $request->input('name'));
        self::assertSame('none', $request->input('missing', 'none'));
        self::assertFalse($request->cancellation()->isCancelled());
        self::assertSame($request->cancellation(), $request->cancellation());
        self::assertSame([], $request->getUploadedFiles());
        self::assertNull(new Request('GET', '/')->getParsedBody());
        self::assertSame('/', new Request('GET', 'http://example.com')->getRequestTarget());
    }

    public function testARequestCookieIsReadByNameWithADefault(): void
    {
        $request = new Request('GET', '/')->withCookieParams(['theme' => 'dark', 'empty' => '', 'odd' => ['nested']]);

        self::assertSame(['dark', '', 'light', null, 'light'], [
            $request->cookie('theme'),
            $request->cookie('empty', 'light'),
            $request->cookie('missing', 'light'),
            $request->cookie('missing'),
            $request->cookie('odd', 'light'),
        ]);
    }

    public function testRequestChangesReturnNewRequests(): void
    {
        $request = new Request('GET', 'http://example.com/a');
        $file = new UploadedFile(new Stream('x'));
        $changed = $request
            ->withMethod('PUT')
            ->withAttribute('id', '7')
            ->withAttribute('none', null)
            ->withQueryParams(['q' => '1'])
            ->withCookieParams(['c' => '2'])
            ->withParsedBody(['p' => '3'])
            ->withUploadedFiles(['f' => $file])
            ->withRequestTarget('*');
        self::assertSame(['GET', [], [], [], null, '/a'], [
            $request->getMethod(),
            $request->getAttributes(),
            $request->getQueryParams(),
            $request->getCookieParams(),
            $request->getParsedBody(),
            $request->getRequestTarget(),
        ]);
        self::assertSame('PUT', $changed->getMethod());
        self::assertSame(['id' => '7', 'none' => null], $changed->getAttributes());
        self::assertSame('7', $changed->getAttribute('id'));
        self::assertNull($changed->getAttribute('none', 'default'));
        self::assertSame('default', $changed->getAttribute('missing', 'default'));
        self::assertSame(['none' => null], $changed->withoutAttribute('id')->getAttributes());
        self::assertSame($changed, $changed->withoutAttribute('missing'));
        self::assertSame([['q' => '1'], ['c' => '2'], ['p' => '3'], ['f' => $file], '*'], [
            $changed->getQueryParams(),
            $changed->getCookieParams(),
            $changed->getParsedBody(),
            $changed->getUploadedFiles(),
            $changed->getRequestTarget(),
        ]);

        $moved = $request->withUri(new Uri('http://other.test:81/b?x=1'));
        self::assertSame(['other.test:81', '/b?x=1'], [$moved->getHeaderLine('Host'), $moved->getRequestTarget()]);
        self::assertSame('example.com', $request->withUri(new Uri('http://other.test/'), true)->getHeaderLine('Host'));
        self::assertSame('example.com', $request->withUri(new Uri('/relative'))->getHeaderLine('Host'));

        foreach ([
            static fn() => $request->withMethod('BAD METHOD'),
            static fn() => $request->withRequestTarget('/a b'),
            static fn() => $request->withParsedBody('text'),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('An invalid change was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testEngineRequestIsReadOnDemand(): void
    {
        $body = new Body();
        $body->append('a=1&b=2');
        $source = new EngineRequest(
            'POST',
            '/users/7?page=2',
            '1.1',
            [
                'host' => ['example.com:8080'],
                'content-type' => ['application/x-www-form-urlencoded'],
                'cookie' => ['theme=dark'],
                'accept' => ['text/html', 'text/plain'],
            ],
            $body,
        );
        $request = Request::fromEngine($source);

        self::assertSame(['POST', '1.1', '/users/7?page=2'], [
            $request->getMethod(),
            $request->getProtocolVersion(),
            $request->getRequestTarget(),
        ]);
        self::assertTrue($request->hasHeader('Accept'));
        self::assertFalse($request->hasHeader('X-Missing'));
        self::assertSame('text/html, text/plain', $request->getHeaderLine('ACCEPT'));
        self::assertSame('', $request->getHeaderLine('X-Missing'));
        self::assertSame(['page' => '2'], $request->getQueryParams());
        self::assertSame(['theme' => 'dark'], $request->getCookieParams());
        self::assertSame(['a' => '1', 'b' => '2'], $request->getParsedBody());
        self::assertSame('a=1&b=2', (string) $request->getBody());
        self::assertSame('http://example.com:8080/users/7?page=2', (string) $request->getUri());
        self::assertSame($request->getUri(), $request->getUri());
        self::assertSame(['text/html', 'text/plain'], $request->getHeader('accept'));
        self::assertSame($source->headers, $request->getHeaders());
        self::assertSame($source->cancellation, $request->cancellation());

        $changed = $request->withHeader('X-Added', 'yes');
        self::assertSame('yes', $changed->getHeaderLine('x-added'));
        self::assertSame('text/html, text/plain', $changed->getHeaderLine('accept'));
        self::assertFalse($request->hasHeader('x-added'));
        self::assertSame('text/html', Request::fromEngine($source)->withoutHeader('cookie')->getHeader('accept')[0]);
        self::assertSame(
            'other.test',
            Request::fromEngine($source)->withUri(new Uri('http://other.test/'))->getHeaderLine('host'),
        );
    }

    public function testEngineRequestTargetsWithoutAHost(): void
    {
        $request = Request::fromEngine(new EngineRequest('GET', '/a b?x=1', '1.0', []));
        self::assertSame(['', '/a%20b', 'x=1'], [
            $request->getUri()->getHost(),
            $request->getUri()->getPath(),
            $request->getUri()->getQuery(),
        ]);
        self::assertSame('', (string) $request->getBody());
        $absolute = Request::fromEngine(new EngineRequest('GET', 'http://proxy.test/path', '1.1', ['host' => [
            'ignored.test',
        ]]));
        self::assertSame(['proxy.test', '/path'], [$absolute->getUri()->getHost(), $absolute->getUri()->getPath()]);
    }

    public function testFactoryCreatesCrabMessages(): void
    {
        $factory = new Factory();
        self::assertSame([418, 'Teapot'], [
            $factory->createResponse(418, 'Teapot')->getStatusCode(),
            $factory->createResponse(418, 'Teapot')->getReasonPhrase(),
        ]);
        $request = $factory->createServerRequest('GET', $factory->createUri('http://example.com/a'), ['K' => 'v']);
        self::assertInstanceOf(Request::class, $request);
        self::assertSame(['/a', ['K' => 'v']], [$request->getUri()->getPath(), $request->getServerParams()]);
        self::assertSame('abc', (string) $factory->createStream('abc'));
        $resource = fopen('php://memory', 'w+b');
        fwrite($resource, 'xyz');
        self::assertSame('xyz', (string) $factory->createStreamFromResource($resource));
    }
}
