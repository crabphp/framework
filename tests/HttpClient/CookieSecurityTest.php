<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Http\Uri;
use Crab\HttpClient\CookieJar;
use Crab\HttpClient\FakeClient;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class CookieSecurityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function insecureChanges(): iterable
    {
        yield 'replacement' => ['shop.example', 'session=original; Secure; Path=/', 'session=changed; Path=/', '/'];
        yield 'deletion' => ['shop.example', 'session=original; Secure; Path=/', 'session=; Max-Age=0; Path=/', '/'];
        yield 'narrower path' => [
            'shop.example',
            'session=original; Secure; Path=/',
            'session=changed; Path=/account',
            '/account',
        ];
        yield 'parent domain' => [
            'shop.example',
            'session=original; Secure; Path=/',
            'session=changed; Domain=shop.example; Path=/',
            '/',
        ];
        yield 'child domain' => [
            'api.shop.example',
            'session=original; Secure; Domain=shop.example; Path=/',
            'session=changed; Path=/',
            '/',
        ];
    }

    #[DataProvider('insecureChanges')]
    public function testAnHttpCookieCannotReplaceDeleteOrShadowASecureCookie(
        string $host,
        string $secure,
        string $change,
        string $path,
    ): void {
        $jar = new CookieJar();
        $jar->store(new Uri('https://shop.example/'), [$secure]);
        $jar->store(new Uri("http://{$host}/"), [$change]);

        self::assertSame('session=original', $jar->header(new Uri("https://{$host}{$path}")));
        self::assertSame('', $jar->header(new Uri("http://{$host}{$path}")));
    }

    public function testCookieProtectionPreservesUnrelatedCookiesAndSecureUpdates(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-01-01 00:00:00 UTC'));
        $jar = new CookieJar($clock);
        $jar->store(new Uri('https://shop.example/'), ['session=original; Secure; Path=/account; Max-Age=60']);
        $jar->store(
            new Uri('http://shop.example/'),
            [
                'theme=dark; Path=/',
                'session=public; Path=/',
                'session=other; Path=/accounting',
            ],
        );
        $jar->store(new Uri('http://other.example/'), ['session=unrelated; Path=/']);

        self::assertSame(
            'session=original; theme=dark; session=public',
            $jar->header(new Uri('https://shop.example/account')),
        );
        self::assertSame(
            'session=other; theme=dark; session=public',
            $jar->header(new Uri('http://shop.example/accounting')),
        );
        self::assertSame('session=unrelated', $jar->header(new Uri('http://other.example/')));

        $jar->store(new Uri('https://shop.example/'), ['session=renewed; Secure; Path=/account; Max-Age=60']);
        self::assertSame('renewed', $jar->for('https://shop.example/account')['session']);
        $clock->advance('+61 seconds');
        $jar->store(new Uri('http://shop.example/'), ['session=after-expiry; Path=/account']);
        self::assertSame('after-expiry', $jar->for('https://shop.example/account')['session']);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function publicSuffixCookies(): iterable
    {
        // From, the cookie it sets, to where it is then asked, and what is sent there.
        yield 'a country\'s second level' => ['https://attacker.co.uk/', 'Domain=co.uk', 'https://victim.co.uk/', ''];
        yield 'shared hosting' => ['https://attacker.github.io/', 'Domain=github.io', 'https://victim.github.io/', ''];
        yield 'a wildcard rule' => ['https://a.b.ck/', 'Domain=b.ck', 'https://c.b.ck/', ''];
        yield 'a top-level domain the list does not name' => [
            'https://a.example/',
            'Domain=example',
            'https://b.example/',
            '',
        ];
        yield 'a registrable domain under one' => [
            'https://api.shop.co.uk/',
            'Domain=shop.co.uk',
            'https://www.shop.co.uk/',
            'id=1',
        ];
        yield 'an exception to a wildcard' => ['https://a.www.ck/', 'Domain=www.ck', 'https://b.www.ck/', 'id=1'];
        yield 'a public suffix that is the host itself' => [
            'https://github.io/',
            'Domain=github.io',
            'https://github.io/',
            'id=1',
        ];
        yield 'which then stays with that host' => [
            'https://github.io/',
            'Domain=github.io',
            'https://victim.github.io/',
            '',
        ];
        yield 'the base of a wildcard rule' => [
            'https://a.b.kawasaki.jp/',
            'Domain=kawasaki.jp',
            'https://c.d.kawasaki.jp/',
            '',
        ];
        yield 'another wildcard base' => ['https://a.b.nom.br/', 'Domain=nom.br', 'https://c.d.nom.br/', ''];
        yield 'an exception under a wildcard base' => [
            'https://a.city.kawasaki.jp/',
            'Domain=city.kawasaki.jp',
            'https://b.city.kawasaki.jp/',
            'id=1',
        ];
        yield 'a punycode public suffix' => [
            'https://attacker.xn--55qx5d.cn/',
            'Domain=xn--55qx5d.cn',
            'https://victim.xn--55qx5d.cn/',
            '',
        ];
        yield 'a Unicode domain, which cannot be checked' => [
            'https://attacker.aéroport.ci/',
            'Domain=AÉROPORT.ci',
            'https://victim.aéroport.ci/',
            '',
        ];
    }

    #[DataProvider('publicSuffixCookies')]
    public function testACookieIsNeverSetForEverySiteUnderAPublicSuffix(
        string $from,
        string $domain,
        string $to,
        string $sent,
    ): void {
        $jar = new CookieJar();
        $jar->store(new Uri($from), ["id=1; {$domain}; Path=/; Secure"]);
        self::assertSame($sent, $jar->header(new Uri($to)));
    }

    public function testAParentDomainCannotOverlayAChildHostsSecureCookie(): void
    {
        $jar = new CookieJar();
        $jar->store(new Uri('https://auth.shop.example/'), ['session=original; Secure; Path=/']);
        $jar->store(new Uri('http://shop.example/'), ['session=changed; Domain=shop.example; Path=/']);

        self::assertSame('session=original', $jar->header(new Uri('https://auth.shop.example/')));
        self::assertSame('', $jar->header(new Uri('http://auth.shop.example/')));
        $jar->store(new Uri('https://auth.shop.example/'), ['session=; Path=/; Max-Age=0']);
        self::assertSame('', $jar->header(new Uri('https://auth.shop.example/')));
    }

    public function testAnInsecureRedirectCannotChangeTheSessionSentBackToHttps(): void
    {
        $jar = new CookieJar();
        $jar->store(new Uri('https://shop.example/'), ['session=original; Secure; Path=/']);
        $client = new FakeClient([
            'GET https://shop.example/start' => FakeClient::response(302, '', [
                'Location' => 'http://shop.example/hop',
            ]),
            'GET http://shop.example/hop' => FakeClient::response(302, '', [
                'Location' => 'https://shop.example/account',
                'Set-Cookie' => 'session=changed; Path=/',
            ]),
            'GET https://shop.example/account' =>
                static fn(RequestInterface $request): string => $request->getHeaderLine('Cookie'),
        ], cookies: $jar);

        self::assertSame('session=original', (string) $client->get('https://shop.example/start')->getBody());
        self::assertSame('', $client->requests[1]->getHeaderLine('Cookie'));
    }
}
