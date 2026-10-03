<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Http\Body;
use Crab\Engine\Http\HttpException;
use Crab\Engine\Http\Parser;
use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Http\ResponseEncoder;
use Crab\Engine\Network\Connection;
use Crab\Engine\Server\HttpServer;
use Crab\Engine\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use RuntimeException;

/**
 * The optimized request path must stay indistinguishable from the general
 * parser, encoder and transport behavior it shortcuts.
 */
final class HttpFastPathTest extends TestCase
{
    public function testRepeatedHeadsProduceEqualFieldsAndIndependentRequests(): void
    {
        $parser = new Parser();
        $wire = "GET /items?page=2 HTTP/1.1\r\nHost: example.com\r\nX-Trace:  a b \r\nx-trace: second\r\n\r\n";
        $requests = [];
        for ($index = 0; $index < 3; ++$index) {
            $parser->append($wire);
            $requests[] = $parser->next();
            self::assertNull($parser->next(), 'A consumed head produced another request.');
        }
        foreach ($requests as $request) {
            self::assertSame(['GET', '/items?page=2', '1.1'], [$request->method, $request->target, $request->version]);
            self::assertSame(['host' => ['example.com'], 'x-trace' => ['a b', 'second']], $request->headers);
            self::assertSame(0, $request->body->size());
        }
        self::assertNotSame($requests[0], $requests[1]);
        self::assertNotSame($requests[0]->body, $requests[1]->body);
        self::assertNotSame($requests[0]->cancellation, $requests[1]->cancellation);
        $requests[0]->cancellation->cancel();
        self::assertFalse($requests[1]->cancellation->isCancelled(), 'Repeated heads shared cancellation state.');
    }

    public function testRepeatedHeadsNeverLeakIntoDifferentOrFramedRequests(): void
    {
        $parser = new Parser();
        $parser->append("GET /one HTTP/1.1\r\nHost: a\r\n\r\n");
        self::assertSame('/one', $parser->next()?->target);
        $parser->append("GET /two HTTP/1.1\r\nHost: a\r\n\r\n");
        self::assertSame('/two', $parser->next()?->target);
        $parser->append("POST /two HTTP/1.1\r\nHost: a\r\nContent-Length: 2\r\n\r\nok");
        $post = $parser->next();
        self::assertSame(['POST', 'ok'], [$post?->method, $post?->body->contents()]);
        // Pipelined repeats share one buffer and still parse one at a time.
        $parser->append(str_repeat("GET /two HTTP/1.1\r\nHost: a\r\n\r\n", 3) . 'GET /thr');
        for ($index = 0; $index < 3; ++$index) {
            self::assertSame('/two', $parser->next()?->target);
        }
        self::assertNull($parser->next());
        $parser->append("ee HTTP/1.0\r\n\r\n");
        $last = $parser->next();
        self::assertSame(['/three', '1.0', []], [$last?->target, $last?->version, $last?->headers]);
    }

    public function testIdenticalFieldsAreNeverChangedByAnEarlierRequestLine(): void
    {
        $parser = new Parser();
        $fields = "Host: origin\r\nX-Shared: 1\r\n\r\n";
        $seen = [];
        foreach (['GET /a', 'GET http://absolute.example:81/b', 'GET /c', 'OPTIONS *', 'POST /d'] as $line) {
            $parser->append("{$line} HTTP/1.1\r\n{$fields}");
            $request = $parser->next();
            $seen[] = [$request?->method, $request?->target, $request?->headers['host'][0] ?? null, $request?->headers['x-shared'] ?? null];
        }
        self::assertSame([
            ['GET', '/a', 'origin', ['1']],
            ['GET', 'http://absolute.example:81/b', 'absolute.example:81', ['1']],
            ['GET', '/c', 'origin', ['1']],
            ['OPTIONS', '*', 'origin', ['1']],
            ['POST', '/d', 'origin', ['1']],
        ], $seen);
        // The same request line with different fields, then a version that
        // changes which fields are required.
        $parser->append("POST /d HTTP/1.1\r\nHost: other\r\n\r\n");
        self::assertSame(['host' => ['other']], $parser->next()?->headers);
        $parser->append("GET /e HTTP/1.0\r\n\r\n");
        self::assertSame([], $parser->next()?->headers);
        try {
            $parser->append("GET /e HTTP/1.1\r\n\r\n");
            $parser->next();
            self::fail('An earlier request without fields excused a missing Host.');
        } catch (HttpException $error) {
            self::assertSame(400, $error->status);
        }
    }

