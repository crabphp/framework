<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\App;
use Crab\Http\HttpException;
use Crab\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JsonLimitTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function wideObjects(): iterable
    {
        $members = [];
        for ($index = 0; $index < 1001; ++$index) {
            $members[] = '"field' . $index . '":0';
        }
        $object = '{' . implode(',', $members) . '}';
        yield 'top level' => [$object];
        yield 'nested in a list' => ['{"items":[' . $object . ']}'];
        yield 'duplicate keys' => ['{' . implode(',', array_fill(0, 1001, '"same":0')) . '}'];
        yield 'escaped keys' => [str_replace('field', '\\u0066ield', $object)];
        $numeric = [];
        for ($index = 0; $index < 1024; ++$index) {
            $numeric[] = '"' . ($index * 1024) . '":0';
        }
        yield 'numeric collision keys' => ['{' . implode(',', $numeric) . '}'];
        $keys = [''];
        for ($level = 0; $level < 10; ++$level) {
            $next = [];
            foreach ($keys as $key) {
                $next[] = $key . 'Ez';
                $next[] = $key . 'FY';
            }
            $keys = $next;
        }
        yield 'string collision keys' => [json_encode(array_fill_keys($keys, 0), JSON_THROW_ON_ERROR)];
    }

    #[DataProvider('wideObjects')]
    public function testAnObjectWithTooManyMembersIsRefusedBeforeItIsDecoded(string $body): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('A JSON object may have at most 1000 members. Send smaller objects.');
        try {
            new Request('POST', '/', ['Content-Type' => 'application/json'], $body)->getParsedBody();
        } catch (HttpException $error) {
            self::assertSame(413, $error->status);
            throw $error;
        }
    }

    public function testLargeListsAndPunctuationInsideStringsDoNotCountAsObjectMembers(): void
    {
        $value = array_fill(0, 2000, ['text' => '"\\{}[]:,', 'id' => 1]);
        $value[0]['nested'] = (object) array_fill_keys(
            array_map(static fn(int $n): string => 'f' . $n, range(1, 1000)),
            ':',
        );
        $body = json_encode($value, JSON_THROW_ON_ERROR);
        $parsed = new Request('POST', '/', ['Content-Type' => 'application/json'], $body)->getParsedBody();

        self::assertCount(2000, $parsed);
        self::assertCount(1000, $parsed[0]['nested']);
        self::assertSame($value[1999], $parsed[1999]);
        self::assertNull(
            new Request(
                'POST',
                '/',
                ['Content-Type' => 'application/json'],
                json_encode(str_repeat(':', 2000)),
            )->getParsedBody(),
        );
    }

    public function testMalformedJsonStillReceives400EvenWhenItContainsManyColons(): void
    {
        foreach (['{broken' . str_repeat(':', 2000), str_repeat('[', 513) . '0' . str_repeat(']', 513)] as $body) {
            $status = null;
            try {
                new Request('POST', '/', ['Content-Type' => 'application/json'], $body)->getParsedBody();
            } catch (HttpException $error) {
                $status = $error->status;
            }
            self::assertSame(400, $status);
        }
    }

    public function testEscapedPunctuationAndSiblingObjectsDoNotShareAMemberCount(): void
    {
        $object = array_fill_keys(array_map(static fn(int $n): string => 'field' . $n, range(1, 1000)), 1);
        $value = ['left' => $object, 'right' => $object, 'text' => str_repeat('\\"{}:[', 2000)];
        $body = json_encode($value, JSON_THROW_ON_ERROR);

        self::assertSame(
            $value,
            new Request('POST', '/', ['Content-Type' => 'application/json'], $body)->getParsedBody(),
        );
    }

    public function testAJsonActionRejectsWideUnknownFieldsWithoutRunning(): void
    {
        $ran = false;
        $app = new App();
        $app->router->post('/json', static function (int $id = 1) use (&$ran): array {
            $ran = true;
            return ['id' => $id];
        });
        $body = '{' . implode(',', array_fill(0, 1001, '"unused":0')) . '}';
        $response = $app->handle(new Request('POST', '/json', ['Content-Type' => 'application/json'], $body));

        self::assertSame(413, $response->getStatusCode());
        self::assertFalse($ran);
    }
}
