<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Http\Body;
use Crab\Engine\Http\HttpException;
use Crab\Engine\Http\Parser;
use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Http\Upgrade;
use Crab\Engine\Async\Async;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\Listener;
use Crab\Engine\Process\Worker;
use Crab\Engine\Server\HttpServer;
use Crab\Engine\WorkerGroup;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Generator;
use RuntimeException;

final class HttpTest extends TestCase
{
    public function testParsesFragmentedFixedBodiesAndIsolatesPipelinedRequests(): void
    {
        $parser = new Parser();
        $requests = [];
        $wire = "POST /one HTTP/1.1\r\nHost: example.com\r\nContent-Length: 4\r\n\r\na\0bc"
            . "GET /two HTTP/1.1\r\nHost: example.com\r\n\r\n";
        foreach (str_split($wire) as $byte) {
            $parser->append($byte);
            if (($request = $parser->next()) !== null) {
                $requests[] = $request;
            }
        }
        self::assertTrue(count($requests) === 2 && $requests[0]->body->contents() === "a\0bc", 'Fragmented fixed bodies failed.');
        self::assertTrue($requests[1]->target === '/two' && $requests[1]->body->size() === 0, 'Pipelined request isolation failed.');
    }

    public function testPreservesDoubleSlashOriginFormPaths(): void
    {
        $parser = new Parser();
        $parser->append("GET //example/path?x=1 HTTP/1.1\r\nHost: x\r\n\r\n");
        self::assertTrue($parser->next()?->path() === '//example/path', 'Origin-form path was misread as an authority.');
    }

    public function testParsesFragmentedChunkedBinaryBodiesAndTrailers(): void
    {
        $parser = new Parser();
        $wire = "POST / HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\nTrailer: X-Checksum\r\n\r\n"
            . "3;foo=\"a b\"\r\na\0b\r\n2\r\ncd\r\n0\r\nX-Checksum: ok\r\n\r\n";
        $request = null;
        foreach (str_split($wire, 2) as $bytes) {
            $parser->append($bytes);
            $request = $parser->next() ?? $request;
        }
        self::assertTrue($request?->body->contents() === "a\0bcd", 'Fragmented chunks failed.');
        self::assertTrue($request->trailers === ['x-checksum' => ['ok']], 'Trailer parsing failed.');
    }

    public function testEmptyBodyApisRemainEmptyAfterRewindAndEmptyAppends(): void
    {
        $body = new Body();
        self::assertSame(0, $body->size());
        self::assertSame('', $body->read());
        self::assertSame('', $body->contents());
        self::assertSame('', (string) $body);
        self::assertSame([], iterator_to_array($body->chunks(2)));
        $body->append('');
        $body->rewind();
        self::assertSame(0, $body->size());
        self::assertSame('', $body->read(1));
        self::assertSame('', $body->contents());
        self::assertSame([], iterator_to_array($body->chunks()));
    }

    #[DataProvider('nonpositiveBodyReadSizes')]
    public function testEmptyBodyRejectsNonpositiveReadSizes(int $size): void
    {
        $body = new Body();
        $this->expectException(\InvalidArgumentException::class);
        $body->read($size);
    }

    #[DataProvider('nonpositiveBodyReadSizes')]
    public function testEmptyBodyRejectsNonpositiveChunkSizes(int $size): void
    {
        $body = new Body();
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array($body->chunks($size));
    }

    public static function nonpositiveBodyReadSizes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testEmptyBodyAcceptsLaterBytesAndMaterializationPreservesCursor(): void
    {
        $body = new Body();
        $body->append('');
        $body->rewind();
        self::assertSame('', $body->read());
        self::assertSame('', $body->contents());
        self::assertSame([], iterator_to_array($body->chunks()));
        $body->append('ab');
        $body->append('');
        $body->append("\0cd");
        self::assertSame(5, $body->size());
        self::assertSame('', $body->read(), 'Appending leaves the cursor after the written bytes.');
        self::assertSame("ab\0cd", $body->contents());
        self::assertSame('', $body->read(), 'Materialization must preserve an EOF cursor.');
        $body->rewind();
        self::assertSame('ab', $body->read(2));
        self::assertSame("ab\0cd", $body->contents());
        self::assertSame("\0", $body->read(1), 'contents() changed the current read position.');
        self::assertSame("ab\0cd", (string) $body);
        self::assertSame('cd', $body->read(2), 'String conversion changed the current read position.');
        $body->append('ef');
        self::assertSame(7, $body->size());
        self::assertSame("ab\0cdef", $body->contents());
        self::assertSame(['ab', "\0c", 'de', 'f'], iterator_to_array($body->chunks(2)));
        self::assertSame('', $body->read(), 'Chunk iteration should leave the cursor at EOF.');
    }

