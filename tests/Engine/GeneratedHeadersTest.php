<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Http\Body;
use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Http\ResponseEncoder;
use Crab\Engine\Tests\Support\TestCase;
use InvalidArgumentException;
use LengthException;
use PHPUnit\Framework\Attributes\DataProvider;

final class GeneratedHeadersTest extends TestCase
{
    private const DATE = 'Sat, 03 Oct 2020 12:34:56 GMT';

    public function testGeneratedFieldsFollowAllExplicitFieldsInTheirOriginalOrder(): void
    {
        $response = new Response(headers: ['X-First' => ['one', 'two'], 'X-Last' => 'last'], body: 'ok');
        $expected = "HTTP/1.1 200 OK\r\nx-first: one\r\nx-first: two\r\nx-last: last\r\n"
            . "content-length: 2\r\nconnection: keep-alive\r\ndate: <generated>\r\n\r\nok";

        // Exercise fresh normalization and reuse without generated headers
        // leaking into the immutable response's retained header representation.
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame($expected, self::replaceGeneratedDate(self::wire($response)));
        }
        self::assertSame(['X-First' => ['one', 'two'], 'X-Last' => 'last'], $response->headers);
    }

    public function testExistingFieldsKeepTheirPositionsWhileRequiredValuesAreReplaced(): void
    {
        $otherDate = 'Fri, 02 Oct 2020 12:34:56 GMT';
        $response = new Response(headers: [
            'Date' => [self::DATE, $otherDate],
            'Connection' => ['Upgrade', 'CLOSE'],
            'X-Between' => 'middle',
            'Content-Length' => '0002',
            'Set-Cookie' => ['a=1', 'b=2'],
        ], body: 'ok');

        self::assertSame("HTTP/1.1 200 OK\r\ndate: " . self::DATE . "\r\ndate: " . $otherDate
            . "\r\nconnection: close\r\nx-between: middle\r\ncontent-length: 2\r\n"
            . "set-cookie: a=1\r\nset-cookie: b=2\r\n\r\nok", self::wire($response));
    }

    public function testHeadRetainsAnExplicitLengthAndItsOriginalSpelling(): void
    {
        $response = new Response(headers: ['Content-Length' => '002048', 'X-After' => 'last'], body: 'hidden');
        self::assertSame("HTTP/1.1 200 OK\r\ncontent-length: 002048\r\nx-after: last\r\n"
            . "connection: keep-alive\r\ndate: <generated>\r\n\r\n",
            self::replaceGeneratedDate(self::wire($response, method: 'HEAD')));
    }

    #[DataProvider('bodylessProvider')]
    public function testBodylessResponsesPreserveTheirFramingAndHeaderOrder(
        int $status,
        string $reason,
        array $headers,
        bool $upgrade,
        string $expectedHeaders,
    ): void {
        $response = new Response($status, $headers, 'hidden');
        self::assertSame('HTTP/1.1 ' . $status . ' ' . $reason . "\r\n" . $expectedHeaders
            . "date: <generated>\r\n\r\n", self::replaceGeneratedDate(self::wire($response, upgrade: $upgrade)));
    }

    public static function bodylessProvider(): iterable
    {
        yield '101 retains upgrade fields, removes length' => [
            101, 'Switching Protocols', ['X-Before' => 'first', 'Content-Length' => '0006', 'Connection' => 'Upgrade', 'Upgrade' => 'test', 'X-After' => 'last'], true,
            "x-before: first\r\nconnection: Upgrade\r\nupgrade: test\r\nx-after: last\r\n",
        ];
        yield '204 removes explicit length and upgrade' => [
            204, 'No Content', ['X-Before' => 'first', 'Content-Length' => '0006', 'Upgrade' => 'test', 'X-After' => 'last'], false,
            "x-before: first\r\nx-after: last\r\nconnection: keep-alive\r\n",
        ];
        yield '205 generates zero length' => [
            205, 'Reset Content', ['X-Before' => 'first'], false,
            "x-before: first\r\ncontent-length: 0\r\nconnection: keep-alive\r\n",
        ];
        yield '205 replaces length in original position' => [
            205, 'Reset Content', ['Content-Length' => '0006', 'X-After' => 'last'], false,
            "content-length: 0\r\nx-after: last\r\nconnection: keep-alive\r\n",
        ];
        yield '304 never generates body length' => [
            304, 'Not Modified', ['X-Before' => 'first'], false,
            "x-before: first\r\nconnection: keep-alive\r\n",
        ];
        yield '304 preserves representation length spelling' => [
            304, 'Not Modified', ['Content-Length' => '0006', 'X-After' => 'last'], false,
            "content-length: 0006\r\nx-after: last\r\nconnection: keep-alive\r\n",
        ];
    }

    public function testHeadGeneratesRepresentationLengthWithoutSendingTheBody(): void
    {
        self::assertSame("HTTP/1.1 200 OK\r\ncontent-length: 6\r\nconnection: keep-alive\r\ndate: <generated>\r\n\r\n",
            self::replaceGeneratedDate(self::wire(new Response(body: 'hidden'), method: 'HEAD')));
    }

    #[DataProvider('streamProvider')]
    public function testStreamFramingRetainsExplicitFieldsBeforeGeneratedFields(
        string $version,
        array $headers,
        string $expectedHeaders,
        string $expectedBody,
    ): void {
        $response = new Response(headers: $headers, body: (static function (): \Generator { yield 'ok'; })());
        $wire = self::wire($response, version: $version, requestHeaders: ['connection' => ['keep-alive']]);
        self::assertSame('HTTP/' . $version . " 200 OK\r\n" . $expectedHeaders
            . "date: <generated>\r\n\r\n" . $expectedBody, self::replaceGeneratedDate($wire));
    }

    public static function streamProvider(): iterable
    {
        yield 'HTTP/1.1 generates chunked framing' => [
            '1.1', ['X-First' => 'first'],
            "x-first: first\r\ntransfer-encoding: chunked\r\nconnection: keep-alive\r\n", "2\r\nok\r\n0\r\n\r\n",
        ];
        yield 'existing connection precedes generated transfer encoding' => [
            '1.1', ['Connection' => 'keep-alive', 'X-Last' => 'last'],
            "connection: keep-alive\r\nx-last: last\r\ntransfer-encoding: chunked\r\n", "2\r\nok\r\n0\r\n\r\n",
        ];
        yield 'declared stream length is retained' => [
            '1.1', ['Content-Length' => '0002', 'X-Last' => 'last'],
            "content-length: 0002\r\nx-last: last\r\nconnection: keep-alive\r\n", 'ok',
        ];
        yield 'HTTP/1.0 unknown length forces close despite keepalive' => [
            '1.0', ['X-First' => 'first'], "x-first: first\r\nconnection: close\r\n", 'ok',
        ];
    }

    #[DataProvider('closeProvider')]
    public function testGeneratedConnectionReflectsRequestAndForcedClose(string $version, array $requestHeaders, bool $forceClose): void
    {
        $response = new Response(headers: ['Date' => self::DATE], body: 'ok');
        self::assertSame('HTTP/' . $version . " 200 OK\r\ndate: " . self::DATE
            . "\r\ncontent-length: 2\r\nconnection: close\r\n\r\nok",
            self::wire($response, version: $version, requestHeaders: $requestHeaders, forceClose: $forceClose));
    }

    public static function closeProvider(): iterable
    {
        yield 'HTTP/1.0 default close' => ['1.0', [], false];
        yield 'request closes HTTP/1.1' => ['1.1', ['connection' => ['close']], false];
        yield 'forced close' => ['1.1', [], true];
    }

    #[DataProvider('invalidHeadersProvider')]
    public function testGeneratedFieldsDoNotBypassExplicitFramingValidation(array $headers): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::wire(new Response(headers: $headers, body: 'ok'));
    }

    public static function invalidHeadersProvider(): iterable
    {
        yield 'case variant duplicate lengths' => [['Content-Length' => '2', 'content-length' => '2']];
        yield 'multi-value length' => [['Content-Length' => ['2', '2']]];
        yield 'mismatched body length' => [['Content-Length' => '3']];
        yield 'application transfer encoding' => [['Transfer-Encoding' => 'chunked']];
    }

    #[DataProvider('informationalProvider')]
    public function testInformationalFinalResponsesRemainRejected(int $status, bool $upgrade): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::wire(new Response($status), upgrade: $upgrade);
    }

    public static function informationalProvider(): iterable
    {
        yield '100 final' => [100, false];
        yield '100 cannot upgrade' => [100, true];
        yield '101 needs upgrade mode' => [101, false];
    }

    #[DataProvider('headerSizeProvider')]
    public function testHeaderLimitCountsGeneratedFieldsBeforeTheFinalBlankLine(int $excess): void
    {
        $empty = self::wire(new Response(headers: ['X-Pad' => '']));
        $baseLength = strpos($empty, "\r\n\r\n") + 2;
        $response = new Response(headers: ['X-Pad' => str_repeat('p', 16384 - $baseLength + $excess)]);
        if ($excess !== 0) {
            $this->expectException(LengthException::class);
        }
        $wire = self::wire($response);
        self::assertSame(16384, strpos($wire, "\r\n\r\n") + 2);
        self::assertSame(16386, strlen($wire), 'The final blank line remains outside the existing limit.');
    }

    public static function headerSizeProvider(): iterable
    {
        yield 'exact limit' => [0];
        yield 'one byte above limit' => [1];
    }

    private static function wire(
        Response $response,
        string $method = 'GET',
        string $version = '1.1',
        array $requestHeaders = [],
        bool $forceClose = false,
        bool $upgrade = false,
    ): string {
        $request = new Request($method, '/', $version, ['host' => ['test'], ...$requestHeaders], new Body());
        return implode('', iterator_to_array((new ResponseEncoder($response, $request, $forceClose, $upgrade))->bytes()));
    }

    private static function replaceGeneratedDate(string $wire): string
    {
        self::assertSame(1, preg_match('/\r\ndate: [A-Z][a-z]{2}, [0-9]{2} [A-Z][a-z]{2} [0-9]{4} [0-9:]{8} GMT\r\n/', $wire));
        return preg_replace('/\r\ndate: [^\r\n]+\r\n/', "\r\ndate: <generated>\r\n", $wire);
    }
}
