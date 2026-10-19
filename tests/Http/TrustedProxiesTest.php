<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\Http\ClosureHandler;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Http\TrustedProxies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which peers are believed when they pass on a client's address.
 */
final class TrustedProxiesTest extends TestCase
{
    public static function malformedRanges(): array
    {
        return [
            'letters' => ['127.0.0.1/abc'],
            'nothing after the slash' => ['127.0.0.1/'],
            'hexadecimal' => ['127.0.0.1/0x8'],
            'negative' => ['127.0.0.1/-1'],
            'too many bits for IPv4' => ['10.0.0.0/33'],
            'too many bits for IPv6' => ['fd00::/129'],
            'a space' => ['10.0.0.0/ 8'],
            'two masks' => ['10.0.0.0/8/8'],
        ];
    }

    #[DataProvider('malformedRanges')]
    public function testARangeWhoseMaskIsNotANumberOfBitsIsRefused(string $range): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("\"{$range}\" is not an address or a range of addresses");
        new TrustedProxies([$range]);
    }

    public function testOnlyAPeerInsideTheRangeIsBelieved(): void
    {
        $proxies = new TrustedProxies(['10.0.0.0/8', 'fd00::/8', '127.0.0.1']);

        self::assertSame('198.51.100.9', self::client($proxies, '10.1.2.3'));
        self::assertSame('198.51.100.9', self::client($proxies, 'fd12::1'));
        self::assertSame('198.51.100.9', self::client($proxies, '127.0.0.1'));
        self::assertSame('203.0.113.7', self::client($proxies, '203.0.113.7'));
    }

    private static function client(TrustedProxies $proxies, string $peer): string
    {
        $request = new Request(
            'GET',
            '/',
            ['X-Forwarded-For' => '198.51.100.9'],
            serverParams: ['REMOTE_ADDR' => $peer],
        );
        $handler = new ClosureHandler(static fn(Request $request): Response => Response::text($request->ip()));
        return (string) $proxies->process($request, $handler)->getBody();
    }
}