    #[DataProvider('lazyFieldProvider')]
    public function testSingleFieldLookupsMatchTheCompleteMap(string $wire, string $body = ''): void
    {
        $names = ['host', 'Host', 'connection', 'expect', 'content-length', 'transfer-encoding', 'x-a', 'X-A', 'x',
            'hostile', 'connection-x', 'user-agent', 'empty', 'missing', '', 'x-a: 1', 'host:', "x-a\r\n", 'x.a', 'x-b'];
        $lookups = [];
        foreach ([false, true] as $mapFirst) {
            $parser = new Parser();
            $parser->append($wire . $body);
            $request = $parser->next();
            self::assertNotNull($request);
            $map = $mapFirst ? $request->headers : null;
            $seen = [];
            foreach ($names as $name) {
                $seen[$name] = [$request->header($name), $request->hasToken($name, 'close')];
            }
            $seen['keep-alive'] = $request->keepAlive();
            $lookups[] = [$seen, $request->headers];
            self::assertSame($map ?? $request->headers, $request->headers);
        }
        self::assertSame($lookups[0], $lookups[1], 'Lookups before and after building the map disagree.');
        foreach ($lookups[0][1] as $name => $values) {
            self::assertSame(implode(', ', $values), $lookups[0][0][$name][0] ?? implode(', ', $values));
        }
    }

    public static function lazyFieldProvider(): iterable
    {
        yield 'usual request' => ["GET / HTTP/1.1\r\nHost: a\r\nUser-Agent: UA/1.0 \r\nX-A: 1\r\nx-a:  2\t\r\nEmpty:\r\n\r\n"];
        yield 'names that extend framing names' => ["GET / HTTP/1.1\r\nHostile: h\r\nConnection-X: c\r\nHOST: a\r\nX: close\r\n\r\n"];
        yield 'connection list' => ["GET / HTTP/1.1\r\nHost: a\r\nConnection: keep-alive , Close \r\nX-B: host: b\r\n\r\n"];
        yield 'repeated connection' => ["GET / HTTP/1.1\r\nHost: a\r\nConnection: keep-alive\r\nX-A: 1\r\nconnection: close\r\n\r\n"];
        yield 'absolute-form' => ["GET http://other.example/p HTTP/1.1\r\nX-A: 1\r\nHost: a\r\n\r\n"];
        yield 'absolute-form on HTTP/1.0' => ["GET http://other.example/p HTTP/1.0\r\nX-A: 1\r\n\r\n"];
        yield 'no fields' => ["GET / HTTP/1.0\r\n\r\n"];
        yield 'fixed body' => ["POST / HTTP/1.1\r\nHost: a\r\nContent-Length: 2\r\nX-A: 1\r\n\r\n", 'ok'];
        yield 'chunked body' => ["POST / HTTP/1.1\r\nX-A: 1\r\nHost: a\r\nTransfer-Encoding: chunked\r\n\r\n", "2\r\nok\r\n0\r\n\r\n"];
        yield 'expectation' => ["GET / HTTP/1.1\r\nHost: a\r\nExpect: 100-continue\r\nX-A: close\r\n\r\n"];
    }

    public function testHeaderMapCannotBeReplacedAndIsBuiltOnce(): void
    {
        $parser = new Parser();
        $parser->append("GET / HTTP/1.1\r\nHost: a\r\nX-A: 1\r\n\r\n");
        $request = $parser->next();
        $first = $request->headers;
        self::assertSame(['host' => ['a'], 'x-a' => ['1']], $first);
        try {
            $request->headers = [];
            self::fail('Request fields were replaced.');
        } catch (\Error) {
        }
        self::assertSame($first, $request->headers);
        self::assertSame($first, (new Request('GET', '/', '1.1', $first, new Body()))->headers);
    }

    #[DataProvider('keepAliveProvider')]
    public function testKeepAliveDecisionsMatchTokenSemantics(string $version, array $headers, bool $expected): void
    {
        self::assertSame($expected, self::request(version: $version, headers: $headers)->keepAlive());
    }

    public static function keepAliveProvider(): iterable
    {
        yield '1.1 default' => ['1.1', [], true];
        yield '1.0 default' => ['1.0', [], false];
        foreach (['1.1', '1.0'] as $version) {
            yield "{$version} keep-alive" => [$version, ['connection' => ['keep-alive']], true];
            yield "{$version} Keep-Alive" => [$version, ['connection' => ['Keep-Alive']], true];
            yield "{$version} close" => [$version, ['connection' => ['close']], false];
            yield "{$version} CLOSE" => [$version, ['connection' => ['CLOSE']], false];
            yield "{$version} list with close" => [$version, ['connection' => ['keep-alive, close']], false];
            yield "{$version} two fields with close" => [$version, ['connection' => ['keep-alive', 'close']], false];
            yield "{$version} mixed-case keep-alive" => [$version, ['connection' => ['KEEP-ALIVE']], true];
        }
        yield '1.1 other token' => ['1.1', ['connection' => ['upgrade']], true];
        yield '1.0 other token' => ['1.0', ['connection' => ['upgrade']], false];
    }

