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
