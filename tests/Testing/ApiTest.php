<?php

declare(strict_types=1);

namespace Crab\Tests\Testing;

use Crab\App;
use Crab\Auth\TokenAuth;
use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\IdentityResolver;
use Crab\Contracts\Auth\TokenStore;
use Crab\Contracts\Clock\Clock;
use Crab\Contracts\HttpClient\File;
use Crab\Http\ClosureHandler;
use Crab\Http\HttpException;
use Crab\Http\Response;
use Crab\Routing\FromQuery;
use Crab\Testing\Api;
use Crab\Testing\ApiResponse;
use Crab\Testing\FrozenClock;
use Crab\Validation\Data;
use Crab\Validation\Email;
use Crab\Validation\Fields;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class Member implements Identity
{
    public function __construct(
        public int|string $id,
        public string $name,
    ) {}
}

final class ApiTest extends TestCase
{
    public function testATokenIsSentWithEveryRequestWithoutChangingTheOriginalClient(): void
    {
        $api = self::echoApi();
        $authenticated = $api->withToken('abc');

        self::assertNotSame($api, $authenticated);
        self::assertSame('Bearer abc', $authenticated->get('/echo')->json('authorization'));
        self::assertSame('Bearer abc', $authenticated->post('/echo')->json('authorization'));
        self::assertSame('', $api->get('/echo')->json('authorization'));
    }

    public function testATokenUsesTheSchemeItIsGivenOrNoScheme(): void
    {
        $api = self::echoApi();

        self::assertSame('Token abc', $api->withToken('abc', 'Token')->get('/echo')->json('authorization'));
        self::assertSame('abc', $api->withToken('abc', '')->get('/echo')->json('authorization'));
    }

    public function testTokensHeadersAndUsersAreKeptWhenClientOptionsAreCombined(): void
    {
        $api = self::echoApi();
        $token = $api->withToken('abc');
        self::assertSame(
            ['authorization' => 'Bearer abc', 'tenant' => 'acme', 'user' => null],
            $token->withHeaders(['X-Tenant' => 'acme'])->get('/echo')->json(),
        );
        self::assertSame('', $token->get('/echo')->json('tenant'));
        self::assertSame(
            'acme',
            $api->withHeaders(['X-Tenant' => 'acme'])->withToken('abc')->get('/echo')->json('tenant'),
        );

        $acting = $api->actingAs(new Member(7, 'Ada'));
        self::assertSame(
            ['authorization' => 'Bearer abc', 'tenant' => '', 'user' => 7],
            $acting->withToken('abc')->get('/echo')->json(),
        );
        self::assertSame('', $acting->get('/echo')->json('authorization'));
        self::assertSame(7, $token->actingAs(new Member(7, 'Ada'))->get('/echo')->json('user'));
    }

    public function testAJsonAssertionReadsADotPathAndReturnsTheResponseForChaining(): void
    {
        $response = new ApiResponse(Response::json(['data' => [['name' => 'mug']]]), 'GET /items');

        self::assertSame($response, $response->assertJson('data.0.name', 'mug'));
        self::assertSame($response, $response->assertJson('data.0', ['name' => 'mug'])->assertStatus(200));
    }

    public function testAWrongJsonValueFailsWithTheRequestPathExpectedValueAndBody(): void
    {
        $body = ['data' => [['name' => 'mug']]];
        $response = new ApiResponse(Response::json($body), 'GET /items?page=2');

        try {
            $response->assertJson('data.0.name', 'kettle');
            self::fail('A different JSON value passed.');
        } catch (AssertionFailedError $error) {
            foreach ([
                'GET /items?page=2',
                'answered 200',
                'data.0.name',
                'kettle',
                '{"data":[{"name":"mug"}]}',
            ] as $detail) {
                self::assertStringContainsString($detail, $error->getMessage());
            }
        }
    }

    public function testAMissingJsonPathIsAnAssertionFailureEvenWhenNullWasExpected(): void
    {
        $response = new ApiResponse(Response::json(['data' => [['name' => 'mug']]]), 'POST /items');

        try {
            $response->assertJson('data.1.name', null);
            self::fail('An absent JSON value passed.');
        } catch (AssertionFailedError $error) {
            foreach ([
                'POST /items',
                'answered 200',
                'data.1.name',
                'NULL',
                'answered without "data.1.name"',
                '{"data":[{"name":"mug"}]}',
            ] as $detail) {
                self::assertStringContainsString($detail, $error->getMessage());
            }
        }
    }

