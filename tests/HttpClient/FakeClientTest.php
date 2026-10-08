<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Contracts\HttpClient\Client as ClientContract;
use Crab\Contracts\HttpClient\File;
use Crab\HttpClient\Client;
use Crab\HttpClient\CookieJar;
use Crab\HttpClient\FakeClient;
use Crab\HttpClient\NetworkException;
use Crab\HttpClient\ResponseException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;

final class FakeClientTest extends TestCase
{
    public function testRepliesAreFoundByMethodAndUrlAndRequestsAreKept(): void
    {
        $http = new FakeClient([
            'GET https://api.example.com/users/7' => ['id' => 7, 'name' => 'Ada'],
            'POST https://api.example.com/charges' => FakeClient::response(402, ['error' => 'card_declined'], ['X-Request' => 'r1']),
            'https://api.example.com/search?q=crab' => 'one result',
            'https://api.example.com/search' => 'every result',
            'DELETE https://api.example.com/*' => 204,
            'https://down.example.com/*' => new \RuntimeException('it cannot be reached'),
            'https://echo.example.com/*' => static fn (RequestInterface $request): array => ['method' => $request->getMethod(), 'body' => (string) $request->getBody()],
        ]);
        self::assertInstanceOf(ClientContract::class, $http);
        self::assertInstanceOf(Client::class, $http);

        $user = $http->get('https://api.example.com/users/7', query: ['full' => 1]);
        self::assertSame([200, 'application/json', ['id' => 7, 'name' => 'Ada']], [$user->getStatusCode(), $user->getHeaderLine('Content-Type'), $user->json()]);
        $declined = $http->post('https://api.example.com/charges', json: ['amount' => 500]);
        self::assertSame([402, false, 'r1', ['error' => 'card_declined']], [$declined->getStatusCode(), $declined->ok(), $declined->getHeaderLine('X-Request'), $declined->json()]);
        self::assertSame('one result', (string) $http->get('https://api.example.com/search', query: ['q' => 'crab'])->getBody());
        self::assertSame('every result', (string) $http->get('https://api.example.com/search', query: ['q' => 'other'])->getBody());
        self::assertSame([204, ''], [$http->delete('https://api.example.com/users/7')->getStatusCode(), (string) $http->delete('https://api.example.com/x/y')->getBody()]);
        self::assertSame(['method' => 'PUT', 'body' => '{"a":1}'], $http->put('https://echo.example.com/anything', json: ['a' => 1])->json());
        try {
            $http->get('https://down.example.com/health');
            self::fail('A reply that is an exception was not thrown.');
        } catch (\RuntimeException $error) {
            self::assertSame('it cannot be reached', $error->getMessage());
        }

        // What was sent can be looked at afterwards.
        self::assertCount(8, $http->requests);
        self::assertCount(8, $http->sent());
        $charges = $http->sent('POST https://api.example.com/charges');
        self::assertCount(1, $charges);
        self::assertSame(['{"amount":500}', 'application/json'], [(string) $charges[0]->getBody(), $charges[0]->getHeaderLine('Content-Type')]);
        self::assertCount(2, $http->sent('https://api.example.com/search*'));
        self::assertCount(1, $http->sent('https://api.example.com/users/7?full=1'));
        self::assertCount(0, $http->sent('POST https://api.example.com/users/*'));

        try {
            $http->get('https://api.example.com/unknown', query: ['token' => 'secret']);
            self::fail('A request nothing fakes went somewhere.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('Nothing fakes GET https://api.example.com/unknown. Add a reply for it to the FakeClient. It has: GET https://api.example.com/users/7, ', $error->getMessage());
            self::assertStringNotContainsString('secret', $error->getMessage());
        }
        try {
            (new FakeClient())->get('https://api.example.com/');
        } catch (\LogicException $error) {
            self::assertStringContainsString('It has none.', $error->getMessage());
        }
    }

    public function testEverythingElseIsTheRealClient(): void
    {
        $jar = new CookieJar();
        $http = new FakeClient([
            'POST https://shop.example/login' => FakeClient::response(302, null, ['Location' => '/account', 'Set-Cookie' => 'session=abc; Path=/; HttpOnly']),
            'GET https://shop.example/account' => static fn (RequestInterface $request): array => ['cookie' => $request->getHeaderLine('Cookie'), 'key' => $request->getHeaderLine('X-Key')],
            'https://shop.example/missing' => 404,
            'POST https://shop.example/upload' => static fn (RequestInterface $request): string => (string) $request->getBody(),
        ], 'https://shop.example', ['X-Key' => 'k'], cookies: $jar);

        // The base URL, the headers, a redirect and the cookie set on the way.
        self::assertSame(['cookie' => 'session=abc', 'key' => 'k'], $http->post('/login', form: ['user' => 'ada'])->json());
        self::assertSame(['session' => 'abc'], $jar->for('https://shop.example/anything'));
        self::assertSame('k', $http->timeout(1.0)->get('/account')->json()['key']);
        self::assertCount(3, $http->requests, 'A client made from it with timeout() is the same fake.');

        try {
            $http->get('/missing')->throw();
            self::fail('An error status was not thrown.');
        } catch (ResponseException $error) {
            self::assertSame('GET https://shop.example/missing was answered with 404 Not Found.', $error->getMessage());
            self::assertSame([404, 404], [$error->response->getStatusCode(), $error->getCode()]);
            self::assertInstanceOf(ClientExceptionInterface::class, $error);
        }
        self::assertSame('k', $http->get('/account')->throw()->json()['key'], 'A success is handed on.');

        $sent = (string) $http->post('/upload', form: ['title' => 'Q3'], files: ['report' => File::fromContents('a,b', 'report.csv', 'text/csv')])->getBody();
        self::assertStringContainsString("name=\"report\"; filename=\"report.csv\"\r\nContent-Type: text/csv\r\n\r\na,b\r\n", $sent);
        self::assertMatchesRegularExpression('~^multipart/form-data; boundary=crab-[0-9a-f]{24}$~', $http->sent('POST https://shop.example/upload')[0]->getHeaderLine('Content-Type'));
    }

    public function testAFakeFailureOfTheNetworkIsThrownAsOne(): void
    {
        $http = new FakeClient(['*' => static fn (RequestInterface $request) => new NetworkException('GET https://x.example/ failed: it took more than 5 seconds.', $request)]);
        $this->expectException(NetworkException::class);
        $http->get('https://x.example/');
    }
}
