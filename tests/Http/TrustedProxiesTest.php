<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\Engine\Http\Request as EngineRequest;
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

    public function testAnIpv4ClientOfADualStackListenerIsKnownByItsIpv4Address(): void
    {
        // A listener on [::] sees IPv4 peers as IPv4-mapped IPv6 addresses.
        $source = new EngineRequest('GET', '/', '1.1', ['host' => ['localhost']]);
        $source->peer = '[::ffff:203.0.113.7]:52930';
        self::assertSame('203.0.113.7', Request::fromEngine($source)->ip());
        self::assertSame('203.0.113.7', Request::fromEngine($source)->getServerParams()['REMOTE_ADDR']);

        $source->peer = '[2001:db8::7]:52930';
        self::assertSame('2001:db8::7', Request::fromEngine($source)->ip());
    }

    public function testEverySpellingOfAMappedAddressIsTheSameIpv4AddressWhereverItIsRead(): void
    {
        foreach ([
            '::ffff:203.0.113.7',
            '::ffff:cb00:7107',
            '0:0:0:0:0:ffff:203.0.113.7',
            '::FFFF:CB00:7107',
        ] as $spelling) {
            $source = new EngineRequest('GET', '/', '1.1', ['host' => ['localhost']]);
            $source->peer = "[{$spelling}]:52930";
            $fromEngine = Request::fromEngine($source);
            $direct = new Request('GET', '/', serverParams: ['REMOTE_ADDR' => $spelling]);
            $forwarded = new Request(
                'GET',
                '/',
                ['X-Forwarded-For' => $spelling],
                serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            );
            $handler = new ClosureHandler(static fn(Request $request): Response => Response::text($request->ip()));
            self::assertSame(
                ['203.0.113.7', '203.0.113.7', '203.0.113.7', '203.0.113.7'],
                [
                    $fromEngine->ip(),
                    $fromEngine->getServerParams()['REMOTE_ADDR'],
                    $direct->ip(),
                    (string) new TrustedProxies(['127.0.0.1'])
                        ->process($forwarded, $handler)
                        ->getBody(),
                ],
                $spelling,
            );
        }

        // Other IPv6 addresses are left as they are written, including the IPv4-compatible form, which is not mapped.
        foreach (['2001:db8::7', '::1', '::cb00:7107'] as $address) {
            self::assertSame($address, new Request('GET', '/', serverParams: ['REMOTE_ADDR' => $address])->ip());
        }
    }

    public function testAProxyIsBelievedWhetherItsAddressIsWrittenAsIpv4OrAsMappedIpv6(): void
    {
        $proxies = new TrustedProxies(['127.0.0.1']);

        self::assertSame('198.51.100.9', self::client($proxies, '::ffff:127.0.0.1'));
        self::assertSame('127.0.0.2', self::client($proxies, '::ffff:127.0.0.2'));
        self::assertSame('198.51.100.9', self::client(new TrustedProxies(['::ffff:10.0.0.0/104']), '10.1.2.3'));
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