    /**
     * @return list<array{string}>
     */
    public static function jsonCountsThatAreNotIntegers(): array
    {
        return [['{"count":"1"}'], ['{"count":1.0}']];
    }

    #[DataProvider('jsonCountsThatAreNotIntegers')]
    public function testJsonAssertionsRequireAnIdenticalValueAndType(string $body): void
    {
        $response = new ApiResponse(new Response(body: $body), 'GET /count');

        try {
            $response->assertJson('count', 1);
            self::fail('A value of another type passed for an integer.');
        } catch (AssertionFailedError $error) {
            self::assertStringContainsString('JSON path "count" must be identical to 1', $error->getMessage());
            self::assertStringContainsString($body, $error->getMessage());
        }
    }

    public function testPresentNullFalseZeroAndEmptyArraysCanBeAsserted(): void
    {
        $values = ['nothing' => null, 'enabled' => false, 'count' => 0, 'data' => []];
        $response = new ApiResponse(Response::json($values), 'GET /items');

        foreach ($values as $path => $expected) {
            self::assertSame($response, $response->assertJson($path, $expected));
        }
        self::assertSame($response, $response->assertJson('', $values));
    }

    public function testAJsonAssertionOnANonJsonBodyIsReportedAsATestFailure(): void
    {
        $response = new ApiResponse(Response::text('not JSON'), 'GET /broken');

        try {
            $response->assertJson('count', 1);
            self::fail('A non-JSON body passed.');
        } catch (AssertionFailedError $error) {
            self::assertStringContainsString('GET /broken answered 200', $error->getMessage());
            self::assertStringContainsString('JSON path "count" must be identical to 1', $error->getMessage());
            self::assertStringContainsString('a body that is not JSON: not JSON', $error->getMessage());
        }
    }

    public function testAHeaderAssertionReturnsTheResponseAndIgnoresTheNamesCase(): void
    {
        $response = new ApiResponse(Response::created(['id' => 1], '/users/1'), 'POST /users');

        self::assertSame($response, $response->assertHeader('Location', '/users/1')->assertStatus(201));
        self::assertSame($response, $response->assertHeader('location', '/users/1'));
        $empty = new ApiResponse(new Response(headers: ['X-Empty' => '']));
        self::assertSame($empty, $empty->assertHeader('X-Empty', ''));
    }

    public function testAWrongHeaderFailsWithItsNameActualValueAndResponseContext(): void
    {
        $response = new ApiResponse(Response::created(['id' => 1], '/users/1'), 'POST /users');

        try {
            $response->assertHeader('Location', '/users/2');
            self::fail('A different header value passed.');
        } catch (AssertionFailedError $error) {
            foreach (['POST /users', 'answered 201', 'Location', '/users/2', '/users/1', '{"id":1}'] as $detail) {
                self::assertStringContainsString($detail, $error->getMessage());
            }
        }
    }

    /**
     * @return list<array{string}>
     */
    public static function valuesExpectedFromMissingHeaders(): array
    {
        return [['/users/1'], ['']];
    }

    #[DataProvider('valuesExpectedFromMissingHeaders')]
    public function testAMissingHeaderFailsEvenWhenAnEmptyValueWasExpected(string $expected): void
    {
        $response = new ApiResponse(Response::json(['id' => 1]), 'POST /users');

        try {
            $response->assertHeader('Location', $expected);
            self::fail('An absent header passed.');
        } catch (AssertionFailedError $error) {
            foreach ([
                'POST /users',
                'answered 200',
                'Location',
                "but was ''",
                'The header is not there.',
                '{"id":1}',
            ] as $detail) {
                self::assertStringContainsString($detail, $error->getMessage());
            }
        }
    }

