<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Http\Uri;
use Crab\HttpClient\CookieJar;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\TestCase;

final class CookieJarTest extends TestCase
{
    /**
     * An invalid cookie URL can be corrected from its error without
     * logging a query or fragment that may hold credentials.
     */
    public function testAnInvalidCookieUrlIsReportedWithoutItsQueryOrFragment(): void
    {
        $jar = new CookieJar();
        foreach (['shop.example?token=query-secret#fragment-secret', 'shop.example#fragment-secret?token=query-secret'] as $url) {
            try {
                $jar->set($url, 'session', 'cookie-secret');
                self::fail('An invalid cookie URL was accepted.');
            } catch (\LogicException $error) {
                self::assertSame('A cookie is set for a whole URL, "https://shop.example", not "shop.example".', $error->getMessage());
                self::assertStringNotContainsString('secret', $error->getMessage());
            }
        }
    }

    public function testACookieGoesBackOnlyWhereABrowserWouldSendIt(): void
    {
        $jar = new CookieJar();
        $jar->store(new Uri('https://shop.example/account/login'), [
            'host=only',
            'wide=1; Domain=.shop.example; Path=/',
            'area=account; Path=/account',
            'safe=1; Secure; Path=/',
            'quoted="v"; Path=/',
            'elsewhere=1; Domain=other.example',
            'everyone=1; Domain=example',
            '=nameless',
            'no pair at all',
        ]);

        self::assertSame(['host' => 'only', 'area' => 'account', 'wide' => '1', 'safe' => '1', 'quoted' => 'v'], $jar->for('https://shop.example/account/orders'));
        self::assertSame('host=only; area=account; wide=1; safe=1; quoted=v', $jar->header(new Uri('https://shop.example/account/orders')), 'The more specific path comes first.');
        self::assertSame(['wide' => '1', 'safe' => '1', 'quoted' => 'v'], $jar->for('https://shop.example/'), 'A cookie that named no path belongs to the folder it was set in.');
        self::assertSame(['wide' => '1', 'safe' => '1', 'quoted' => 'v'], $jar->for('https://shop.example/accounting'), '"/account" is not the start of "/accounting".');
        self::assertSame(['wide' => '1', 'quoted' => 'v'], $jar->for('http://shop.example/'), 'A secure cookie is not sent in the clear.');
        self::assertSame(['wide' => '1'], $jar->for('https://api.shop.example/'), 'Only a cookie that named the domain goes to the hosts under it.');
        self::assertSame([], $jar->for('https://other.example/'), 'A server does not set cookies for another.');
        self::assertSame([], $jar->for('https://evilshop.example/'));
        self::assertSame([], $jar->for('https://another.example/'), 'Nor for every site of an ending.');

        $jar->store(new Uri('http://127.0.0.1:8080/'), ['local=1; Domain=0.0.1']);
        self::assertSame([], $jar->for('http://127.0.0.1:8080/'), 'A numeric address is under no domain.');

        $jar->set('https://shop.example', 'by', 'hand');
        self::assertSame('hand', $jar->for('https://shop.example/x')['by']);
        $jar->clear();
        self::assertSame('', $jar->header(new Uri('https://shop.example/account/orders')));
        $this->expectExceptionMessage('A cookie is set for a whole URL');
        $jar->set('shop.example', 'a', 'b');
    }

    public function testCookiesExpireAndAreTakenBack(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01 00:00:00 UTC'));
        $jar = new CookieJar($clock);
        $shop = new Uri('https://shop.example/');
        $jar->store($shop, [
            'short=1; Max-Age=60',
            'dated=1; Expires=Thu, 01 Jan 2026 00:10:00 GMT',
            'both=1; Max-Age=3600; Expires=Thu, 01 Jan 2026 00:00:30 GMT',
            'kept=1',
            'stale=1; Expires=Wed, 31 Dec 2025 23:59:59 GMT',
        ]);
        self::assertSame(['short', 'dated', 'both', 'kept'], array_keys($jar->for('https://shop.example/')));

        $clock->advance('+120 seconds');
        self::assertSame(['dated', 'both', 'kept'], array_keys($jar->for('https://shop.example/')), 'Where both are given, the number of seconds counts.');
        $clock->advance('+600 seconds');
        self::assertSame(['both', 'kept'], array_keys($jar->for('https://shop.example/')));

        $jar->store($shop, ['kept=; Max-Age=0', 'both=2']);
        self::assertSame(['both' => '2'], $jar->for('https://shop.example/'), 'Set again it is replaced, and set expired it is taken back.');
    }
}
