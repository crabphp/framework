<?php

declare(strict_types=1);

namespace Crab\Tests\WebSocket;

use Crab\App;
use Crab\Config\Config;
use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Http\Request;
use Crab\Contracts\WebSocket\Socket;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\FailureReporter;
use Crab\Http\AccessLog;
use Crab\Http\Concurrency;
use Crab\Http\HttpException;
use Crab\Route;
use Crab\Routing\Router;
use Crab\Testing\Api;
use Crab\Testing\TestSocket;
use Crab\Testing\WebSocketRefused;
use Crab\Tests\Support\Wait;
use Crab\Validation\Data;
use Crab\Validation\Fields;
use Crab\Validation\Range;
use Crab\WebSocket\Binary;
use Crab\WebSocket\SocketClosed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;

/**
 * Real handshake, middleware and protocol checks, with connections confined
 * to socket pairs owned by each test.
 */
final class RouteTest extends TestCase
{
    private EventLoop $loop;
    private ?\Closure $reporter;
    private array $reports = [];
    private array $sockets = [];
    private array $apis = [];

    protected function setUp(): void
    {
        $this->loop = EventLoop::install(RevoltEventLoop::create());
        $this->reporter = FailureReporter::handleWith(function (string $what, ?\Throwable $error): void {
            $this->reports[] = [$what, $error];
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            $socket->close();
        }
        foreach ($this->apis as $api) {
            $api->closeSockets();
        }
        $this->apis = [];
        FailureReporter::handleWith($this->reporter);
    }

    private function open(Api $api, string $path = '/live', array $query = [], array $headers = []): TestSocket
    {
        $this->apis[] = $api;
        return $this->sockets[] = $api->websocket($path, $query, $headers);
    }

    private function api(\Closure $action, array $settings = []): Api
    {
        $config = new Config(['websocket' => ['ping' => 0, ...$settings]]);
        $app = new App($config);
        $app->router->websocket('/live', $action);
        return new Api($app);
    }

    public function testASocketActionMustDeclareASocketWhereTheRouteIsAdded(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('WS /live needs a');
        new Router()->websocket('/live', static fn(): string => 'no socket');
    }

    public function testAnHttpActionWithASocketIsRefusedWhereTheRouteIsAdded(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('GET /live takes a Socket parameter');
        new Router()->get('/live', static function (Socket $socket): void {});
    }

    public function testASocketActionCannotDeclareAnInputClass(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('WS /live takes an input class');
        new Router()->websocket('/live', static function (Socket $socket, LiveInput $input): void {});
    }

    public function testASocketActionCannotDeclareDescribedBodyFields(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('no request body');
        new Router()->websocket('/live', static function (
            Socket $socket,
            #[Fields(['name' => 'string'])]
            Data $input,
        ): void {});
    }

    public function testASocketActionMustHaveOneNonNullSocketAndTypedFields(): void
    {
        foreach ([
            static function (?Socket $socket): void {},
            static function (Socket $a, Socket $b): void {},
            static function (Socket $socket, $name): void {},
        ] as $action) {
            try {
                new Router()->websocket('/live', $action);
                self::fail('An invalid signature was admitted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('WS /live', $error->getMessage());
            }
        }
    }

    public function testTheActionGetsTypedPatternAndQueryParametersTheUserAndRequest(): void
    {
        $app = new App(new Config(['websocket' => ['ping' => 0]]));
        $app->router->websocket('/orders/{id}', static function (
            Socket $socket,
            int $id,
            LiveUser $user,
            Request $request,
            #[Range(min: 1)]
            int $page = 1,
            bool $active = false,
        ): void {
            $socket->send([
                $id,
                $user->id,
                $page,
                $active,
                $socket->request === $request,
                $request->getAttribute('id'),
            ]);
        });
        $api = new Api($app)->actingAs(new LiveUser(7));
        $this->open($api, '/orders/42', [
            'page' => '2',
            'active' => 'true',
        ])->assertReceived([42, 7, 2, true, true, '42'])->assertClosed(1000);
    }

    public function testInvalidPatternAndQueryDataAreHttpAnswersAndNeverRunTheAction(): void
    {
        $called = false;
        $app = new App();
        $app->router->websocket('/orders/{id}', static function (
            Socket $socket,
            int $id,
            #[Range(min: 1)]
            int $page,
        ) use (&$called): void {
            $called = true;
        });
        $api = new Api($app);
        foreach ([
            ['/orders/no', ['page' => 1], 'id'],
            ['/orders/1', ['page' => 'no'], 'page'],
            ['/orders/1', [], 'page'],
            ['/orders/1', ['page' => 0], 'page'],
        ] as [$path, $query, $field]) {
            try {
                $api->websocket($path, $query);
                self::fail('Invalid data upgraded.');
            } catch (WebSocketRefused $error) {
                $error->response->assertStatus(422);
                self::assertArrayHasKey($field, $error->response->json('error.fields'));
            }
        }
        self::assertFalse($called);
    }

    public function testAMissingUserIsAnHttp401AndAnOptionalUserCanBeNull(): void
    {
        $api = $this->api(static function (Socket $socket, LiveUser $user): void {});
        try {
            $api->websocket('/live');
            self::fail('A missing user upgraded.');
        } catch (WebSocketRefused $error) {
            $error->response->assertStatus(401);
        }
        $this->open($this->api(static function (Socket $socket, ?LiveUser $user): void {
            $socket->send([$user]);
        }))->assertReceived([null]);
    }

    public function testGroupsAndTheRouteListDescribeSocketRoutesAsWs(): void
    {
        $app = new App();
        $guard = self::middleware(static fn($request, $handler) => $handler->handle($request));
        Route::prefix('/admin')
            ->middleware($guard)
            ->group(static function (): void {
                Route::websocket('/live/{id}', LiveAction::class);
            });
        $info = $app->router->routes()[0];
        self::assertSame(['WS', '/admin/live/{id}', LiveAction::class, [$guard::class]], [
            $info->method,
            $info->pattern,
            $info->action,
            $info->middleware,
        ]);
        $stream = fopen('php://temp', 'w+');
        $output = new \Crab\Console\Output($stream);
        $app->console->run(['route:list'], output: $output);
        rewind($stream);
        self::assertStringContainsString('WS', stream_get_contents($stream));
        fclose($stream);
        $this->open(new Api($app), '/admin/live/12')->assertReceived('12');
    }

    public function testAnArrayActionUsesTheSameSocketSignature(): void
    {
        $router = new Router();
        $router->websocket('/live/{id}', [LiveAction::class, 'handle']);
        $this->open(new Api($router), '/live/8')->assertReceived('8');
    }

    public function testThePipelineHoldsConcurrencyThroughPreAcceptanceWorkAndLogsAtAcceptance(): void
    {
        $order = [];
        $logger = new class($order) extends AbstractLogger {
            public array $order;

            public function __construct(array &$order)
            {
                $this->order = &$order;
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->order[] = ['logged', $context['status']];
            }
        };
        $limit = new Concurrency(1, wait: 0.01);
        $outer = self::middleware(static function ($request, $handler) use (&$order) {
            $order[] = 'before';
            $response = $handler->handle($request);
            $order[] = 'after';
            return $response->withHeader('X-Handshake', 'complete');
        });
        $app = new App(middleware: [$outer, new AccessLog(logger: $logger), $limit]);
        $app->router->websocket('/live', static function (Socket $socket) use (&$order, $limit): void {
            $order[] = ['action', $limit->stats()['running']];
            foreach ($socket as $message) {
                $socket->send($message);
            }
        });
        $socket = $this->open(new Api($app));
        $socket->send('echo');
        $socket->assertReceived('echo');
        self::assertSame(['before', ['action', 1], ['logged', 101], 'after'], $order);
        $this->open(new Api($app))->assertNothingReceived();
        self::assertSame(0, $limit->stats()['running']);
        self::assertSame([], $this->reports);
    }

    public function testMiddlewareRefusesBeforeUpgradeWithTheSharedErrorResponse(): void
    {
        $called = false;
        $app = new App();
        $app
            ->router
            ->websocket('/live', static function (Socket $socket) use (&$called): void {
                $called = true;
            })
            ->middleware(self::middleware(
                static fn($request, $handler) => \Crab\Http\ErrorResponse::make($request, 403, 'Not yours.'),
            ));
        try {
            new Api($app)->websocket('/live');
            self::fail('Middleware refused but upgraded.');
        } catch (WebSocketRefused $error) {
            $error->response->assertStatus(403)->assertJson('error.message', 'Not yours.');
        }
        self::assertFalse($called);
    }

    public function testHandshakeRefusalsAreHttp400Or426ThroughMiddleware(): void
    {
        $app = new App();
        $app->router->websocket('/live', static function (Socket $socket): void {});
        $api = new Api($app);
        $api->get('/live')->assertStatus(400);
        foreach ([
            ['Sec-WebSocket-Version' => '12'],
            ['Sec-WebSocket-Key' => 'bad'],
            ['Connection' => 'close'],
        ] as $headers) {
            try {
                $api->websocket('/live', headers: $headers);
                self::fail('An invalid handshake upgraded.');
            } catch (WebSocketRefused $error) {
                $error->response->assertStatus(isset($headers['Sec-WebSocket-Version']) ? 426 : 400);
                if (isset($headers['Sec-WebSocket-Version'])) {
                    $error->response->assertHeader('Sec-WebSocket-Version', '13');
                }
            }
        }
        $api->get('/live', headers: [
            'Upgrade' => 'websocket',
            'Connection' => 'Upgrade',
            'Sec-WebSocket-Version' => '13',
            'Sec-WebSocket-Key' => base64_encode(random_bytes(16)),
        ])->assertStatus(101);
        $api->post('/live')->assertStatus(405)->assertHeader('Allow', 'GET');
    }

    public function testJsonObjectsAndArraysNonJsonTextAndBinaryTravelBothWays(): void
    {
        $socket = $this->open($this->api(static function (Socket $socket): void {
            $socket->send(['ready' => true]);
            $socket->sendBinary("\0\xff");
            foreach ($socket as $message) {
                $socket->send($message);
            }
        }));
        $socket->assertReceived(['ready' => true])->assertReceived(new Binary("\0\xff"));
        foreach ([['id' => 3], [1, 2], '{}', '[]', '"json scalar"', 'null', '{broken', 'ordinary text'] as $message) {
            $socket->send($message);
            $socket->assertReceived($message === '{}' || $message === '[]' ? [] : $message);
        }
        $socket->sendBinary('{"binary":true}');
        $socket->assertReceived(new Binary('{"binary":true}'));
    }

    public function testBinaryEchoKeepsItsFrameTypeAndDoesNotDecodeJson(): void
    {
        $socket = $this->open($this->api(static function (Socket $socket): void {
            foreach ($socket as $message) {
                $socket->send($message);
            }
        }));
        $socket->sendBinary("\0\xff");
        $socket->assertReceived(new Binary("\0\xff"));
        $socket->sendBinary('{"a":1}');
        $socket->assertReceived(new Binary('{"a":1}'));
    }

    public function testAnOverlyWideJsonObjectCloses1009WithoutReachingTheAction(): void
    {
        $received = [];
        $socket = $this->open($this->api(static function (Socket $socket) use (&$received): void {
            foreach ($socket as $message) {
                $received[] = $message;
            }
        }));
        $body = '{' . implode(',', array_fill(0, 1001, '"member":0')) . '}';
        $socket->send($body);
        $socket->assertClosed(1009);

        self::assertSame([], $received);
        self::assertSame([], $this->reports);
    }

    public function testJsonMemberLimitsLeaveOrdinaryTextBinaryAndListsUnchanged(): void
    {
        $socket = $this->open($this->api(static function (Socket $socket): void {
            foreach ($socket as $message) {
                $socket->send($message);
            }
        }));
        $text = '{ordinary text' . str_repeat(':', 1001);
        $socket->send($text);
        $socket->assertReceived($text);
        $binary = new Binary('{' . implode(',', array_fill(0, 1001, '"member":0')) . '}');
        $socket->send($binary);
        $socket->assertReceived($binary);
        $list = array_fill(0, 1001, ['id' => 1]);
        $socket->send($list);
        $socket->assertReceived($list);
    }

    public function testTheReceiveLoopEndsAndCodeAfterItRunsWhenTheClientCloses(): void
    {
        $finished = false;
        $socket = $this->open($this->api(static function (Socket $socket) use (&$finished): void {
            foreach ($socket as $message) {
            }
            Async::sleep(EventLoop::current(), 0.01);
            $finished = true;
        }));
        $socket->close();
        self::assertTrue($finished);
        self::assertSame([], $this->reports);
    }

    public function testReturningFromTheActionClosesWith1000(): void
    {
        $this->open($this->api(static function (Socket $socket): void {}))->assertClosed(1000);
        self::assertSame([], $this->reports);
    }

    public function testThrowingAfterUpgradeCloses1011AndReportsTheRouteOnce(): void
    {
        foreach ([new \RuntimeException('Broken'), new HttpException(404, 'Too late')] as $failure) {
            $this->open($this->api(static function (Socket $socket) use ($failure): void {
                $socket->accept();
                throw $failure;
            }))->assertClosed(1011);
        }
        self::assertCount(2, $this->reports);
        foreach ($this->reports as [$what, $error]) {
            self::assertStringContainsString('WS /live failed', $error->getMessage());
            self::assertNotNull($error->getPrevious());
        }
    }

    public function testSendingToAClosedSocketThrowsAndEndsASendLoopNormally(): void
    {
        $closed = false;
        $this->open($this->api(static function (Socket $socket) use (&$closed): void {
            $socket->close();
            try {
                $socket->send('late');
            } catch (SocketClosed) {
                $closed = true;
            }
            $socket->sendBinary('late');
        }))->assertClosed(1000);
        self::assertTrue($closed);
        self::assertSame([], $this->reports);
    }

    public function testASecondConsumerIsRefusedWhileTheFirstWaits(): void
    {
        $message = '';
        $api = $this->api(static function (Socket $socket) use (&$message): void {
            $first = Task::spawn(EventLoop::current(), static fn() => $socket->receive());
            Wait::until(
                EventLoop::current(),
                static fn(): bool => new \ReflectionProperty($socket, 'receiving')->getValue($socket),
                'the first WebSocket consumer to wait',
            );
            try {
                $socket->receive();
            } catch (\LogicException $error) {
                $message = $error->getMessage();
            }
            $socket->close();
            $first->await();
        });
        $this->open($api)->assertClosed(1000);
        self::assertStringContainsString('one consumer', $message);
    }

    public function testAFastSenderStopsAtTheInboxBoundAndLosesNothing(): void
    {
        $received = [];
        $serverSocket = null;
        $release = false;
        $api = $this->api(static function (Socket $socket) use (&$received, &$serverSocket, &$release): void {
            $serverSocket = $socket;
            $socket->accept();
            Wait::until(
                EventLoop::current(),
                static function () use (&$release): bool {
                    return $release;
                },
                'release of the held WebSocket inbox consumer',
            );
            for ($i = 0; $i < 200; ++$i) {
                $received[] = $socket->receive();
            }
            $socket->send(['count' => count($received)]);
        });
        $socket = $this->open($api);
        for ($i = 0; $i < 200; ++$i) {
            $socket->send((string) $i);
        }
        Wait::until($this->loop, static fn(): bool => new \ReflectionProperty($serverSocket, 'inbox')
            ->getValue($serverSocket)
            ->count() === 16, 'the WebSocket inbox to reach its message bound');
        $inbox = new \ReflectionProperty($serverSocket, 'inbox')->getValue($serverSocket);
        self::assertSame(16, $inbox->count());
        $release = true;
        $socket->assertReceived(['count' => 200]);
        self::assertSame(array_map(strval(...), range(0, 199)), $received);
    }

    public function testTheByteBoundPausesBeforeAnotherMaximumMessageWouldOverflow(): void
    {
        $serverSocket = null;
        $release = false;
        $api = $this->api(static function (Socket $socket) use (&$serverSocket, &$release): void {
            $serverSocket = $socket;
            $socket->accept();
            Wait::until(
                EventLoop::current(),
                static function () use (&$release): bool {
                    return $release;
                },
                'release of the held WebSocket inbox consumer',
            );
            for ($i = 0; $i < 8; ++$i) {
                $socket->receive();
            }
            $socket->send('all');
        });
        $socket = $this->open($api);
        for ($i = 0; $i < 8; ++$i) {
            $socket->send(str_repeat('x', 65_536));
        }
        Wait::until(
            $this->loop,
            static fn(): bool => new \ReflectionProperty($serverSocket, 'paused')->getValue($serverSocket),
            'the WebSocket inbox to reach its byte bound',
        );
        self::assertLessThanOrEqual(262_144, new \ReflectionProperty($serverSocket, 'bytes')->getValue($serverSocket));
        self::assertTrue(new \ReflectionProperty($serverSocket, 'paused')->getValue($serverSocket));
        $release = true;
        $socket->assertReceived('all');
    }

    public function testAMessageLimitLargerThanTheInboxIsRefusedAtDeclaration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('256 KiB');
        $this->api(static function (Socket $socket): void {}, ['max_message' => 300_000]);
    }

    public function testAMessageOverTheConfiguredLimitCloses1009(): void
    {
        $socket = $this->open($this->api(static function (Socket $socket): void {
            foreach ($socket as $message) {
            }
        }, ['max_message' => 1024]));
        $socket->send(str_repeat('x', 1025));
        $socket->assertClosed(1009);
        self::assertSame([], $this->reports);
    }

    public function testTheConnectionLimitIs503AndAClosedConnectionGivesItsPlaceBack(): void
    {
        $calls = 0;
        $api = $this->api(static function (Socket $socket) use (&$calls): void {
            ++$calls;
            foreach ($socket as $message) {
            }
        }, ['max_connections' => 1]);
        $first = $this->open($api);
        try {
            $api->websocket('/live');
            self::fail('The connection ceiling was exceeded.');
        } catch (WebSocketRefused $error) {
            $error->response->assertStatus(503)->assertHeader('Retry-After', '1');
        }
        self::assertSame(1, $calls);
        $first->close();
        $this->open($api)->assertNothingReceived();
    }

    public function testAResponseDiscardedByMiddlewareDoesNotKeepAConnectionSlot(): void
    {
        $app = new App(
            new Config(['websocket' => ['max_connections' => 1]]),
            middleware: [self::middleware(static function ($request, $handler) {
                $handler->handle($request);
                return \Crab\Http\ErrorResponse::make($request, 403);
            })],
        );
        $app->router->websocket('/live', static function (Socket $socket): void {});
        $api = new Api($app);
        for ($i = 0; $i < 2; ++$i) {
            try {
                $api->websocket('/live');
                self::fail('Middleware refused but upgraded.');
            } catch (WebSocketRefused $error) {
                $error->response->assertStatus(403);
            }
        }
    }

    public function testHeadersAndTokensFollowTheSameApiClientOptionsAsHttp(): void
    {
        $api = $this->api(static function (Socket $socket): void {
            $socket->send([
                $socket->request->getHeaderLine('Authorization'),
                $socket->request->getHeaderLine('X-Tenant'),
            ]);
        });
        $this->open($api->withHeaders(['X-Tenant' => 'acme'])->withToken('abc'))->assertReceived([
            'Bearer abc',
            'acme',
        ]);
    }

    public function testPingsKeepAnIdleClientAliveAndCloseADeadOne(): void
    {
        $serverSocket = null;
        $api = $this->api(static function (Socket $socket) use (&$serverSocket): void {
            $serverSocket = $socket;
            foreach ($socket as $message) {
                $socket->send($message);
            }
        }, ['ping' => 1.0]);
        $alive = $this->open($api);
        $session = new \ReflectionProperty($serverSocket, 'session')->getValue($serverSocket);
        $receivedAt = new \ReflectionProperty($session, 'lastReceivedAt');
        for ($ping = 0; $ping < 3; ++$ping) {
            $before = $receivedAt->getValue($session);
            $session->ping();
            Wait::until(
                $this->loop,
                static fn(): bool => $receivedAt->getValue($session) > $before,
                'the live WebSocket peer to answer a ping',
            );
        }
        $alive->send('alive');
        $alive->assertReceived('alive');
        $dead = $this->open($api);
        $client = new \ReflectionProperty($dead, 'client')->getValue($dead);
        $client->pauseRead();
        Wait::until($this->loop, $serverSocket->isClosed(...), 'the silent WebSocket peer to time out');
        $client->resumeRead();
        $dead->assertClosed(1001);
        self::assertSame([], $this->reports);
    }

    public function testASlowReaderHitsTheSendDeadlineAndCloses1008(): void
    {
        $ended = false;
        $paused = false;
        $api = $this->api(static function (Socket $socket) use (&$ended, &$paused): void {
            $socket->accept();
            Wait::until(
                EventLoop::current(),
                static function () use (&$paused): bool {
                    return $paused;
                },
                'the WebSocket client to pause reads',
            );
            try {
                for ($i = 0; $i < 10_000; ++$i) {
                    $socket->sendBinary(str_repeat('x', 65_536));
                }
            } finally {
                $ended = true;
            }
        }, ['send_timeout' => 0.02]);
        $socket = $this->open($api);
        $client = new \ReflectionProperty($socket, 'client')->getValue($socket);
        $client->pauseRead();
        $paused = true;
        Wait::until(
            $this->loop,
            static function () use (&$ended): bool {
                return $ended;
            },
            'the slow WebSocket sender to reach its deadline',
        );
        self::assertTrue($ended);
        $client->resumeRead();
        $socket->assertClosed(1008);
        self::assertSame([], $this->reports);
    }

    #[DataProvider('startupRefusals')]
    public function testStartupRefusesAnUnsafeConnectionBudgetWithWhatToChange(array $settings, string $message): void
    {
        $app = new App(new Config($settings));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $app->router->websocket('/live', static function (Socket $socket): void {});
        $app->runtime('tcp://127.0.0.1:8200');
    }

    public static function startupRefusals(): array
    {
        $cases = [
            [['websocket' => ['max_connections' => 513]], 'websocket.max_connections exceeds'],
            [['server' => ['max_connections' => 901]], 'up to 900'],
            [['server' => ['loop' => 'missing']], 'Set "server.loop"'],
            [['server' => ['max_connections' => 1.5]], 'positive whole number'],
            [['websocket' => ['ping' => -1]], 'websocket.ping'],
            [['websocket' => ['send_timeout' => 0]], 'websocket.send_timeout'],
            [['websocket' => ['max_message' => 0]], 'positive whole number'],
        ];
        foreach (['ev', 'uv', 'event'] as $backend) {
            if (extension_loaded($backend)) {
                continue;
            }

            $cases[] = [['server' => ['loop' => $backend]], "needs the {$backend} PHP extension"];
        }
        return $cases;
    }

    public function testTheHttpGroupUsesTheConfiguredConnectionBudget(): void
    {
        $app = new App(new Config(['server' => ['max_connections' => 700]]));
        $runtime = $app->runtime('tcp://127.0.0.1:8200');
        $groups = new \ReflectionProperty($runtime, 'groups')->getValue($runtime);
        self::assertSame(700, $groups['http']->maxConnections);
    }

    public function testAnHttpAndASocketRouteCanShareAPathWithoutLeakingParameters(): void
    {
        $app = new App(new Config(['websocket' => ['ping' => 0]]));
        $app->router->get('/live/{httpId}', static fn(string $httpId): string => $httpId);
        $app->router->websocket('/live/{id}', static function (Socket $socket, int $id): void {
            $socket->send([$id, $socket->request->getAttribute('httpId')]);
        });
        $api = new Api($app);
        self::assertSame('42', $api->get('/live/42')->body());
        $this->open($api, '/live/42')->assertReceived([42, null]);
        $api->request('HEAD', '/live/42')->assertStatus(200);
    }

    public function testTheSmallestMessageLimitStillAllowsCloseControlFrames(): void
    {
        $socket = $this->open($this->api(static function (Socket $socket): void {
            foreach ($socket as $message) {
                $socket->send($message);
            }
        }, ['max_message' => 1]));
        $socket->send('x');
        $socket->assertReceived('x');
        $socket->close();
        $socket->assertClosed(1000);
        self::assertSame([], $this->reports);
    }

    public function testTheUpgradeKeepsHeadersClonedByMiddlewareOnTheWire(): void
    {
        $app = new App(middleware: [self::middleware(
            static fn($request, $handler) => $handler->handle($request)->withHeader('X-Handshake', 'ready'),
        )]);
        $app->router->websocket('/live', static function (Socket $socket): void {});
        $socket = $this->open(new Api($app));
        $headers = new \ReflectionProperty($socket, 'wireHeaders')->getValue($socket);
        self::assertSame(['ready'], $headers['x-handshake']);
    }

    public function testAnUpgradeWithARequestBodyIsAnHttp400(): void
    {
        $app = new App();
        $app->router->websocket('/live', static function (Socket $socket): void {});
        new Api($app)
            ->request('GET', '/live', ['body' => 'forbidden'])
            ->assertStatus(400)
            ->assertJson('error.message', 'A WebSocket upgrade cannot carry a request body.');
    }

    public function testAShortWorkerDrainStillSends1001BeforeTheTransportDeadline(): void
    {
        $app = new App(new Config(['server' => ['drain' => 0.02], 'websocket' => ['ping' => 0]]));
        $finished = false;
        $app->router->websocket('/live', static function (Socket $socket) use (&$finished): void {
            foreach ($socket as $message) {
            }
            $finished = true;
        });
        $socket = $this->open(new Api($app));
        $connection = new \ReflectionProperty($socket, 'server')->getValue($socket);
        $connection->drain(0.02);
        $socket->assertClosed(1001);
        $socket->close();
        self::assertTrue($finished);
        self::assertFalse($connection->hasPendingWork());
        self::assertSame([], $this->reports);
    }

    public function testOwnershipFailuresInsideTheActionRemainHttpResponses(): void
    {
        $seen = [];
        $app = new App(middleware: [
            self::middleware(static function ($request, $handler) use (&$seen) {
                $response = $handler->handle($request);
                $seen[] = $response->getStatusCode();
                return $response;
            }),
            new \Crab\Http\ErrorHandler(new \Psr\Log\NullLogger()),
        ]);
        $app->router->websocket('/orders/{id}/live', static function (Socket $socket, int $id): void {
            if ($id === 404) {
                throw new HttpException(404, 'Order missing.');
            }
            if ($id === 403) {
                throw new HttpException(403, 'Not yours.');
            }
            $socket->send('owned');
        });
        $api = new Api($app);
        foreach ([404, 403] as $status) {
            try {
                $api->websocket("/orders/{$status}/live");
                self::fail('An ownership refusal upgraded.');
            } catch (WebSocketRefused $error) {
                $error->response->assertStatus($status);
            }
        }
        self::assertSame([404, 403], $seen);
        self::assertSame([], $this->reports);
    }

    public function testPreAcceptanceValidationIs422AndAnUnexpectedFailureIs500Once(): void
    {
        $records = [];
        $logger = new class($records) extends AbstractLogger {
            public function __construct(
                public array &$records,
            ) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $context];
            }
        };
        $app = new App(middleware: [new \Crab\Http\ErrorHandler($logger)]);
        $app->router->websocket('/invalid', static function (Socket $socket): void {
            throw new \Crab\Validation\ValidationException(['order' => ['is invalid']]);
        });
        $app->router->websocket('/broken', static function (Socket $socket): void {
            throw new \RuntimeException('Broken before acceptance.');
        });
        $api = new Api($app);
        foreach (['/invalid' => 422, '/broken' => 500] as $path => $status) {
            try {
                $api->websocket($path);
                self::fail('A pre-acceptance failure upgraded.');
            } catch (WebSocketRefused $error) {
                $error->response->assertStatus($status);
            }
        }
        self::assertCount(1, $records);
        self::assertSame('error', $records[0][0]);
        self::assertSame([], $this->reports);
    }

    public function testTheRequestTokenIsLiveUntilAcceptanceAndAcceptIsIdempotent(): void
    {
        $live = false;
        $completed = false;
        $socket = $this->open($this->api(static function (Socket $socket) use (&$live, &$completed): void {
            $live = !$socket->request->cancellation()->isCancelled();
            Async::sleep(EventLoop::current(), 0.005, $socket->request->cancellation());
            $socket->accept();
            $socket->accept();
            $completed = $socket->request->cancellation()->isCancelled();
            $socket->send('accepted');
        }));
        $socket->assertReceived('accepted')->assertClosed(1000);
        self::assertTrue($live);
        self::assertTrue($completed);
        self::assertSame([], $this->reports);
    }

    public function testSwallowingEvenARetainedUpgradeEndsItsWaitingActionAndReleasesAdmission(): void
    {
        $held = [];
        $ended = 0;
        $app = new App(
            new Config(['websocket' => ['max_connections' => 1]]),
            middleware: [self::middleware(static function ($request, $handler) use (&$held) {
                $held[] = $handler->handle($request);
                return \Crab\Http\ErrorResponse::make($request, 403);
            })],
        );
        $app->router->websocket('/live', static function (Socket $socket) use (&$ended): void {
            try {
                $socket->send('never sent');
            } finally {
                ++$ended;
            }
        });
        $api = new Api($app);
        for ($i = 0; $i < 2; ++$i) {
            try {
                $api->websocket('/live');
                self::fail('A swallowed upgrade reached the wire.');
            } catch (WebSocketRefused $error) {
                $error->response->assertStatus(403);
            }
        }
        self::assertSame(2, $ended);
        self::assertSame([], $this->reports);
    }

    public function testAWireConversionFailureNeverReturnsAUsableTestSocket(): void
    {
        $ended = false;
        $app = new App(middleware: [self::middleware(static fn($request, $handler) => $handler
            ->handle($request)
            ->withBody(new \Crab\Http\Stream('invalid body')))]);
        $app->router->websocket('/live', static function (Socket $socket) use (&$ended): void {
            try {
                $socket->accept();
            } finally {
                $ended = true;
            }
        });
        try {
            new Api($app)->websocket('/live');
            self::fail('A failed wire conversion returned a socket.');
        } catch (WebSocketRefused $error) {
            $error->response->assertStatus(500);
        }
        self::assertTrue($ended);
        self::assertCount(1, $this->reports);
    }

    public function testSocketCancellationEndsAwaitedWorkOnPeerCloseAndCompletedDrain(): void
    {
        foreach ([0, 1, 2] as $ending) {
            $ended = false;
            $token = null;
            $socket = $this->open($this->api(static function (Socket $socket) use (&$ended, &$token): void {
                $socket->accept();
                $token = $socket->cancellation();
                try {
                    Async::sleep(EventLoop::current(), 30, $token);
                } finally {
                    $ended = true;
                }
            }));
            if ($ending === 1) {
                new \ReflectionProperty($socket, 'server')
                    ->getValue($socket)
                    ->drain(0.02);
                $socket->assertClosed(1001);
            } elseif ($ending === 2) {
                new \ReflectionProperty($socket, 'server')
                    ->getValue($socket)
                    ->close();
            }
            $socket->close();
            self::assertTrue($token->isCancelled());
            self::assertTrue($ended);
        }
        self::assertSame([], $this->reports);
    }

    public function testAnUnrelatedCancellationIsStillReportedAfterOurClose(): void
    {
        $socket = $this->open($this->api(static function (Socket $socket): void {
            $socket->close();
            throw new \Crab\Engine\Async\CancelledException('A different token failed.');
        }));
        $socket->assertClosed(1000);
        self::assertCount(1, $this->reports);
        self::assertSame('A different token failed.', $this->reports[0][1]->getPrevious()->getMessage());
    }

    public function testClosedSocketsRejectInvalidPayloadsBeforeInspectingThem(): void
    {
        $caught = 0;
        $socket = $this->open($this->api(static function (Socket $socket) use (&$caught): void {
            $socket->close();
            $resource = fopen('php://temp', 'w+');
            try {
                foreach (["\xff", ['invalid' => "\xff"], ['resource' => $resource], new Binary("\xff")] as $message) {
                    try {
                        $socket->send($message);
                    } catch (SocketClosed) {
                        ++$caught;
                    }
                }
                try {
                    $socket->sendBinary("\xff");
                } catch (SocketClosed) {
                    ++$caught;
                }
            } finally {
                fclose($resource);
            }
        }));
        $socket->assertClosed(1000);
        self::assertSame(5, $caught);
        self::assertSame([], $this->reports);
    }

    public function testAFullInboxClosesAtItsOwnDeadlineWithOneRouteWarning(): void
    {
        $records = [];
        $logger = new class($records) extends AbstractLogger {
            public function __construct(
                public array &$records,
            ) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message];
            }
        };
        $ended = false;
        $app = new App(new Config(['websocket' => ['ping' => 0.01]]));
        $app->container->instance(\Psr\Log\LoggerInterface::class, $logger);
        $app->router->websocket('/live', static function (Socket $socket) use (&$ended): void {
            $socket->accept();
            try {
                Async::sleep(EventLoop::current(), 30, $socket->cancellation());
            } finally {
                $ended = true;
            }
        });
        $socket = $this->open(new Api($app));
        for ($i = 0; $i < 32; ++$i) {
            $socket->send('queued');
        }
        $socket->assertClosed(1011);
        $socket->close();
        self::assertTrue($ended);
        self::assertCount(1, $records);
        self::assertSame('warning', $records[0][0]);
        self::assertStringContainsString('WS /live', $records[0][1]);
        self::assertStringContainsString('did not take a message for 0.02 seconds', $records[0][1]);
        self::assertSame([], $this->reports);
    }

    public function testASendOnlyActionAtTheDefaultLimitRetiresASilentPeer(): void
    {
        $ended = false;
        $serverSocket = null;
        $socket = $this->open($this->api(static function (Socket $socket) use (&$ended, &$serverSocket): void {
            $serverSocket = $socket;
            $socket->send('push');
            try {
                Async::sleep(EventLoop::current(), 30, $socket->cancellation());
            } finally {
                $ended = true;
            }
        }, ['ping' => 0.01]));
        $socket->assertReceived('push');
        $client = new \ReflectionProperty($socket, 'client')->getValue($socket);
        $client->pauseRead();
        Wait::until(
            $this->loop,
            static fn(): bool => $serverSocket->isClosed(),
            'the silent send-only WebSocket to close',
        );
        self::assertFalse(new \ReflectionProperty($serverSocket, 'paused')->getValue($serverSocket));
        $client->resumeRead();
        $socket->assertClosed(1001);
        $socket->close();
        self::assertTrue($ended);
        self::assertSame([], $this->reports);
    }

    public function testTheApiFixtureClosesForgottenSocketsEvenWhenAnAssertionThrows(): void
    {
        $ended = false;
        $weak = null;
        try {
            $api = $this->api(static function (Socket $socket) use (&$ended): void {
                foreach ($socket as $message) {
                }
                Async::sleep(EventLoop::current(), 0.02);
                $ended = true;
            }, ['max_connections' => 1]);
            $socket = $api->withHeaders(['X-Test' => 'fixture'])->websocket('/live');
            $weak = \WeakReference::create($socket);
            unset($socket);
            \PHPUnit\Framework\Assert::fail('Simulated failed assertion.');
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            unset($api);
        }
        gc_collect_cycles();
        self::assertTrue($ended);
        self::assertNull($weak->get());
        self::assertSame([], $this->loop->getIdentifiers());
        EventLoop::install(RevoltEventLoop::create());
    }

    public function testClosingOneTestSocketDoesNotCancelAnother(): void
    {
        $api = $this->api(static function (Socket $socket): void {
            foreach ($socket as $message) {
                $socket->send($message);
            }
        });
        $first = $this->open($api);
        $other = $this->open($api);
        $first->close();
        $other->send('still open');
        $other->assertReceived('still open');
        $other->close();
        self::assertSame([], $this->loop->getIdentifiers());
    }

    public function testUnsettledCleanupIsABoundedTestFailureNamingTheRoute(): void
    {
        $cleanup = new \Crab\Engine\Async\Cancellation();
        $ended = false;
        $socket = $this->open($this->api(static function (Socket $socket) use ($cleanup, &$ended): void {
            foreach ($socket as $message) {
            }
            try {
                Async::sleep(EventLoop::current(), 30, $cleanup);
            } catch (\Crab\Engine\Async\CancelledException) {
                // The test releases its deliberately stalled cleanup below.
            } finally {
                $ended = true;
            }
        }));
        $start = hrtime(true);
        try {
            $socket->close();
            self::fail('Unsettled cleanup was accepted.');
        } catch (\PHPUnit\Framework\AssertionFailedError $error) {
            self::assertStringContainsString('WS /live did not settle within one second', $error->getMessage());
        }
        self::assertLessThan(5.0, (hrtime(true) - $start) / 1e9);
        self::assertFalse($ended);
        EventLoop::install(RevoltEventLoop::create());
        EventLoop::install($this->loop);
        $cleanup->cancel();
        $this->loop->run();
        self::assertTrue($ended);
        self::assertSame([], $this->loop->getIdentifiers());
        self::assertSame([], $this->reports);
    }

    public function testClientLossCancelsPreAcceptanceWorkWithoutReportingAFailure(): void
    {
        foreach ([false, true] as $requestToken) {
            $ended = false;
            $api = $this->api(static function (Socket $socket) use ($requestToken, &$ended): void {
                $token = $requestToken ? $socket->request->cancellation() : $socket->cancellation();
                try {
                    Async::sleep(EventLoop::current(), 30, $token);
                } finally {
                    $ended = true;
                }
            });
            try {
                $api->websocket('/live');
                self::fail('An action that never accepted completed its handshake.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('handshake did not finish within one second', $error->getMessage());
            }
            self::assertTrue($ended);
            self::assertSame([], $this->loop->getIdentifiers());
        }
        self::assertSame([], $this->reports);
    }

    public function testARefusedUpgradeKeepsItsAdmissionUntilActionCleanupSettles(): void
    {
        $cleanup = new \Crab\Engine\Async\Cancellation();
        $calls = 0;
        $app = new App(
            new Config(['websocket' => ['ping' => 0, 'max_connections' => 1]]),
            middleware: [self::middleware(static function ($request, $handler) {
                $response = $handler->handle($request);
                return $response->getStatusCode() === 101 ? \Crab\Http\ErrorResponse::make($request, 403) : $response;
            })],
        );
        $app->router->websocket('/live', static function (Socket $socket) use ($cleanup, &$calls): void {
            ++$calls;
            try {
                $socket->accept();
            } finally {
                try {
                    Async::sleep(EventLoop::current(), 30, $cleanup);
                } catch (\Crab\Engine\Async\CancelledException) {
                    // The test releases its deliberately stalled cleanup.
                }
            }
        });
        $api = new Api($app);
        foreach ([403, 503] as $status) {
            try {
                $api->websocket('/live');
                self::fail('A refused upgrade reached the wire.');
            } catch (WebSocketRefused $error) {
                $error->response->assertStatus($status);
            }
        }
        self::assertSame(1, $calls);
        $cleanup->cancel();
        $this->loop->run();
        try {
            $api->websocket('/live');
            self::fail('A refused upgrade reached the wire.');
        } catch (WebSocketRefused $error) {
            $error->response->assertStatus(403);
        }
        self::assertSame(2, $calls);
        self::assertSame([], $this->loop->getIdentifiers());
        self::assertSame([], $this->reports);
    }

    public function testCloseBeforeAcceptanceUpgradesThenExposesItsCode(): void
    {
        $this->open($this->api(static function (Socket $socket): void {
            $socket->close(1008, 'Subscription ended');
        }))->assertClosed(1008);
        self::assertSame([], $this->reports);
    }

    public function testTheInboxCeilingAcceptsOneMaximumBinaryMessage(): void
    {
        $socket = $this->open($this->api(static function (Socket $socket): void {
            $socket->send($socket->receive());
        }, ['max_message' => 262_144]));
        $message = new Binary(str_repeat('x', 262_144));
        self::assertSame($message->bytes, (string) $message);
        $socket->send($message);
        $socket->assertReceived($message)->assertClosed(1000);
        self::assertSame([], $this->reports);
    }

    public function testDisablingPingsAlsoDisablesTheFullInboxDeadline(): void
    {
        $serverSocket = null;
        $socket = $this->open($this->api(static function (Socket $socket) use (&$serverSocket): void {
            $serverSocket = $socket;
            $socket->accept();
            Async::sleep(EventLoop::current(), 30, $socket->cancellation());
        }));
        for ($i = 0; $i < 32; ++$i) {
            $socket->send('queued');
        }
        Wait::until(
            $this->loop,
            static fn(): bool => new \ReflectionProperty($serverSocket, 'paused')->getValue($serverSocket),
            'the ping-disabled WebSocket inbox to pause',
        );
        self::assertTrue(new \ReflectionProperty($serverSocket, 'paused')->getValue($serverSocket));
        self::assertNull(new \ReflectionProperty($serverSocket, 'pauseTimer')->getValue($serverSocket));
        self::assertFalse($serverSocket->isClosed());
        $serverSocket->close();
        $socket->close();
        self::assertSame([], $this->reports);
    }

    public function testAnHttpOnlyAppDoesNotValidateUnusedSocketSettings(): void
    {
        $app = new App(new Config([
            'server' => ['max_connections' => 64],
            'websocket' => ['ping' => -1, 'max_message' => 300_000, 'send_timeout' => 0, 'max_connections' => 10_000],
        ]));
        $app->router->get('/ok', static fn(): string => 'ok');
        $app->runtime('tcp://127.0.0.1:8200');
        new Api($app)->get('/ok')->assertStatus(200);
        self::assertSame([], $this->loop->getIdentifiers());
    }

    public function testTheActionObjectIsSharedButItsLocalParametersAreConnectionState(): void
    {
        $action = new SharedLiveAction();
        $app = new App(new Config(['websocket' => ['ping' => 0]]));
        $app->container->instance(SharedLiveAction::class, $action);
        $app->router->websocket('/live/{id}', SharedLiveAction::class);
        $api = new Api($app);
        $first = $this->open($api, '/live/10');
        $second = $this->open($api, '/live/20');
        $first->send('state');
        $first->assertReceived(['local' => 10, 'shared' => 20]);
        $second->send('state');
        $second->assertReceived(['local' => 20, 'shared' => 20]);
        self::assertSame(2, $action->calls);
    }

    private static function middleware(\Closure $process): MiddlewareInterface
    {
        return new class($process) implements MiddlewareInterface {
            public function __construct(
                private readonly \Closure $process,
            ) {}

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return ($this->process)($request, $handler);
            }
        };
    }
}

final readonly class LiveInput
{
    public function __construct(
        public string $name,
    ) {}
}

final readonly class LiveUser implements Identity
{
    public function __construct(
        public int|string $id,
    ) {}
}

final class LiveAction
{
    public function handle(Socket $socket, int $id): void
    {
        $socket->send((string) $id);
    }
}

final class SharedLiveAction
{
    public int $calls = 0;
    private int $id;

    public function handle(Socket $socket, int $id): void
    {
        ++$this->calls;
        $this->id = $id;
        foreach ($socket as $message) {
            $socket->send(['local' => $id, 'shared' => $this->id]);
        }
    }
}