    public function testRequestsAreSentAsAClientWouldAndAnswersAreEasyToCheck(): void
    {
        $app = new App();
        $app->router->get('/items', static fn(#[FromQuery] int $page = 1, #[FromQuery] string $q = ''): array => [
            'page' => $page,
            'q' => $q,
            'data' => [['name' => 'mug']],
        ]);
        $app->router->post('/users', static fn(#[Fields(['email' => ['string', new Email()]])] Data $user): array => [
            'email' => $user->email,
        ]);
        $app->router->patch('/users/{id}', static fn(int $id, #[Fields(['name' => 'string'])] Data $user): array => [
            $id,
            $user->name,
        ]);
        $app->router->delete('/users/{id}', static function (int $id): void {});
        $app->router->get('/echo', static fn(\Psr\Http\Message\ServerRequestInterface $request): array => [
            $request->getHeaderLine('X-Tenant'),
            $request->getHeaderLine('Accept'),
        ]);
        $app->router->get('/broken', static fn() => throw new HttpException(409, 'Already taken.'));
        $api = new Api($app);

        self::assertSame(
            ['page' => 2, 'q' => 'a b'],
            array_slice($api->get('/items', ['page' => 2, 'q' => 'a b'])->assertStatus(200)->json(), 0, 2),
        );
        self::assertSame('mug', $api->get('/items')->json('data.0.name'));
        self::assertSame(['email' => 'ada@example.com'], $api->post('/users', ['email' => 'ada@example.com'])->json());
        self::assertSame([7, 'Ada'], $api->patch('/users/7', ['name' => 'Ada'])->json());
        self::assertSame(204, $api->delete('/users/7')->status);
        self::assertSame(['acme', 'application/json'], $api->withHeaders(['X-Tenant' => 'acme'])->get('/echo')->json());

        // What is refused comes back as JSON, with the field that was wrong.
        $refused = $api->post('/users', ['email' => 'nope']);
        self::assertSame([422, ['must be an email address']], [$refused->status, $refused->json('error.fields.email')]);
        self::assertSame('Already taken.', $api->get('/broken')->assertStatus(409)->json('error.message'));

        // A status that is not the one expected fails the test, and says what was answered.
        try {
            $api->post('/users', ['email' => 'nope'])->assertStatus(200);
            self::fail('A 422 passed for a 200.');
        } catch (AssertionFailedError $error) {
            self::assertStringContainsString('POST /users answered 422, not 200', $error->getMessage());
            self::assertStringContainsString('must be an email address', $error->getMessage());
        }
        try {
            $api->get('/items')->json('data.3.name');
            self::fail('A value that is not there was read.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('answered without "data.3.name"', $error->getMessage());
        }
    }

    public function testARequestIsMadeAsAUserWithoutAToken(): void
    {
        $app = new App();
        // Authentication that would let no token through: there is none to find.
        $tokens = new class implements TokenStore {
            public function issue(
                Identity|int|string $for,
                string $name = '',
                ?\DateTimeInterface $expiresAt = null,
            ): string {
                return '';
            }

            public function owner(#[\SensitiveParameter] string $token): int|string|null
            {
                return null;
            }

            public function revoke(#[\SensitiveParameter] string $token): void {}

            public function revokeAll(Identity|int|string $of): void {}
        };
        $users = new class implements IdentityResolver {
            public function resolve(int|string $id): ?Identity
            {
                return null;
            }
        };
        $app->router->get('/me', static fn(Member $user): array => [
            'id' => $user->id,
            'name' => $user->name,
        ])->middleware(new TokenAuth($tokens, $users));
        $api = new Api($app);

        self::assertSame(401, $api->get('/me')->status);
        self::assertSame(401, $api->get('/me', headers: ['Authorization' => 'Bearer nothing'])->status);
        self::assertSame(
            ['id' => 7, 'name' => 'Ada'],
            $api->actingAs(new Member(7, 'Ada'))->get('/me')->assertStatus(200)->json(),
        );
        // The one made before is as it was.
        self::assertSame(401, $api->get('/me')->status);
    }

    public function testAServiceReplacedInATestIsReplacedUnderEveryNameItHas(): void
    {
        $app = new App();
        // Asked for by its PSR name first, as a service made before the test would have.
        $real = $app->container->get(ClockInterface::class);
        $frozen = new FrozenClock('2026-01-31 12:00:00');
        $app->container->instance(Clock::class, $frozen);

        self::assertNotSame($real, $app->container->get(ClockInterface::class));
        self::assertSame($frozen, $app->container->get(ClockInterface::class));
        self::assertSame($frozen, $app->container->get(Clock::class));
    }

    public function testARequestIsKnownByItsClientsAddressAndAProxysWordIsTakenOnlyFromAProxy(): void
    {
        $from = static fn(string $peer, string $forwarded = ''): \Crab\Http\Request => new \Crab\Http\Request(
            'GET',
            '/',
            $forwarded === '' ? [] : ['X-Forwarded-For' => $forwarded],
            serverParams: ['REMOTE_ADDR' => $peer],
        );
        $seen = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return \Crab\Http\Response::text($request->ip());
            }
        };
        $ip = static fn(\Crab\Http\Request $request, array $proxies): string => (string) new \Crab\Http\TrustedProxies(
            $proxies,
        )
            ->process($request, $seen)
            ->getBody();

        // With no proxy to trust, the address is the connection's, whatever the header claims.
        self::assertSame('203.0.113.7', $from('203.0.113.7', '1.2.3.4')->ip());
        self::assertSame('', new \Crab\Http\Request('GET', '/')->ip());
        // From a proxy that is trusted: the first address from the right that is not a proxy's.
        self::assertSame('198.51.100.9', $ip($from('10.0.0.5', '1.2.3.4, 198.51.100.9, 10.0.0.8'), ['10.0.0.0/8']));
        self::assertSame('198.51.100.9', $ip($from('127.0.0.1', '198.51.100.9'), ['127.0.0.1']));
        self::assertSame('2001:db8::7', $ip($from('fd00::1', '2001:db8::7'), ['fd00::/8']));
        // From anyone else, the header is someone's claim and nothing more.
        self::assertSame('203.0.113.7', $ip($from('203.0.113.7', '1.2.3.4'), ['10.0.0.0/8']));
        // A proxy that sent no header, or nonsense, is itself the client as far as can be told.
        self::assertSame('10.0.0.5', $ip($from('10.0.0.5'), ['10.0.0.0/8']));
        self::assertSame('10.0.0.5', $ip($from('10.0.0.5', 'not-an-address'), ['10.0.0.0/8']));

        $this->expectException(\InvalidArgumentException::class);
        new \Crab\Http\TrustedProxies(['ten.zero']);
    }

    /**
     * Shows client options through a handler without needing application
     * wiring or a real authentication store.
     */
    public function testAFormFilesAndRawBytesAreSentThroughTheRequestParser(): void
    {
        $api = new Api(new ClosureHandler(static function (ServerRequestInterface $request): Response {
            $files = [];
            foreach ($request->getUploadedFiles() as $name => $file) {
                $files[$name] = [
                    $file->getClientFilename(),
                    $file->getClientMediaType(),
                    bin2hex((string) $file->getStream()),
                ];
            }
            return Response::json([
                'type' => explode(';', $request->getHeaderLine('Content-Type'))[0],
                'fields' => $request->getParsedBody(),
                'files' => $files,
                'body' => $request->getHeaderLine('Content-Type') === 'application/octet-stream'
                    ? bin2hex((string) $request->getBody())
                    : null,
            ]);
        }));

        self::assertSame(
            [
                'type' => 'application/x-www-form-urlencoded',
                'fields' => ['name' => 'Ada', 'tags' => ['a', 'b']],
                'files' => [],
                'body' => null,
            ],
            $api->post('/', form: ['name' => 'Ada', 'tags' => ['a', 'b']])->json(),
        );
        self::assertSame(
            [
                'type' => 'multipart/form-data',
                'fields' => ['name' => 'Ada'],
                'files' => ['avatar' => ['me.png', 'image/png', '89504e47']],
                'body' => null,
            ],
            // The boundary is the body's own, whatever Content-Type the test says.
            $api->put(
                '/',
                headers: ['Content-Type' => 'text/plain'],
                form: ['name' => 'Ada'],
                files: [
                    'avatar' => File::fromContents("\x89PNG", 'me.png', 'image/png'),
                ],
            )->json(),
        );
        self::assertSame(
            '00ff0d0a',
            $api->patch('/', headers: ['Content-Type' => 'application/octet-stream'], body: "\x00\xFF\r\n")->json(
                'body',
            ),
        );
    }

    public function testARequestHasOneBody(): void
    {
        $this->expectExceptionMessage(
            'A request has one body: give json:, a form (form: and files:) or body:, not several.',
        );
        self::echoApi()->post('/', ['name' => 'Ada'], body: 'raw');
    }

    private static function echoApi(): Api
    {
        $handler = new ClosureHandler(static function (ServerRequestInterface $request): Response {
            $user = $request->getAttribute(Identity::ATTRIBUTE);
            return Response::json([
                'authorization' => $request->getHeaderLine('Authorization'),
                'tenant' => $request->getHeaderLine('X-Tenant'),
                'user' => $user instanceof Identity ? $user->id : null,
            ]);
        });
        return new Api($handler);
    }
}