    public function testSpoolsLargeBodiesAndReadsThemAsChunks(): void
    {
        $body = new Body();
        $body->append(str_repeat('x', 150000));
        $body->rewind();
        self::assertTrue(strlen($body->read(17)) === 17 && strlen($body->contents()) === 150000, 'Spooled body read failed.');
        self::assertTrue(strlen(implode('', iterator_to_array($body->chunks()))) === 150000, 'Spooled body streaming failed.');
    }

    #[DataProvider('invalidRequestProvider')]
    public function testRejectsAmbiguousOrMalformedRequests(string $wire, int $status, int $maxBody = 1048576): void
    {
        $parser = new Parser(maxBodyBytes: $maxBody);
        try {
            $parser->append($wire);
            $parser->next();
        } catch (HttpException $error) {
            self::assertSame($status, $error->status, $error->getMessage());
            return;
        }
        self::fail('Invalid HTTP request was accepted.');
    }

    public static function invalidRequestProvider(): iterable
    {
        yield 'duplicate-content-length' => ["POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 1\r\nContent-Length: 1\r\n\r\na", 400];
        yield 'list-content-length' => ["POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 1, 1\r\n\r\na", 400];
        yield 'length-and-transfer-encoding' => ["POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 1\r\nTransfer-Encoding: chunked\r\n\r\n", 400];
        yield 'missing-host' => ["GET / HTTP/1.1\r\n\r\n", 400];
        yield 'duplicate-host' => ["GET / HTTP/1.1\r\nHost: x\r\nHost: y\r\n\r\n", 400];
        yield 'whitespace-before-colon' => ["GET / HTTP/1.1\r\nHost : x\r\n\r\n", 400];
        yield 'obsolete-folding' => ["GET / HTTP/1.1\r\nHost: x\r\n folded: x\r\n\r\n", 400];
        yield 'unsupported-version' => ["GET / HTTP/2.0\r\nHost: x\r\n\r\n", 505];
        yield 'negative-length' => ["POST / HTTP/1.1\r\nHost: x\r\nContent-Length: -1\r\n\r\n", 400];
        yield 'overflowing-length' => ["POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 18446744073709551616\r\n\r\n", 413];
        yield 'body-limit' => ["POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 5\r\n\r\nhello", 413, 4];
        yield 'duplicate-chunked-coding' => ["POST / HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked, chunked\r\n\r\n", 400];
        yield 'unsupported-coding' => ["POST / HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: gzip, chunked\r\n\r\n", 501];
        yield 'invalid-chunk-terminator' => ["POST / HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n1\r\nxXX", 400];
        yield 'overflowing-chunk' => ["POST / HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\nFFFFFFFFFFFFFFFF\r\n", 413];
        yield 'forbidden-trailer' => ["POST / HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n0\r\nContent-Length: 0\r\n\r\n", 400];
        yield 'unsupported-expectation' => ["POST / HTTP/1.1\r\nHost: x\r\nExpect: other\r\n\r\n", 417];
    }

    public function testEmitsContinueSignalOnceBeforeReceivingTheBody(): void
    {
        $parser = new Parser();
        $parser->append("POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 2\r\nExpect: 100-continue\r\n\r\n");
        self::assertTrue($parser->next() === null && $parser->takeContinue() && !$parser->takeContinue(), '100 Continue must be emitted once.');
        $parser->append('ok');
        self::assertTrue($parser->next()?->body->contents() === 'ok', 'Expected body was lost.');
    }

    public function testPreservesPipelineHandlerAndResponseOrder(): void
    {
        $order = [];
        $output = $this->exchange(
            "GET /one HTTP/1.1\r\nHost: x\r\n\r\nGET /two HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n",
            function (Request $request) use (&$order): Response {
                $order[] = $request->target;
                return Response::text($request->target);
            },
        );
        self::assertTrue($order === ['/one', '/two'], 'Pipeline handler order changed.');
        self::assertTrue(substr_count($output, 'HTTP/1.1 200 OK') === 2 && strpos($output, '/one') < strpos($output, '/two'), 'Pipeline response order changed.');
    }

