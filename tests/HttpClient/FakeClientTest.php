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

    public function testARequestIsTriedAgainOnlyWhenThatCannotDoItTwice(): void
    {
        // Each path answers from a list, one reply a try; the last is repeated.
        $script = [
            '/busy' => [503, 429, 'done'],
            '/gateway' => [502, 504, 'done'],
            '/broken' => [500, 'never reached'],
            '/down' => ['lost', 'lost', 'done'],
            '/unreachable' => ['unsent', 'done'],
            '/always' => [503],
        ];
        $tries = [];
        $http = new FakeClient(['*' => static function (RequestInterface $request) use ($script, &$tries): mixed {
            $path = $request->getUri()->getPath();
            $reply = $script[$path][min($tries[$path] ?? 0, count($script[$path]) - 1)];
            $tries[$path] = ($tries[$path] ?? 0) + 1;
            return match ($reply) {
                'lost' => new NetworkException("{$request->getMethod()} {$path} failed: the connection was lost.", $request),
                'unsent' => new NetworkException("{$request->getMethod()} {$path} failed: it could not be reached.", $request, unsent: true),
                default => $reply,
            };
        }], 'https://api.example.com');
        $thrice = $http->retry(3);
        $outcome = static function (\Closure $request) use (&$tries): array {
            $tries = [];
            try {
                $answer = $request();
                return [$answer->getStatusCode(), array_sum($tries)];
            } catch (NetworkException) {
                return ['no answer', array_sum($tries)];
            }
        };

        // A request that may safely be made twice.
        self::assertSame([200, 3], $outcome(fn () => $thrice->get('/busy')));
        self::assertSame([200, 3], $outcome(fn () => $thrice->put('/gateway', json: ['a' => 1])));
        self::assertSame([200, 3], $outcome(fn () => $thrice->delete('/down')));
        self::assertSame([500, 1], $outcome(fn () => $thrice->get('/broken')), 'A server that failed is not asked again: it may fail the same way.');
        self::assertSame([503, 4], $outcome(fn () => $thrice->get('/always')), 'After the last try the answer is the answer.');
        self::assertSame([503, 2], $outcome(fn () => $http->retry(1)->get('/always')));

        // One that may not.
        self::assertSame([200, 3], $outcome(fn () => $thrice->post('/busy', json: ['pay' => 5])), 'Told to come back later, it was not acted on.');
        self::assertSame([502, 1], $outcome(fn () => $thrice->post('/gateway', json: ['pay' => 5])), 'Something behind the gateway may have acted on it.');
        self::assertSame(['no answer', 1], $outcome(fn () => $thrice->post('/down', json: ['pay' => 5])), 'Lost on the way, it may have arrived.');
        self::assertSame([200, 2], $outcome(fn () => $thrice->patch('/unreachable', json: ['pay' => 5])), 'Never sent, it cannot have.');

        // Nor one whose body can be read only once.
        $pipe = popen(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo "once";'), 'r');
        self::assertSame([503, 1], $outcome(fn () => $thrice->request('PUT', '/busy', body: new \Crab\Http\Stream($pipe))));

        // By itself a client tries once.
        self::assertSame([503, 1], $outcome(fn () => $http->get('/busy')));
        $made = new FakeClient(['*' => static function () use (&$tries): mixed {
            $tries['x'] = ($tries['x'] ?? 0) + 1;
            return $tries['x'] < 3 ? 503 : 'done';
        }], retries: 2);
        self::assertSame([200, 3], $outcome(static fn () => $made->get('https://api.example.com/x')), 'Or as many times as it was made to.');

        $this->expectExceptionMessage('retry() takes a number of times and a delay in seconds');
        $http->retry(-1);
    }

    public function testTheServersOwnWaitIsKeptWhenItIsLongerAndNotWhenItIsTooLong(): void
    {
        $tries = 0;
        $reply = static function (string $after) use (&$tries): \Closure {
            return static function () use ($after, &$tries): mixed {
                return ++$tries === 1 ? FakeClient::response(429, 'slow down', ['Retry-After' => $after]) : 'done';
            };
        };
        $client = static fn (string $after, float $timeout = 30.0): \Crab\HttpClient\Client => (new \Crab\HttpClient\Client(timeout: $timeout, retries: 2, retryDelay: 0.01, transport: new FakeClient(['*' => $reply($after)])));

        $started = hrtime(true);
        self::assertSame('done', (string) $client('1')->get('https://api.example.com/')->getBody());
        self::assertGreaterThan(0.95, (hrtime(true) - $started) / 1e9, 'A second, as the server asked, not the hundredth it would have waited.');

        $tries = 0;
        $started = hrtime(true);
        self::assertSame(429, $client('120', 2.0)->get('https://api.example.com/')->getStatusCode(), 'Asked to wait longer than a request may take, it is not waited for.');
        self::assertLessThan(0.5, (hrtime(true) - $started) / 1e9);

        $tries = 0;
        $started = hrtime(true);
        self::assertSame('done', (string) $client(gmdate('D, d M Y H:i:s', time() + 1) . ' GMT')->get('https://api.example.com/')->getBody(), 'A time to come back at is read too.');
        self::assertLessThan(1.6, (hrtime(true) - $started) / 1e9);
        \Crab\Engine\EventLoop::current()->run();
    }

    public function testAPauseThatCannotFitThrowsTheLastFailureUnchanged(): void
    {
        $loop = \Crab\Engine\EventLoop::current();
        $pauseElapsed = false;
        $timer = $loop->delay(0.075, static function () use (&$pauseElapsed): void {
            $pauseElapsed = true;
        });
        $failure = null;
        $cause = new \RuntimeException('the original cause');
        $http = new FakeClient(['*' => static function (RequestInterface $request) use (&$failure, $cause): NetworkException {
            return $failure = new NetworkException('The connection was lost.', $request, $cause);
        }]);
        try {
            (new Client(timeout: 0.05, retries: 1, retryDelay: 0.2, transport: $http))->get('https://api.example.com/');
            self::fail('A failed try was turned into an answer.');
        } catch (NetworkException $error) {
            self::assertSame($failure, $error);
            self::assertSame($cause, $error->getPrevious());
        } finally {
            $loop->cancel($timer);
        }
        self::assertFalse($pauseElapsed, 'The last failure is thrown before a timer inside the forbidden pause fires.');
        self::assertCount(1, $http->requests);
    }

    public function testRetryAfterMustFitTheTimeLeftRatherThanTheOriginalTimeout(): void
    {
        $loop = \Crab\Engine\EventLoop::current();
        $pauseElapsed = false;
        $timer = null;
        $http = new FakeClient(['*' => static function () use ($loop, &$pauseElapsed, &$timer): mixed {
            \Crab\Engine\Async\Async::sleep($loop, 0.6);
            $timer = $loop->delay(0.2, static function () use (&$pauseElapsed): void {
                $pauseElapsed = true;
            });
            return FakeClient::response(429, 'slow down', ['Retry-After' => '1']);
        }]);
        try {
            $response = $http->timeout(1.5)->retry(1, 0.0)->get('https://api.example.com/');
            self::assertFalse($pauseElapsed, 'The response returns before a timer inside Retry-After fires.');
        } finally {
            if ($timer !== null) {
                $loop->cancel($timer);
            }
        }

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('slow down', (string) $response->getBody());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
        self::assertCount(1, $http->requests, 'One second fits the original allowance but not what remains after the first try.');
    }

    public function testAWorkerThatResumesAfterTheDeadlineDoesNotSendALateRetry(): void
    {
        $held = false;
        $http = new FakeClient(['*' => static function () use (&$held): int {
            // Queued work runs after the retry pause suspends, before its caller can resume.
            \Crab\Engine\EventLoop::current()->defer(static function () use (&$held): void {
                usleep(200_000);
                $held = true;
            });
            return 503;
        }]);

        $client = new Client(timeout: 0.1, retries: 1, retryDelay: 0.02, transport: $http);
        self::assertSame(503, $client->get('https://api.example.com/')->getStatusCode());
        self::assertTrue($held, 'The pause actually suspended before the worker was held past the deadline.');
        self::assertCount(1, $http->requests);
    }

    public function testARedirectWhoseDeadlineHasPassedIsNeverSent(): void
    {
        $http = new FakeClient(['*' => static function (): mixed {
            \Crab\Engine\Async\Async::sleep(\Crab\Engine\EventLoop::current(), 0.2);
            return FakeClient::response(302, '', ['Location' => '/next']);
        }]);
        try {
            $http->timeout(0.05)->get('https://api.example.com/start');
            self::fail('A redirect was sent after the call expired.');
        } catch (NetworkException $error) {
            self::assertStringContainsString('it took more than 0.05 seconds', $error->getMessage());
            self::assertSame('/next', $error->getRequest()->getUri()->getPath());
            self::assertTrue($error->unsent);
        }
        self::assertCount(1, $http->requests);
    }

    public function testAFakeFailureOfTheNetworkIsThrownAsOne(): void
    {
        $http = new FakeClient(['*' => static fn (RequestInterface $request) => new NetworkException('GET https://x.example/ failed: it took more than 5 seconds.', $request)]);
        $this->expectException(NetworkException::class);
        $http->get('https://x.example/');
    }

    /**
     * A missing fake reply reports request and pattern paths, without
     * query or fragment secrets from either of them.
     */
    public function testAnUnmatchedFakeRequestDoesNotExposeSecretsFromReplyPatterns(): void
    {
        $http = new FakeClient([
            'GET https://api.example.com/known?token=query-secret#fragment-secret' => 200,
            'https://api.example.com/other#other-secret?token=hidden-secret' => 200,
        ]);
        try {
            $http->get('https://api.example.com/missing?token=request-secret#request-fragment-secret');
            self::fail('A request without a fake reply was accepted.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('Nothing fakes GET https://api.example.com/missing.', $error->getMessage());
            self::assertStringContainsString('It has: GET https://api.example.com/known, https://api.example.com/other.', $error->getMessage());
            self::assertStringNotContainsString('secret', $error->getMessage());
        }
    }
}