    #[DataProvider('acceptedHeadProvider')]
    public function testAcceptsValidHeads(string $wire, string $target, array $headers): void
    {
        $parser = new Parser();
        $parser->append($wire);
        $request = $parser->next();
        self::assertNotNull($request);
        self::assertSame($target, $request->target);
        self::assertSame($headers, $request->headers);
        self::assertFalse($parser->takeContinue(), 'A bodyless request requested 100 Continue.');
    }

    public static function acceptedHeadProvider(): iterable
    {
        yield 'no fields on HTTP/1.0' => ["GET / HTTP/1.0\r\n\r\n", '/', []];
        yield 'empty and padded values' => ["GET / HTTP/1.1\r\nHost: x\r\nX-Empty:\r\nX-Pad: \t v \t\r\n\r\n", '/',
            ['host' => ['x'], 'x-empty' => [''], 'x-pad' => ['v']]];
        yield 'obs-text and repeated names' => ["GET / HTTP/1.1\r\nHOST: x\r\nA: \xC3\xA9\r\na: 2\r\n\r\n", '/',
            ['host' => ['x'], 'a' => ["\xC3\xA9", '2']]];
        yield 'absolute-form replaces Host' => ["GET http://example.org:8080/p HTTP/1.1\r\nHost: other\r\n\r\n",
            'http://example.org:8080/p', ['host' => ['example.org:8080']]];
        yield 'asterisk with OPTIONS' => ["OPTIONS * HTTP/1.1\r\nHost: x\r\n\r\n", '*', ['host' => ['x']]];
        yield 'expectation without a body' => ["GET / HTTP/1.1\r\nHost: x\r\nExpect: 100-continue\r\n\r\n", '/',
            ['host' => ['x'], 'expect' => ['100-continue']]];
        yield 'bracketed host' => ["GET / HTTP/1.1\r\nHost: [::1]:8080\r\n\r\n", '/', ['host' => ['[::1]:8080']]];
    }

    #[DataProvider('rejectedHeadProvider')]
    public function testRejectsInvalidHeads(string $wire, int $status, array $options = []): void
    {
        // Fresh, and after a valid request: no initial or cached state may
        // excuse an invalid head.
        foreach ([false, true] as $primed) {
            $parser = new Parser(...$options);
            if ($primed) {
                $parser->append("GET / HTTP/1.1\r\nHost: x\r\n\r\n");
                self::assertNotNull($parser->next());
            }
            try {
                $parser->append($wire);
                $parser->next();
                self::fail('Invalid HTTP head was accepted.');
            } catch (HttpException $error) {
                self::assertSame($status, $error->status, $error->getMessage());
            }
        }
    }

    public static function rejectedHeadProvider(): iterable
    {
        yield 'leading blank line' => ["\r\nGET / HTTP/1.1\r\nHost: x\r\n\r\n", 400];
        yield 'lowercase protocol' => ["GET / http/1.1\r\nHost: x\r\n\r\n", 400];
        yield 'space in target' => ["GET /a b HTTP/1.1\r\nHost: x\r\n\r\n", 400];
        yield 'missing target' => ["GET  HTTP/1.1\r\nHost: x\r\n\r\n", 400];
        yield 'bare line feed' => ["GET / HTTP/1.1\r\nHost: x\nX: y\r\n\r\n", 400];
        yield 'carriage return in value' => ["GET / HTTP/1.1\r\nHost: x\r\nX: a\rb\r\n\r\n", 400];
        yield 'control byte in value' => ["GET / HTTP/1.1\r\nHost: x\r\nX: a\0b\r\n\r\n", 400];
        yield 'empty field name' => ["GET / HTTP/1.1\r\nHost: x\r\n: y\r\n\r\n", 400];
        yield 'field without colon' => ["GET / HTTP/1.1\r\nHost: x\r\nbroken\r\n\r\n", 400];
        yield 'malformed last field' => ["GET / HTTP/1.1\r\nHost: x\r\nX y: z\r\n\r\n", 400];
        yield 'invalid host after a valid one' => ["GET / HTTP/1.1\r\nHost: x y\r\n\r\n", 400];
        yield 'empty host' => ["GET / HTTP/1.1\r\nHost:\r\n\r\n", 400];
        yield 'duplicate host' => ["GET / HTTP/1.1\r\nHost: x\r\nHost: x\r\n\r\n", 400];
        yield 'missing host' => ["GET / HTTP/1.1\r\nX: y\r\n\r\n", 400];
        yield 'fragment' => ["GET /a#b HTTP/1.1\r\nHost: x\r\n\r\n", 400];
        yield 'asterisk without OPTIONS' => ["GET * HTTP/1.1\r\nHost: x\r\n\r\n", 400];
        yield 'credentials in absolute-form' => ["GET http://u:p@x/ HTTP/1.1\r\nHost: x\r\n\r\n", 400];
        yield 'CONNECT' => ["CONNECT x:443 HTTP/1.1\r\nHost: x\r\n\r\n", 501];
        yield 'unsupported version' => ["GET / HTTP/1.2\r\nHost: x\r\n\r\n", 505];
        yield 'expectation on HTTP/1.0' => ["GET / HTTP/1.0\r\nExpect: 100-continue\r\n\r\n", 417];
        yield 'too many fields' => ["GET / HTTP/1.1\r\nHost: x\r\nA: 1\r\nB: 2\r\n\r\n", 431, ['maxHeaderCount' => 2]];
        yield 'too many fields, one malformed' => ["GET / HTTP/1.1\r\nHost: x\r\nbroken\r\nB: 2\r\n\r\n", 431, ['maxHeaderCount' => 2]];
        yield 'malformed within the limit' => ["GET / HTTP/1.1\r\nHost: x\r\nbroken\r\n\r\n", 400, ['maxHeaderCount' => 2]];
    }