    public function testHeadSuppressesBodyButReportsItsLength(): void
    {
        $output = $this->exchange("HEAD / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n", fn() => Response::text('hidden'));
        self::assertTrue(str_contains($output, "content-length: 6\r\n") && !str_contains($output, 'hidden'), 'HEAD emitted a body.');
    }

    #[DataProvider('bodylessStatusProvider')]
    public function testSuppressesBodyAndTransferEncodingForBodylessStatuses(int $status): void
    {
        $output = $this->exchange("GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n", fn() => new Response($status, [], 'hidden'));
        self::assertTrue(!str_contains($output, 'hidden') && !str_contains($output, 'transfer-encoding'), 'Bodyless status emitted body framing.');
    }

    public static function bodylessStatusProvider(): iterable
    {
        yield 'no-content' => [204];
        yield 'reset-content' => [205];
        yield 'not-modified' => [304];
    }

    public function testStreamsChunkedResponsesThroughTinyOutputBuffers(): void
    {
        $output = $this->exchange("GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n", fn() => new Response(body: (function (): Generator {
            for ($i = 0; $i < 100; ++$i) {
                yield str_repeat('z', 4096);
            }
        })()), capacity: 128);
        self::assertTrue(str_contains($output, "transfer-encoding: chunked\r\n") && str_ends_with($output, "0\r\n\r\n"), 'Streaming response framing failed.');
        self::assertTrue(substr_count($output, str_repeat('z', 4096)) === 100, 'Streaming response lost data under pressure.');
    }

    public function testStreamsHttp10ResponsesWithConnectionCloseFraming(): void
    {
        $output = $this->exchange("GET / HTTP/1.0\r\n\r\n", fn() => new Response(body: (function (): Generator { yield 'old-client'; })()));
        self::assertTrue(!str_contains($output, 'transfer-encoding') && str_ends_with($output, 'old-client'), 'HTTP/1.0 streaming framing failed.');
    }

    public function testConvertsHandlerExceptionsIntoPrivateServerErrors(): void
    {
        $output = $this->exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", fn() => throw new RuntimeException('private failure'));
        self::assertTrue(str_starts_with($output, 'HTTP/1.1 500') && !str_contains($output, 'private failure'), 'Handler exception was not isolated.');
        self::assertSame(['HTTP handler failed: private failure'], $this->reports, 'The failure must be reported, not only answered.');
    }

