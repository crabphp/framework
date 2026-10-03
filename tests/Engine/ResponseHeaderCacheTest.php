<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Http\Body;
use Crab\Engine\Http\HttpException;
use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Http\ResponseEncoder;
use Crab\Engine\Tests\Support\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ResponseHeaderCacheTest extends TestCase
{
    #[DataProvider('invalidHeaderProvider')]
    public function testInvalidHeadersAreRejectedWhenEncodingOnEveryAttempt(
        array $headers,
        string $message,
        bool $hasPrevious,
    ): void
    {
        $response = new Response(headers: $headers);
        self::assertInstanceOf(Response::class, $response, 'Construction must not validate header fields.');
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $error = self::encodingError($response);
            self::assertSame($message, $error->getMessage());
            if ($hasPrevious) {
                self::assertInstanceOf(HttpException::class, $error->getPrevious());
            } else {
                self::assertNull($error->getPrevious());
            }
        }
    }

    public static function invalidHeaderProvider(): iterable
    {
        yield 'invalid name' => [['Bad Name' => 'value'], 'Invalid response header.', true];
        yield 'CRLF value' => [['X-Test' => "safe\r\nInjected: true"], 'Invalid response header.', true];
        yield 'nonstring value' => [['X-Test' => 5], 'Response header values must be strings.', false];
        yield 'nested array value' => [['X-Test' => [['nested']]], 'Response header values must be strings.', false];
        yield 'numeric name with empty values' => [[5 => []], 'Response headers require named fields.', false];
    }

    public function testReferencedScalarRemainsVisibleAfterRepeatedEncoding(): void
    {
        $value = 'before';
        $response = new Response(headers: ['X-Test' => &$value]);
        $value = 'first';
        self::assertStringContainsString("x-test: first\r\n", self::wire($response));
        self::assertStringContainsString("x-test: first\r\n", self::wire($response));

        $value = 'after second encoding';
        self::assertStringContainsString("x-test: after second encoding\r\n", self::wire($response));
        $value = "bad\r\nInjected: true";
        self::assertSame('Invalid response header.', self::encodingError($response)->getMessage());
        $value = 'recovered';
        self::assertStringContainsString("x-test: recovered\r\n", self::wire($response));
    }

    public function testReferencedValueListRemainsVisibleAfterRepeatedEncoding(): void
    {
        $values = ['before'];
        $response = new Response(headers: ['X-Test' => &$values]);
        $values = ['first', 'second'];
        self::assertStringContainsString("x-test: first\r\nx-test: second\r\n", self::wire($response));
        self::assertStringContainsString("x-test: first\r\nx-test: second\r\n", self::wire($response));

        $values = ['changed'];
        self::assertStringContainsString("x-test: changed\r\n", self::wire($response));
        $values = ["bad\r\nInjected: true"];
        self::assertSame('Invalid response header.', self::encodingError($response)->getMessage());
        $values = [];
        self::assertStringNotContainsString('x-test:', self::wire($response));
        $values = ['recovered'];
        self::assertStringContainsString("x-test: recovered\r\n", self::wire($response));
    }

    public function testReferencedListElementRemainsVisibleAfterRepeatedEncoding(): void
    {
        $value = 'before';
        $response = new Response(headers: ['X-Test' => ['fixed', &$value]]);
        $value = 'first';
        self::assertStringContainsString("x-test: fixed\r\nx-test: first\r\n", self::wire($response));
        self::assertStringContainsString("x-test: fixed\r\nx-test: first\r\n", self::wire($response));

        $value = 'after second encoding';
        self::assertStringContainsString("x-test: after second encoding\r\n", self::wire($response));
        $value = "bad\r\nInjected: true";
        self::assertSame('Invalid response header.', self::encodingError($response)->getMessage());
        $value = 'recovered';
        self::assertStringContainsString("x-test: fixed\r\nx-test: recovered\r\n", self::wire($response));
    }

    public function testReturnedNormalizedArraysAreIndependentOfCallerChanges(): void
    {
        $response = new Response(headers: ['X-Test' => ['first', 'second']]);
        $first = $response->normalizedHeaders();
        $first['x-test'][0] = 'changed before cache';
        $second = $response->normalizedHeaders();
        self::assertSame(['x-test' => ['first', 'second']], $second);

        $second['x-test'][0] = 'changed after cache';
        $second['x-test'][] = 'added';
        $second['content-length'] = ['999'];
        $third = $response->normalizedHeaders();
        self::assertSame(['x-test' => ['first', 'second']], $third);
        self::assertStringContainsString("x-test: first\r\nx-test: second\r\n", self::wire($response));
        self::assertStringContainsString("content-length: 0\r\n", self::wire($response));
    }

    public function testEmptyListsAndCaseFoldedDuplicatesPreserveTheirExistingBehavior(): void
    {
        $response = new Response(headers: [
            'X-Empty' => [],
            'Bad Name' => [],
            'X-Test' => [' first ', "\tsecond\t"],
            'x-test' => 'third',
            'X-TEST' => [],
        ]);
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $wire = self::wire($response);
            self::assertStringContainsString("x-test: first\r\nx-test: second\r\nx-test: third\r\n", $wire);
            self::assertStringNotContainsString('x-empty:', $wire);
            self::assertStringNotContainsString('Bad Name:', $wire);
            self::assertSame(3, substr_count($wire, 'x-test:'));
        }
    }

    public function testFramingRemainsRequestSpecificAfterRepeatedHeaderNormalization(): void
    {
        $response = Response::text('body');
        self::wire($response);
        self::wire($response);

        $head = self::wire($response, 'HEAD');
        self::assertStringContainsString("content-length: 4\r\n", $head);
        self::assertStringEndsWith("\r\n\r\n", $head);
        self::assertStringContainsString("connection: close\r\n", self::wire($response, version: '1.0'));
        self::assertStringContainsString("connection: keep-alive\r\n", self::wire($response));
        self::assertStringEndsWith("\r\n\r\nbody", self::wire($response));
        self::assertSame(['content-type' => ['text/plain; charset=utf-8']], $response->normalizedHeaders());
    }

    private static function wire(Response $response, string $method = 'GET', string $version = '1.1'): string
    {
        $request = new Request($method, '/', $version, [], new Body());
        return implode('', iterator_to_array((new ResponseEncoder($response, $request))->bytes(), false));
    }

    private static function encodingError(Response $response): InvalidArgumentException
    {
        try {
            self::wire($response);
        } catch (InvalidArgumentException $error) {
            return $error;
        }
        self::fail('Invalid response headers were accepted.');
    }
}