    public function testFieldCountLimitAcceptsExactlyTheMaximum(): void
    {
        $parser = new Parser(maxHeaderCount: 2);
        $parser->append("GET / HTTP/1.1\r\nHost: x\r\nA: 1\r\n\r\n");
        self::assertSame(['host' => ['x'], 'a' => ['1']], $parser->next()?->headers);
    }

    #[DataProvider('smallResponseProvider')]
    public function testSmallEncoderMatchesTheGeneralEncoder(Response $response, Request $request, bool $forceClose): void
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $close = $forceClose;
            $small = ResponseEncoder::small($response, $request, $close);
            $encoder = new ResponseEncoder($response, $request, $forceClose);
            $general = implode('', iterator_to_array($encoder->bytes(), false));
            self::assertNotNull($small, 'An eligible response left the small path.');
            self::assertSame(self::withoutDate($general), self::withoutDate($small));
            self::assertSame($encoder->close, $close);
            self::assertSame($general, $encoder->message(), 'message() differs from the produced bytes.');
        }
    }

    public static function smallResponseProvider(): iterable
    {
        $get = self::request();
        yield 'text' => [Response::text("Hello\n"), $get, false];
        yield 'empty body' => [new Response(), $get, false];
        yield 'forced close' => [Response::text('bye'), $get, true];
        yield 'request close' => [Response::text('bye'), self::request(headers: ['connection' => ['Close']]), false];
        yield 'HTTP/1.0 without keep-alive' => [Response::text('old'), self::request(version: '1.0', headers: []), false];
        yield 'HTTP/1.0 keep-alive' => [Response::text('old'),
            self::request(version: '1.0', headers: ['connection' => ['keep-alive']]), false];
        yield 'status and repeated fields' => [new Response(404, ['X-A' => ['1', '2'], 'Content-Type' => 'text/plain',
            'x-a' => '3', 'Empty' => []], 'missing'), $get, false];
        yield 'unknown status' => [new Response(599, body: 'odd'), $get, false];
        yield 'json' => [Response::json(['ok' => true], 201), $get, false];
    }

    #[DataProvider('stringResultProvider')]
    public function testStringResultsEncodeExactlyLikeTextResponses(string $body, Request $request, bool $forceClose): void
    {
        $close = $forceClose;
        $direct = ResponseEncoder::small($body, $request, $close);
        $expectedClose = $forceClose;
        $expected = ResponseEncoder::small(Response::text($body), $request, $expectedClose);
        self::assertSame($expected === null, $direct === null);
        self::assertSame($expectedClose, $close);
        if ($direct !== null) {
            self::assertSame(self::withoutDate($expected), self::withoutDate($direct));
            $general = implode('', iterator_to_array((new ResponseEncoder(Response::text($body), $request, $forceClose))->bytes(), false));
            self::assertSame(self::withoutDate($general), self::withoutDate($direct));
        }
    }

    public static function stringResultProvider(): iterable
    {
        yield 'text' => ["Hello\n", self::request(), false];
        yield 'empty' => ['', self::request(), false];
        yield 'forced close' => ['bye', self::request(), true];
        yield 'HTTP/1.0' => ['old', self::request(version: '1.0', headers: []), false];
        yield 'HEAD uses the general encoder' => ['hidden', self::request(method: 'HEAD'), false];
        yield 'sizeable body uses the general encoder' => [str_repeat('x', 7937), self::request(), false];
    }

    public function testHandlersMayReturnStringsOnPersistentConnections(): void
    {
        $output = $this->converse(
            ["GET /a HTTP/1.1\r\nHost: x\r\n\r\n", "HEAD /b HTTP/1.1\r\nHost: x\r\n\r\n", "GET /c HTTP/1.0\r\n\r\n"],
            static fn (Request $request): string => 'path ' . $request->path(),
        );
        self::assertSame(3, substr_count($output, " 200 OK\r\n"));
        self::assertSame(3, substr_count($output, "content-type: text/plain; charset=utf-8\r\n"));
        self::assertStringContainsString("content-length: 7\r\nconnection: keep-alive\r\ndate: ", $output);
        self::assertStringContainsString("\r\n\r\npath /aHTTP/1.1 200 OK\r\n", $output);
        self::assertStringNotContainsString('path /b', $output, 'HEAD sent a body.');
        self::assertStringEndsWith("HTTP/1.0 200 OK\r\ncontent-type: text/plain; charset=utf-8\r\ncontent-length: 7\r\n"
            . "connection: close\r\ndate: " . self::dateOf($output) . "\r\n\r\npath /c", $output);
    }

    public function testFactoryResponsesKeepTheirValidatedFields(): void
    {
        self::assertSame(['content-type' => ['text/plain; charset=utf-8']], Response::text('x')->normalizedHeaders());
        self::assertSame(['content-type' => ['application/json']], Response::json(['a' => 1])->normalizedHeaders());
        self::assertSame("content-type: text/plain; charset=utf-8\r\n", Response::text('x')->headerBlock());
        self::assertSame("content-type: application/json\r\n", Response::json(1)->headerBlock());
        // Caller fields still pass through validation and may replace the default.
        self::assertSame("content-type: text/html\r\nx-a: 1\r\n",
            Response::text('x', 200, ['Content-Type' => 'text/html', 'X-A' => '1'])->headerBlock());
        self::assertSame(['Content-Type' => 'text/plain; charset=utf-8', 'X-A' => '1'], Response::text('x', 200, ['X-A' => '1'])->headers);
        self::assertSame("content-type: application/json\r\nx-a: 1\r\n", Response::json(1, 200, ['X-A' => '1'])->headerBlock());
        $this->expectException(\InvalidArgumentException::class);
        Response::text('x', 200, ['X-Bad' => "a\nb"])->headerBlock();
    }

    #[DataProvider('cachedSecondProvider')]
    public function testSmallEncoderRefreshesItsDateWithTheClock(int $offset): void
    {
        $stale = time() + $offset;
        $staleDate = gmdate('D, d M Y H:i:s', $stale) . ' GMT';
        (new ReflectionProperty(ResponseEncoder::class, 'dateSecond'))->setValue(null, $stale);
        (new ReflectionProperty(ResponseEncoder::class, 'date'))->setValue(null, $staleDate);
        $before = time();
        $close = false;
        $keepAlive = ResponseEncoder::small('ok', self::request(), $close);
        $close = true;
        $closing = ResponseEncoder::small('ok', self::request(), $close);
        $after = time();
        $valid = [gmdate('D, d M Y H:i:s', $before) . ' GMT', gmdate('D, d M Y H:i:s', $after) . ' GMT'];
        self::assertContains(self::dateOf($keepAlive), $valid);
        self::assertContains(self::dateOf($closing), $valid);
        self::assertStringNotContainsString($staleDate, $keepAlive . $closing);
        self::assertStringContainsString("\r\nconnection: keep-alive\r\n", $keepAlive);
        self::assertStringContainsString("\r\nconnection: close\r\n", $closing);
    }

    public static function cachedSecondProvider(): iterable
    {
        yield 'forward rollover' => [-60];
        yield 'backward correction' => [60];
    }

    public function testEmptyBodyTrailersAndCancellationAppearOnDemand(): void
    {
        $parser = new Parser();
        $parser->append("GET / HTTP/1.1\r\nHost: x\r\n\r\n");
        $request = $parser->next();
        self::assertSame([], $request->trailers);
        self::assertSame(0, $request->body->size());
        self::assertSame('', $request->body->contents());
        self::assertSame($request->body, $request->body, 'The empty body changed identity.');
        $token = $request->cancellation;
        self::assertSame($token, $request->cancellation);
        self::assertFalse($token->isCancelled());
        $request->complete();
        self::assertTrue($token->isCancelled(), 'An observed token missed completion.');

        $parser->append("GET / HTTP/1.1\r\nHost: x\r\n\r\n");
        $late = $parser->next();
        $late->complete();
        self::assertTrue($late->cancellation->isCancelled(), 'A token first read after completion was live.');

        $supplied = new \Crab\Engine\Async\Cancellation();
        $body = new Body();
        $built = new Request('POST', '/', '1.1', ['host' => ['x']], $body, ['x-t' => ['1']], $supplied);
        self::assertSame([$body, ['x-t' => ['1']], $supplied], [$built->body, $built->trailers, $built->cancellation]);
        foreach (['body', 'trailers', 'cancellation', 'method'] as $property) {
            try {
                $built->{$property} = null;
                self::fail("Request::\${$property} was replaced.");
            } catch (\Error) {
            }
        }
    }

    public function testSuspendedHandlerInAReadTurnReceivesNoFurtherInputUntilItAnswers(): void
    {
        $order = [];
        $output = $this->converse(
            ["GET /slow HTTP/1.1\r\nHost: x\r\n\r\nGET /next HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n"],
            static function (Request $request) use (&$order): string {
                $order[] = 'start ' . $request->target;
                if ($request->target === '/slow') {
                    $suspension = EventLoop::getSuspension();
                    EventLoop::delay(0.02, static fn () => $suspension->resume());
                    $suspension->suspend();
                }
                $order[] = 'end ' . $request->target;
                return $request->target;
            },
            // The second request arrives while the first handler is suspended.
            splitAt: 37,
        );
        self::assertSame(['start /slow', 'end /slow', 'start /next', 'end /next'], $order);
        self::assertStringContainsString("\r\n\r\n/slowHTTP/1.1 200 OK\r\n", $output);
        self::assertStringEndsWith("\r\n\r\n/next", $output);
    }

    #[DataProvider('generalOnlyProvider')]
    public function testSmallEncoderDefersEverythingElseToTheGeneralEncoder(Response $response, Request $request): void
    {
        $close = false;
        self::assertNull(ResponseEncoder::small($response, $request, $close));
        self::assertFalse($close, 'A declined response changed the connection decision.');
    }

    public static function generalOnlyProvider(): iterable
    {
        $get = self::request();
        yield 'HEAD' => [Response::text('hidden'), self::request(method: 'HEAD')];
        yield 'streamed body' => [new Response(body: ['a', 'b']), $get];
        yield '204' => [new Response(204), $get];
        yield '205' => [new Response(205), $get];
        yield '304' => [new Response(304), $get];
        yield 'informational' => [new Response(101), $get];
        yield 'explicit length' => [new Response(headers: ['Content-Length' => '2'], body: 'ok'), $get];
        yield 'explicit connection' => [new Response(headers: ['connection' => 'close'], body: 'ok'), $get];
        yield 'explicit date' => [new Response(headers: ['DATE' => 'Sat, 03 Oct 2020 12:34:56 GMT'], body: 'ok'), $get];
        yield 'upgrade field' => [new Response(headers: ['Upgrade' => 'h2c'], body: 'ok'), $get];
        yield 'transfer encoding' => [new Response(headers: ['Transfer-Encoding' => 'chunked'], body: 'ok'), $get];
        yield 'sizeable body' => [Response::text(str_repeat('x', 7937)), $get];
        yield 'sizeable fields' => [new Response(headers: ['X-Fill' => str_repeat('x', 7937)], body: ''), $get];
    }

    public function testSmallEncoderStillValidatesFieldsOnEveryAttempt(): void
    {
        $response = new Response(headers: ['X-Bad' => "a\r\nb"], body: 'ok');
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $close = false;
                ResponseEncoder::small($response, self::request(), $close);
                self::fail('An invalid response field was encoded.');
            } catch (\InvalidArgumentException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testReferencedFieldsStayVisibleToTheSmallEncoder(): void
    {
        $value = 'one';
        $headers = ['X-Live' => &$value];
        $response = new Response(headers: $headers, body: 'ok');
        $seen = [];
        foreach (['one', 'two', 'three'] as $next) {
            $value = $next;
            $close = false;
            $seen[] = str_contains((string) ResponseEncoder::small($response, self::request(), $close), "x-live: {$next}\r\n");
        }
        self::assertSame([true, true, true], $seen, 'A referenced response field was cached.');
    }

    public function testRepeatedResponseFieldsAreValidatedIdenticallyFromTheMemo(): void
    {
        $first = (new Response(headers: ['X-Memo-Check' => ' padded '], body: ''))->normalizedHeaders();
        $second = (new Response(headers: ['X-Memo-Check' => ' padded '], body: ''))->normalizedHeaders();
        self::assertSame(['x-memo-check' => ['padded']], $first);
        self::assertSame($first, $second);
        // Enough distinct lines to cycle the bounded memo at least once.
        for ($index = 0; $index < 600; ++$index) {
            self::assertSame(['x-n' => [(string) $index]], (new Response(headers: ['X-N' => (string) $index]))->normalizedHeaders());
        }
        self::assertSame($first, (new Response(headers: ['X-Memo-Check' => ' padded '], body: ''))->normalizedHeaders());
    }

    public function testPersistentRequestsShareOneReadTurnWithoutLosingOrder(): void
    {
        $loop = $this->newLoop();
        [$server, $client] = $this->pair();
        $connection = $this->connection($loop, $server);
        $seen = [];
        (new HttpServer(static function (Request $request) use (&$seen): Response {
            $seen[] = $request->target;
            return Response::text($request->target);
        }))->attach($connection);
        $output = '';
        $next = 0;
        $send = static function () use ($client, &$next): void {
            fwrite($client, "GET /r{$next} HTTP/1.1\r\nHost: x\r\n" . ($next === 4 ? "Connection: close\r\n" : '') . "\r\n");
            ++$next;
        };
        $watchdog = $loop->delay(2, static function () use ($connection): void {
            $connection->close();
            throw new RuntimeException('Persistent exchange timed out.');
        });
        $loop->onReadable($client, static function (string $id) use ($loop, $client, &$output, $send, &$next, $watchdog): void {
            $output .= fread($client, 65536);
            if (feof($client)) {
                $loop->cancel($id);
                $loop->cancel($watchdog);
                return;
            }
            if (substr_count($output, 'HTTP/1.1 200 OK') === $next && $next < 5) {
                $send();
            }
        });
        $send();
        $loop->run();
        self::assertSame(['/r0', '/r1', '/r2', '/r3', '/r4'], $seen);
        self::assertSame(4, substr_count($output, "connection: keep-alive\r\n"));
        self::assertStringEndsWith("connection: close\r\ndate: " . self::dateOf($output) . "\r\n\r\n/r4", $output);
        self::assertTrue($connection->isClosed());
        self::assertSame([], $loop->getIdentifiers(), 'Persistent exchange leaked loop registrations.');
    }

    public function testSmallResponsesLargerThanTheWriteCapacityStillArriveIntact(): void
    {
        $loop = $this->newLoop();
        [$server, $client] = $this->pair();
        $connection = $this->connection($loop, $server, 16);
        (new HttpServer(static fn (Request $request): Response => Response::text(str_repeat($request->target, 40))))
            ->attach($connection);
        $output = '';
        $watchdog = $loop->delay(2, static function () use ($connection): void {
            $connection->close();
            throw new RuntimeException('Tiny-capacity exchange timed out.');
        });
        $loop->onReadable($client, static function (string $id) use ($loop, $client, &$output, $watchdog): void {
            $output .= fread($client, 65536);
            if (feof($client)) {
                $loop->cancel($id);
                $loop->cancel($watchdog);
            }
        });
        fwrite($client, "GET /a HTTP/1.1\r\nHost: x\r\n\r\nGET /b HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        $loop->run();
        self::assertSame(2, substr_count($output, 'HTTP/1.1 200 OK'));
        self::assertStringContainsString("\r\n\r\n" . str_repeat('/a', 40) . 'HTTP/1.1 200 OK', $output);
        self::assertStringEndsWith("\r\n\r\n" . str_repeat('/b', 40), $output);
    }

    public function testSuspendedReadTurnStopsWatchingUntilItsConsumerReturns(): void
    {
        $loop = $this->newLoop();
        [$server, $client] = $this->pair();
        $connection = $this->connection($loop, $server);
        $received = [];
        $watching = [];
        $suspension = null;
        $state = new ReflectionProperty(Connection::class, 'readWatching');
        $connection->onData(static function (Connection $connection, string $bytes) use ($loop, &$received, &$suspension): void {
            $received[] = $bytes;
            if (count($received) === 1) {
                $suspension = $loop->getSuspension();
                $suspension->suspend();
            }
        });
        fwrite($client, 'first');
        $loop->delay(0.01, static function () use ($client): void { fwrite($client, 'second'); });
        $loop->delay(0.03, static function () use ($connection, $state, &$watching): void {
            $watching[] = $state->getValue($connection);
        });
        $loop->delay(0.04, static function () use (&$suspension): void { $suspension->resume(); });
        $watchdog = $loop->delay(2, static function () use ($loop): void { $loop->stop(); });
        $poll = $loop->repeat(0.001, static function () use ($loop, &$received): void {
            if (count($received) === 2) { $loop->stop(); }
        });
        $loop->run();
        $loop->cancel($watchdog);
        $loop->cancel($poll);
        self::assertSame([false], $watching, 'A suspended read turn kept polling a readable socket.');
        self::assertSame(['first', 'second'], $received, 'Bytes arriving during a suspended read turn were lost.');
        self::assertTrue($state->getValue($connection), 'Read interest was not restored after the turn.');
    }

    public function testPauseAndResumeWithinOneReadTurnKeepsReceiving(): void
    {
        $loop = $this->newLoop();
        [$server, $client] = $this->pair();
        $connection = $this->connection($loop, $server);
        $received = [];
        $connection->onData(static function (Connection $connection, string $bytes) use ($client, &$received): void {
            $received[] = $bytes;
            $connection->pauseRead();
            $connection->resumeRead();
            if (count($received) < 3) {
                fwrite($client, 'more' . count($received));
            }
        });
        fwrite($client, 'start');
        $watchdog = $loop->delay(2, static function () use ($loop): void { $loop->stop(); });
        $poll = $loop->repeat(0.001, static function () use ($loop, &$received): void {
            if (count($received) === 3) { $loop->stop(); }
        });
        $loop->run();
        $loop->cancel($watchdog);
        $loop->cancel($poll);
        self::assertSame(['start', 'more1', 'more2'], $received);
    }

    public function testPauseWithinAReadTurnStaysPausedAfterIt(): void
    {
        $loop = $this->newLoop();
        [$server, $client] = $this->pair();
        $connection = $this->connection($loop, $server);
        $received = [];
        $connection->onData(static function (Connection $connection, string $bytes) use ($client, &$received): void {
            $received[] = $bytes;
            $connection->pauseRead();
            fwrite($client, 'held');
        });
        fwrite($client, 'start');
        $loop->delay(0.03, $this->guardCallback(static function () use ($connection, &$received): void {
            self::assertSame(['start'], $received, 'A paused connection delivered bytes.');
            $connection->resumeRead();
        }));
        $watchdog = $loop->delay(2, static function () use ($loop): void { $loop->stop(); });
        $poll = $loop->repeat(0.001, static function () use ($loop, &$received): void {
            if (count($received) === 2) { $loop->stop(); }
        });
        $loop->run();
        $loop->cancel($watchdog);
        $loop->cancel($poll);
        self::assertSame(['start', 'held'], $received);
    }

    /**
     * Sends each message after the previous response arrived, then returns
     * everything the server wrote until it closed or the last answer arrived.
     *
     * @param list<string> $messages
     */
    private function converse(array $messages, callable $handler, ?int $splitAt = null): string
    {
        $loop = $this->newLoop();
        [$server, $client] = $this->pair();
        $connection = $this->connection($loop, $server);
        (new HttpServer($handler))->attach($connection);
        $output = '';
        $next = 0;
        $expected = 0;
        $send = static function () use ($loop, $client, $messages, &$next, &$expected, $splitAt): void {
            $message = $messages[$next++];
            $expected += substr_count($message, "\r\n\r\n");
            if ($splitAt === null) {
                fwrite($client, $message);
                return;
            }
            fwrite($client, substr($message, 0, $splitAt));
            $loop->delay(0.005, static function () use ($client, $message, $splitAt): void {
                fwrite($client, substr($message, $splitAt));
            });
        };
        $watchdog = $loop->delay(2, static function () use ($connection): void {
            $connection->close();
            throw new RuntimeException('Conversation timed out.');
        });
        $loop->onReadable($client, static function (string $id) use ($loop, $client, $connection, $messages, &$output, &$next, &$expected, $send, $watchdog): void {
            $output .= fread($client, 65536);
            $answered = preg_match_all('/HTTP\/1\.[01] \d{3} /', $output);
            if (feof($client) || ($answered === $expected && $next === count($messages))) {
                $loop->cancel($id);
                $loop->cancel($watchdog);
                $connection->close();
                return;
            }
            if ($answered === $expected) {
                $send();
            }
        });
        $send();
        $loop->run();
        self::assertSame([], $loop->getIdentifiers(), 'Conversation leaked loop registrations.');
        return $output;
    }

    /**
     * @param array<string, list<string>> $headers
     */
    private static function request(string $method = 'GET', string $version = '1.1', array $headers = ['host' => ['x']]): Request
    {
        return new Request($method, '/', $version, $headers, new Body());
    }

    private static function withoutDate(string $wire): string
    {
        return preg_replace('/\r\ndate: [^\r]+\r\n/', "\r\ndate: -\r\n", $wire);
    }

    private static function dateOf(string $wire): string
    {
        preg_match_all('/\r\ndate: ([^\r]+)\r\n/', $wire, $matches);
        return (string) end($matches[1]);
    }

    private function newLoop(): StreamSelectDriver
    {
        $loop = new StreamSelectDriver();
        EventLoop::setDriver($loop);
        $this->deferCleanup(static function () use ($loop): void {
            foreach ($loop->getIdentifiers() as $id) { $loop->cancel($id); }
        });
        return $loop;
    }

    /**
     * @return array{resource, resource}
     */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        stream_set_blocking($pair[1], false);
        $this->deferCleanup(static function () use ($pair): void {
            foreach ($pair as $stream) { if (is_resource($stream)) { fclose($stream); } }
        });
        return $pair;
    }

    private function connection(StreamSelectDriver $loop, mixed $stream, int $capacity = 1048576): Connection
    {
        $connection = new Connection($loop, $stream, 'test-peer', $capacity);
        $this->deferCleanup($connection->close(...));
        return $connection;
    }
}