    public function testAResponseThatFailsPartWayIsReportedAndCutOff(): void
    {
        $output = $this->exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", fn() => new Response(body: (function (): Generator {
            yield 'sent';
            throw new RuntimeException('producer failure');
        })()));
        self::assertTrue(str_starts_with($output, 'HTTP/1.1 200') && str_contains($output, 'sent'), 'The part produced before the failure was not sent.');
        self::assertStringNotContainsString("0\r\n\r\n", $output, 'A response that failed must not look complete.');
        self::assertSame(['HTTP response failed part-way: producer failure'], $this->reports);
    }

    public function testClientErrorsAreAnsweredAndNotReported(): void
    {
        $output = $this->exchange("GET / HTTP/1.1\r\nHost: x\r\nHost: y\r\n\r\n", fn() => 'unreachable');
        self::assertStringStartsWith('HTTP/1.1 400', $output);
        self::assertSame([], $this->reports, 'A malformed request is the client\'s mistake, not a failure of ours.');
    }

    public function testUpgradeTransfersBufferedBytesAndPreservesResponseFraming(): void
    {
        $seen = '';
        $output = $this->exchange("GET /ws HTTP/1.1\r\nHost: x\r\nConnection: Upgrade\r\nUpgrade: demo\r\n\r\nfirst-frame", function () use (&$seen): Upgrade {
            return new Upgrade(new Response(101, ['Upgrade' => 'demo', 'Connection' => 'Upgrade']),
                function (Connection $connection, Request $request, string $unread) use (&$seen): void {
                    $seen = $unread;
                    $connection->tryWrite('upgraded');
                    $connection->end();
                });
        });
        self::assertTrue($seen === 'first-frame' && str_ends_with($output, 'upgraded'), 'Upgrade lost buffered bytes.');
        self::assertTrue(!str_contains($output, 'content-length') && !str_contains($output, 'transfer-encoding'), '101 contained forbidden framing.');
    }

    public function testSuspendingHandlersCannotReorderPipelinedRequests(): void
    {
        $order = [];
        $output = $this->exchange("GET /one HTTP/1.1\r\nHost: x\r\n\r\nGET /two HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n", function (Request $request) use (&$order): string {
            if ($request->target === '/one') {
                $suspension = EventLoop::current()->getSuspension();
                EventLoop::current()->delay(0.005, fn() => $suspension->resume());
                $suspension->suspend();
            }
            $order[] = $request->target;
            return $request->target;
        });
        self::assertTrue($order === ['/one', '/two'], 'Suspended handler reordered a pipeline.');
    }

    public function testPeerEofPreservesCompletePipelinedRequests(): void
    {
        $output = $this->exchange("GET /one HTTP/1.1\r\nHost: x\r\n\r\nGET /two HTTP/1.1\r\nHost: x\r\n\r\n",
            fn(Request $request) => $request->target, halfClose: true);
        self::assertTrue(substr_count($output, 'HTTP/1.1 200 OK') === 2 && str_ends_with($output, '/two'), 'Receive EOF dropped a pipelined request.');
    }

    public function testPeerEofRejectsTruncatedBodies(): void
    {
        $output = $this->exchange("POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 5\r\n\r\na", fn() => 'unreachable', halfClose: true);
        self::assertTrue(str_starts_with($output, 'HTTP/1.1 400'), 'Truncated body was reused after EOF.');
    }

    public function testInvalidApplicationHeadersProduceServerErrors(): void
    {
        $output = $this->exchange("GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n", fn() => new Response(200, ['X-Test' => "x\r\ny"]));
        self::assertTrue(str_starts_with($output, 'HTTP/1.1 500'), 'Invalid application header was blamed on the client.');
        self::assertSame(['HTTP handler failed: Invalid response header.'], $this->reports);
    }

    public function testHeadAcceptsExplicitRepresentationLength(): void
    {
        $output = $this->exchange("HEAD / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n", fn() => new Response(200, ['Content-Length' => '2048']));
        self::assertTrue(str_contains($output, "content-length: 2048\r\n"), 'HEAD metadata length was rejected.');
    }

    public function testSlowReadersDoNotLoseOrDeadlockStreamedResponses(): void
    {
        $output = $this->exchange("GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n", fn() => new Response(body: (function (): Generator {
            for ($i = 0; $i < 400; ++$i) { yield str_repeat('q', 4096); }
        })()), capacity: 256, readDelay: 0.02);
        self::assertTrue(substr_count($output, str_repeat('q', 4096)) === 400 && str_ends_with($output, "0\r\n\r\n"), 'Slow reader caused streaming loss or deadlock.');
    }

    public function testContinueHandshakeDoesNotDeadlockAWaitingClient(): void
    {
        $loop = $this->newLoop();
        [$serverSocket, $client] = $this->pair();
        stream_set_blocking($client, false);
        $connection = $this->connection($loop, $serverSocket);
        (new HttpServer(fn(Request $request) => $request->body->contents()))->attach($connection);
        fwrite($client, "POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 2\r\nExpect: 100-continue\r\nConnection: close\r\n\r\n");
        $output = '';
        $sent = false;
        $watchdog = $loop->delay(1, fn() => throw new RuntimeException('100 Continue deadlocked.'));
        $loop->onReadable($client, function (string $id) use ($loop, $client, &$output, &$sent, $watchdog): void {
            $output .= fread($client, 65536);
            if (!$sent && str_contains($output, "100 Continue\r\n\r\n")) {
                $sent = true;
                fwrite($client, 'ok');
            }
            if (feof($client)) {
                $loop->cancel($id);
                $loop->cancel($watchdog);
                fclose($client);
            }
        });
        $loop->run();
        self::assertTrue(substr_count($output, '100 Continue') === 1 && str_ends_with($output, 'ok'), 'Interactive Continue response failed.');
        self::assertTrue($loop->getIdentifiers() === [], 'Continue exchange leaked watchers.');
    }

    public function testResponseTimeoutCancelsHandlerAndRunsFinally(): void
    {
        $cleanedUp = false;
        $output = $this->exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", function (Request $request) use (&$cleanedUp): string {
            try {
                Async::sleep(EventLoop::current(), 10, $request->cancellation);
                return 'too late';
            } finally {
                $cleanedUp = true;
            }
        }, ['responseTimeout' => 0.01]);
        self::assertTrue($cleanedUp && $output === '', 'Request timeout did not cancel the handler and execute cleanup.');
    }

    public function testCloseRunsCancellationCleanupEvenWhenAnEarlierObserverStopsTheLoop(): void
    {
        $loop = $this->newLoop();
        [$serverSocket, $client] = $this->pair();
        $connection = $this->connection($loop, $serverSocket);
        $connection->onClose(static function () use ($loop): void { $loop->stop(); });
        $cleanedUp = false;
        (new HttpServer(function (Request $request) use ($loop, &$cleanedUp): string {
            try {
                Async::sleep($loop, 10, $request->cancellation);
                return 'too late';
            } finally {
                $cleanedUp = true;
            }
        }))->attach($connection);
        fwrite($client, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
        $loop->delay(0.01, static function () use ($connection): void { $connection->close(); });
        $loop->run();
        fclose($client);
        self::assertTrue($cleanedUp && $loop->getIdentifiers() === [], 'Connection close skipped cooperative handler cleanup.');
    }

    #[DataProvider('deadlineProvider')]
    public function testEnforcesAbsoluteIncompleteRequestDeadlines(string $wire, string $option): void
    {
        $output = $this->exchange($wire, fn() => 'unreachable', [$option => 0.01]);
        self::assertStringStartsWith('HTTP/1.1 408', $output);
    }

    public static function deadlineProvider(): iterable
    {
        yield 'headers' => ["GET / HTTP/1.1\r\nHost: x\r\n", 'headerTimeout'];
        yield 'body' => ["POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 4\r\n\r\na", 'bodyTimeout'];
    }

    public function testLaterBodyDeadlineSurvivesTheEarlierHeaderTimer(): void
    {
        $output = $this->exchangeFragments([
            [0, "POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 4\r\nConnection: close\r\n\r\na"],
            [0.12, 'bcd'],
        ], static fn(Request $request): string => $request->body->contents(), [
            'headerTimeout' => 0.03,
            'bodyTimeout' => 0.5,
        ]);
        self::assertStringStartsWith('HTTP/1.1 200', $output);
        self::assertStringEndsWith('abcd', $output);
    }

    public function testEarlierBodyDeadlineReplacesTheLaterHeaderTimer(): void
    {
        $handled = false;
        $output = $this->exchangeFragments([
            [0, "POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 4\r\nConnection: close\r\n\r\na"],
            [0.3, 'bcd'],
        ], static function () use (&$handled): string {
            $handled = true;
            return 'too late';
        }, [
            'headerTimeout' => 1,
            'bodyTimeout' => 0.05,
        ]);
        self::assertStringStartsWith('HTTP/1.1 408', $output);
        self::assertFalse($handled, 'A body completed after its deadline reached the handler.');
    }

    public function testPartialBodyDoesNotRefreshItsAbsoluteDeadline(): void
    {
        $handled = false;
        $output = $this->exchangeFragments([
            [0, "POST / HTTP/1.1\r\nHost: x\r\nContent-Length: 4\r\nConnection: close\r\n\r\na"],
            [0.06, 'b'],
            [0.14, 'c'],
            [0.3, 'd'],
        ], static function () use (&$handled): string {
            $handled = true;
            return 'too late';
        }, [
            'headerTimeout' => 0.03,
            'bodyTimeout' => 0.2,
        ]);
        self::assertStringStartsWith('HTTP/1.1 408', $output);
        self::assertFalse($handled, 'Receiving partial body bytes refreshed the body deadline.');
    }

    public function testReusedHeaderTimerCancelsTheCurrentResponseAtItsDeadline(): void
    {
        $cleanedUp = false;
        $survivedHeaderDeadline = false;
        $output = $this->exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", static function (Request $request) use (&$cleanedUp, &$survivedHeaderDeadline): string {
            try {
                Async::sleep(EventLoop::current(), 0.07, $request->cancellation);
                $survivedHeaderDeadline = true;
                Async::sleep(EventLoop::current(), 10, $request->cancellation);
                return 'too late';
            } finally {
                $cleanedUp = true;
            }
        }, ['headerTimeout' => 0.03, 'responseTimeout' => 0.12]);
        self::assertSame('', $output, 'Response expiry must close without writing a request-timeout response.');
        self::assertTrue($survivedHeaderDeadline, 'The previous header deadline expired the current response early.');
        self::assertTrue($cleanedUp, 'The current response timeout did not cancel its handler.');
    }

    public function testReusedTimerPreservesTheIdleDeadlineBetweenRequests(): void
    {
        $output = $this->exchangeFragments([
            [0, "GET /one HTTP/1.1\r\nHost: x\r\n\r\n"],
            [0.12, "GET /two HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n"],
        ], static fn(Request $request): string => $request->target, [
            'headerTimeout' => 0.03,
            'idleTimeout' => 0.5,
        ]);
        self::assertSame(2, substr_count($output, 'HTTP/1.1 200'));
        self::assertStringContainsString('/one', $output);
        self::assertStringEndsWith('/two', $output);
    }

    public function testReusedTimerClosesAnIdleConnectionWithout408(): void
    {
        $output = $this->exchange("GET / HTTP/1.1\r\nHost: x\r\n\r\n", static fn(): string => 'ok', [
            'headerTimeout' => 0.02,
            'idleTimeout' => 0.08,
        ]);
        self::assertSame(1, substr_count($output, 'HTTP/1.1 '));
        self::assertStringStartsWith('HTTP/1.1 200', $output);
        self::assertStringEndsWith('ok', $output);
    }

    #[DataProvider('activityProvider')]
    public function testWorkerRetainsSuspendedHandlersAndProducersThroughAsynchronousFinally(string $activity): void
    {
        $loop = $this->newLoop();
        $bound = Listener::bind('tcp://127.0.0.1:0');
        $this->deferCleanup(static function () use ($bound): void { if (is_resource($bound)) { fclose($bound); } });
        $address = 'tcp://' . stream_socket_get_name($bound, false);
        $peer = stream_socket_client($address, $errno, $error, 1);
        self::assertIsResource($peer);
        $this->deferCleanup(static function () use ($peer): void { if (is_resource($peer)) { fclose($peer); } });
        stream_set_blocking($peer, false);
        fwrite($peer, "GET / HTTP/1.1\r\nHost: local\r\n\r\n");
        $connection = null;
        $worker = null;
        $cleanupStarted = false;
        $cleanupFinished = false;
        $timedOut = false;
        $http = new HttpServer(static function (Request $request) use ($activity, $loop, &$cleanupStarted, &$cleanupFinished, &$worker, &$connection): Response|string {
            $wait = static function () use ($request, $loop, &$cleanupStarted, &$cleanupFinished, &$worker, &$connection): void {
                $loop->delay(0.005, $worker->stop(...));
                $loop->delay(0.015, static function () use (&$connection): void { $connection->close(); });
                try {
                    Async::sleep($loop, 60, $request->cancellation);
                } finally {
                    $cleanupStarted = true;
                    Async::sleep($loop, 0.01);
                    $cleanupFinished = true;
                }
            };
            if ($activity === 'handler') {
                $wait();
                return 'done';
            }
            return new Response(body: (static function () use ($wait): Generator {
                // Force several deferred output turns before suspending.
                for ($i = 0; $i < 100; ++$i) { yield str_repeat('x', 4096); }
                $wait();
            })());
        });
        $group = new WorkerGroup('http-cleanup');
        $group->listen($address, static function (Connection $accepted) use ($http, &$connection): void {
            $connection = $accepted;
            $http->attach($accepted);
        });
        $group->onStart(static function (Worker $worker) use ($peer, &$timedOut): void {
            $worker->loop->onReadable($peer, static function () use ($peer): void { fread($peer, 65536); });
            $worker->loop->delay(1, static function () use ($worker, &$timedOut): void {
                $timedOut = true;
                $worker->stop();
            });
        });
        $worker = new Worker($loop, $group, 0, ['tcp' => [$bound], 'udp' => []], 0.2);
        $worker->run();
        fclose($peer);
        self::assertFalse($timedOut, 'HTTP activity fixture never reached its cleanup phase.');
        self::assertTrue($cleanupStarted && $cleanupFinished, 'Worker abandoned asynchronous HTTP cleanup: ' . $activity);
        self::assertTrue($loop->getIdentifiers() === [] && $worker->stats()['connections'] === 0, 'HTTP activity drain leaked resources.');
    }

    public static function activityProvider(): iterable
    {
        yield 'handler' => ['handler'];
        yield 'streaming-producer' => ['streaming-producer'];
    }

    private function exchange(string $wire, callable $handler, array $options = [], int $capacity = 1024, bool $halfClose = false, float $readDelay = 0): string
    {
        $loop = $this->newLoop();
        [$serverSocket, $client] = $this->pair();
        stream_set_blocking($client, false);
        $connection = $this->connection($loop, $serverSocket, $capacity);
        (new HttpServer($handler, ...$options))->attach($connection);
        $output = '';
        $offset = 0;
        $watchdog = $loop->delay(2, function () use ($connection): void {
            $connection->close();
            throw new RuntimeException('HTTP exchange timed out.');
        });
        $writer = $loop->onWritable($client, $this->guardCallback(function (string $id) use ($loop, $client, $wire, &$offset, $halfClose): void {
            $written = fwrite($client, substr($wire, $offset, 8192));
            self::assertTrue($written !== false, 'Client write failed.');
            $offset += $written;
            if ($offset === strlen($wire)) {
                $loop->cancel($id);
                if ($halfClose) {
                    stream_socket_shutdown($client, STREAM_SHUT_WR);
                }
            }
        }));
        $reader = $loop->onReadable($client, function (string $id) use ($loop, $client, &$output, $watchdog, $writer): void {
            $output .= fread($client, 65536);
            if (feof($client)) {
                $loop->cancel($id);
                $loop->cancel($writer);
                $loop->cancel($watchdog);
                fclose($client);
            }
        });
        if ($readDelay > 0) {
            $loop->disable($reader);
            $loop->delay($readDelay, static function () use ($loop, $reader): void { $loop->enable($reader); });
        }
        $loop->run();
        self::assertTrue($connection->isClosed(), 'Server connection did not close.');
        self::assertTrue($loop->getIdentifiers() === [], 'HTTP connection leaked loop registrations.');
        return $output;
    }

    /**
     * @param list<array{float|int, string}> $fragments Bytes sent at offsets from loop startup.
     */
    private function exchangeFragments(array $fragments, callable $handler, array $options): string
    {
        $loop = $this->newLoop();
        [$serverSocket, $client] = $this->pair();
        stream_set_blocking($client, false);
        $connection = $this->connection($loop, $serverSocket);
        (new HttpServer($handler, ...$options))->attach($connection);
        $output = '';
        $sends = [];
        foreach ($fragments as [$seconds, $bytes]) {
            $send = $this->guardCallback(static function () use ($connection, $client, $bytes): void {
                if ($connection->isClosed()) {
                    return;
                }
                self::assertSame(strlen($bytes), fwrite($client, $bytes), 'Fragment write failed.');
            });
            if ($seconds === 0 || $seconds === 0.0) {
                $send();
            } else {
                $sends[] = $loop->delay($seconds, $send);
            }
        }
        $watchdog = $loop->delay(2, static function () use ($connection): void {
            $connection->close();
            throw new RuntimeException('Fragmented HTTP exchange timed out.');
        });
        $loop->onReadable($client, static function (string $id) use ($loop, $client, &$output, $watchdog, $sends): void {
            $output .= fread($client, 65536);
            if (feof($client)) {
                foreach ([$id, $watchdog, ...$sends] as $pending) {
                    $loop->cancel($pending);
                }
                fclose($client);
            }
        });
        $loop->run();
        self::assertTrue($connection->isClosed(), 'Server connection did not close.');
        self::assertSame([], $loop->getIdentifiers(), 'Fragmented HTTP exchange leaked loop registrations.');
        return $output;
    }

    private function newLoop(): RevoltEventLoop
    {
        $loop = RevoltEventLoop::create();
        EventLoop::install($loop);
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
        $this->deferCleanup(static function () use ($pair): void {
            foreach ($pair as $stream) { if (is_resource($stream)) { fclose($stream); } }
        });
        return $pair;
    }

    private function connection(RevoltEventLoop $loop, mixed $stream, int $capacity = 1048576): Connection
    {
        $connection = new Connection($loop, $stream, 'test-peer', $capacity);
        $this->deferCleanup($connection->close(...));
        return $connection;
    }
}
