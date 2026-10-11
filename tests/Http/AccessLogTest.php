<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\App;
use Crab\Container\Container;
use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Http\Middleware;
use Crab\Engine\FailureReporter;
use Crab\Http\AccessLog;
use Crab\Http\ClosureHandler;
use Crab\Http\ErrorHandler;
use Crab\Http\Pipeline;
use Crab\Http\Request;
use Crab\Http\RequestId;
use Crab\Http\Response;
use Crab\Http\TrustedProxies;
use Crab\Http\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Keeps records so tests can check what the middleware sends to PSR-3.
 */
final class AccessLogLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

/**
 * Models a failed log destination and counts calls so it cannot be used
 * again to report its own failure.
 */
final class FailingAccessLogLogger extends AbstractLogger
{
    public int $calls = 0;

    public function __construct(public readonly \Throwable $failure)
    {
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        ++$this->calls;
        throw $this->failure;
    }
}

/**
 * An application's identity whose id may be numeric or text.
 */
final readonly class AccessLogUser implements Identity
{
    public function __construct(public int|string $id)
    {
    }
}

/**
 * Exercises access records standalone and through application wiring.
 */
final class AccessLogTest extends TestCase
{
    public function testEachRequestIsLoggedOnceAfterItsResponseIsMade(): void
    {
        $logger = new AccessLogLogger();
        $response = Response::text('created', 201);
        $response->getBody()->seek(2);
        $handler = new ClosureHandler(static function () use ($logger, $response): ResponseInterface {
            self::assertSame([], $logger->records, 'The response has not been made yet.');
            return $response;
        });
        $log = new AccessLog(logger: $logger);

        self::assertSame($response, $log->process(new Request('POST', '/users/7', serverParams: ['REMOTE_ADDR' => '203.0.113.7']), $handler));
        self::assertCount(1, $logger->records);
        $record = $logger->records[0];
        $context = $record['context'];
        self::assertSame(['POST', '/users/7', 201, '203.0.113.7', 7], [$context['method'], $context['path'], $context['status'], $context['ip'], $context['bytes']]);
        self::assertSame('info', $record['level']);
        self::assertIsFloat($context['ms']);
        self::assertGreaterThanOrEqual(0.0, $context['ms']);
        self::assertSame(round($context['ms'], 1), $context['ms']);
        self::assertSame('POST /users/7 201 ' . number_format($context['ms'], 1, '.', '') . 'ms', $record['message']);
        self::assertSame(2, $response->getBody()->tell(), 'Logging must not consume the response body.');
        self::assertArrayNotHasKey('request_id', $context);
        self::assertArrayNotHasKey('user', $context);

        $log->process(new Request('GET', '/next'), new ClosureHandler(static fn (): string => 'next'));
        self::assertCount(2, $logger->records);
        self::assertSame(['GET', '/next', 200], array_values(array_intersect_key($logger->records[1]['context'], array_flip(['method', 'path', 'status']))));
    }

    public function testTheQueryStringIsNeverIncludedInAnAccessRecord(): void
    {
        $logger = new AccessLogLogger();
        $log = new AccessLog(logger: $logger);
        $request = new Request('GET', '/users/7?token=secret');
        $request = $request->withRequestTarget('/users/7?token=another-secret');
        $log->process($request, new ClosureHandler(static fn (): string => 'ok'));

        self::assertSame('/users/7', $logger->records[0]['context']['path']);
        $record = json_encode($logger->records[0], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret', $record);
        self::assertStringNotContainsString('token', $record);
        self::assertStringNotContainsString('?', $record);
    }

    public function testAnUnknownRouteIsLoggedWithIts404Response(): void
    {
        $logger = new AccessLogLogger();
        $app = App::configure()->withMiddleware(prepend: [new AccessLog()])->create();
        $app->container->instance(LoggerInterface::class, $logger);

        self::assertSame(404, $app->handle(new Request('GET', '/missing'))->getStatusCode());
        self::assertCount(1, $logger->records);
        self::assertSame(['/missing', 404], [$logger->records[0]['context']['path'], $logger->records[0]['context']['status']]);
    }

    public function testAnEscapingExceptionIsLoggedOnceAs500AndRethrown(): void
    {
        $logger = new AccessLogLogger();
        $failure = new \RuntimeException('failed');
        $handler = new ClosureHandler(static fn (): never => throw $failure);

        try {
            (new AccessLog(logger: $logger))->process(new Request('GET', '/fail'), $handler);
            self::fail('The exception did not reach the caller.');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertCount(1, $logger->records);
        self::assertSame(500, $logger->records[0]['context']['status']);
        self::assertNull($logger->records[0]['context']['bytes']);

        // An outer error handler still receives the same failure.
        $errors = new AccessLogLogger();
        $pipeline = new Pipeline($handler, new ErrorHandler($errors), new AccessLog(logger: $logger));
        self::assertSame(500, $pipeline->handle(new Request('GET', '/fail'))->getStatusCode());
        self::assertCount(2, $logger->records);
        self::assertSame($failure, $errors->records[0]['context']['exception']);
    }

    public function testAFailedAccessLoggerLeavesTheCreatedResponseUnchangedAndReportsItsFailure(): void
    {
        $failure = new \RuntimeException('access logger failed');
        $logger = new FailingAccessLogLogger($failure);
        $reports = [];
        $previous = FailureReporter::handleWith(static function (string $what, ?\Throwable $error) use (&$reports): void {
            $reports[] = [$what, $error];
        });
        $response = Response::text('created', 201);
        try {
            $result = (new AccessLog(logger: $logger))->process(new Request('POST', '/users'), new ClosureHandler(static fn (): ResponseInterface => $response));
            self::assertSame($response, $result);
            self::assertSame(201, $result->getStatusCode());
            self::assertSame([['HTTP access log failed', $failure]], $reports);
            self::assertSame(1, $logger->calls);
        } finally {
            FailureReporter::handleWith($previous);
        }
    }

    public function testAFailedAccessLoggerLeavesTheHandlersOwnExceptionUnchangedAndReportsItsFailure(): void
    {
        $failure = new \RuntimeException('access logger failed');
        $logger = new FailingAccessLogLogger($failure);
        $original = new \DomainException('original handler failure');
        $reports = [];
        $previous = FailureReporter::handleWith(static function (string $what, ?\Throwable $error) use (&$reports): void {
            $reports[] = [$what, $error];
        });
        try {
            try {
                (new AccessLog(logger: $logger))->process(new Request('GET', '/fail'), new ClosureHandler(static fn (): never => throw $original));
                self::fail('The handler exception did not reach the caller.');
            } catch (\DomainException $error) {
                self::assertSame($original, $error);
            }
            self::assertSame([['HTTP access log failed', $failure]], $reports);
            self::assertSame(1, $logger->calls);
        } finally {
            FailureReporter::handleWith($previous);
        }
    }

    public function testAFailedBodySizeLookupLeavesTheResponseUnchangedAndReportsItsFailure(): void
    {
        $failure = new \RuntimeException('body size is unavailable');
        $body = $this->createStub(StreamInterface::class);
        $body->method('getSize')->willThrowException($failure);
        $response = new Response(status: 201, body: $body);
        $logger = new AccessLogLogger();
        $reports = [];
        $previous = FailureReporter::handleWith(static function (string $what, ?\Throwable $error) use (&$reports): void {
            $reports[] = [$what, $error];
        });
        try {
            self::assertSame($response, (new AccessLog(logger: $logger))->process(new Request('POST', '/stream'), new ClosureHandler(static fn (): ResponseInterface => $response)));
            self::assertSame([['HTTP access log failed', $failure]], $reports);
            self::assertSame([], $logger->records);
        } finally {
            FailureReporter::handleWith($previous);
        }
    }

    public function testAFailedPathLookupStillRunsTheHandlerAndReportsItsFailure(): void
    {
        $failure = new \RuntimeException('request URI is unavailable');
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getUri')->willThrowException($failure);
        $original = new \DomainException('original handler failure');
        $logger = new AccessLogLogger();
        $reports = [];
        $previous = FailureReporter::handleWith(static function (string $what, ?\Throwable $error) use (&$reports): void {
            $reports[] = [$what, $error];
        });
        try {
            try {
                (new AccessLog(logger: $logger))->process($request, new ClosureHandler(static fn (): never => throw $original));
                self::fail('The handler exception did not reach the caller.');
            } catch (\DomainException $error) {
                self::assertSame($original, $error);
            }
            self::assertSame([['HTTP access log failed', $failure]], $reports);
            self::assertSame([], $logger->records);
        } finally {
            FailureReporter::handleWith($previous);
        }
    }

    public function testAnActionFailureHandledInsideTheAccessLogIsLoggedOnce(): void
    {
        $access = new AccessLogLogger();
        $errors = new AccessLogLogger();
        $handler = new ClosureHandler(static fn (): never => throw new \RuntimeException('failed'));
        $pipeline = new Pipeline($handler, new AccessLog(logger: $access), new ErrorHandler($errors));

        $response = $pipeline->handle(new Request('GET', '/fail'));
        self::assertSame(500, $response->getStatusCode());
        self::assertCount(1, $access->records);
        self::assertSame(500, $access->records[0]['context']['status']);
        self::assertSame($response->getBody()->getSize(), $access->records[0]['context']['bytes']);
        self::assertCount(1, $errors->records);
    }

    public function testOnlyAnExactSkippedPathIsLeftOut(): void
    {
        $logger = new AccessLogLogger();
        $log = new AccessLog(skip: ['/up'], logger: $logger);
        $handler = new ClosureHandler(static fn (): string => 'ok');

        self::assertSame(200, $log->process(new Request('GET', '/up?check=secret'), $handler)->getStatusCode());
        self::assertSame([], $logger->records);
        $log->process(new Request('GET', '/up/more'), $handler);
        self::assertCount(1, $logger->records);
        self::assertSame('/up/more', $logger->records[0]['context']['path']);
    }

    public function testEveryPsr3LevelIsAcceptedAndUnknownLevelsListTheChoices(): void
    {
        $logger = new AccessLogLogger();
        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        foreach ($levels as $level) {
            (new AccessLog(level: $level, logger: $logger))->process(new Request('GET', '/'), new ClosureHandler(static fn (): string => 'ok'));
        }
        self::assertSame($levels, array_column($logger->records, 'level'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Set the access log level to one of ' . implode(', ', $levels));
        new AccessLog(level: 'loud');
    }

    public function testTheClientAddressComesFromTrustedProxiesOutsideTheAccessLog(): void
    {
        $logger = new AccessLogLogger();
        $pipeline = new Pipeline(
            new ClosureHandler(static fn (): string => 'ok'),
            new TrustedProxies(['10.0.0.0/8']),
            new AccessLog(logger: $logger),
        );
        $pipeline->handle(new Request('GET', '/', ['X-Forwarded-For' => '203.0.113.7, 10.0.0.2'], serverParams: ['REMOTE_ADDR' => '10.0.0.1']));

        self::assertSame('203.0.113.7', $logger->records[0]['context']['ip']);
    }

    public function testAPsr7RequestUsesTheConnectionAddress(): void
    {
        $logger = new AccessLogLogger();
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');
        $request->method('getUri')->willReturn(new Uri('/foreign'));
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => '203.0.113.8']);
        (new AccessLog(logger: $logger))->process($request, new ClosureHandler(static fn (): string => 'ok'));

        self::assertSame('203.0.113.8', $logger->records[0]['context']['ip']);
    }

    public function testAnUnknownBodySizeIsLoggedWithoutReadingTheBody(): void
    {
        $logger = new AccessLogLogger();
        $body = $this->createMock(StreamInterface::class);
        $body->expects(self::once())->method('getSize')->willReturn(null);
        $body->expects(self::never())->method('getContents');
        $body->expects(self::never())->method('read');
        $body->expects(self::never())->method('__toString');
        $response = new Response(body: $body);
        (new AccessLog(logger: $logger))->process(new Request('GET', '/stream'), new ClosureHandler(static fn (): ResponseInterface => $response));

        self::assertNull($logger->records[0]['context']['bytes']);
    }

    public function testRequestIdsAndUsersAlreadyOnTheRequestAreLogged(): void
    {
        $logger = new AccessLogLogger();
        $pipeline = new Pipeline(new ClosureHandler(static fn (): string => 'ok'), new RequestId(), new AccessLog(logger: $logger));
        $request = (new Request('GET', '/', ['X-Request-Id' => 'request-7']))->withAttribute(Identity::ATTRIBUTE, new AccessLogUser('member-7'));
        $pipeline->handle($request);

        self::assertSame(['request-7', 'member-7'], [$logger->records[0]['context']['request_id'], $logger->records[0]['context']['user']]);
    }

    public function testAttributesAddedToAnInnerRequestAreNotSharedBackOut(): void
    {
        $logger = new AccessLogLogger();
        $authenticate = new class implements Middleware {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request->withAttribute(Identity::ATTRIBUTE, new AccessLogUser(7)));
            }
        };
        $handler = new ClosureHandler(static function (ServerRequestInterface $request): string {
            self::assertInstanceOf(Identity::class, $request->getAttribute(Identity::ATTRIBUTE));
            self::assertNotNull($request->getAttribute(RequestId::ATTRIBUTE));
            return 'ok';
        });
        $pipeline = new Pipeline($handler, new AccessLog(logger: $logger), new RequestId(), $authenticate);
        $pipeline->handle(new Request('GET', '/'));

        self::assertArrayNotHasKey('request_id', $logger->records[0]['context']);
        self::assertArrayNotHasKey('user', $logger->records[0]['context']);
    }

    public function testProcessingWithoutALoggerSaysToRegisterALogProvider(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Register a log provider that binds Psr\Log\LoggerInterface');
        (new AccessLog())->process(new Request('GET', '/'), new ClosureHandler(static fn (): string => 'ok'));
    }

    public function testAContainerWithoutALoggerSaysWhatToRegister(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('register a log provider that binds Psr\Log\LoggerInterface');
        (new AccessLog())->withContainer(new Container());
    }

    public function testSupplyingALoggerNeedsNoContainer(): void
    {
        $log = new AccessLog(logger: new AccessLogLogger());
        self::assertSame($log, $log->withContainer(new Container()));
    }

    public function testTheContainerMakesMiddlewareGivenByClassName(): void
    {
        $logger = new AccessLogLogger();
        $app = App::configure()->withMiddleware(prepend: [RequestId::class, AccessLog::class])->create();
        $app->container->instance(LoggerInterface::class, $logger);
        $app->router->get('/users', static fn (): string => 'users');

        self::assertSame(200, $app->handle(new Request('GET', '/users', ['X-Request-Id' => 'named']))->getStatusCode());
        self::assertInstanceOf(AccessLog::class, $app->container->get(AccessLog::class));
        self::assertCount(1, $logger->records);
        self::assertSame('named', $logger->records[0]['context']['request_id']);
    }

    public function testMiddlewareGivenWithOptionsGetsTheContainerLogger(): void
    {
        $logger = new AccessLogLogger();
        $app = App::configure()->withRouting(health: '/up')->withMiddleware(prepend: [new AccessLog(skip: ['/up'], level: 'debug')])->create();
        $app->container->instance(LoggerInterface::class, $logger);

        self::assertSame(200, $app->handle(new Request('GET', '/up'))->getStatusCode());
        self::assertSame([], $logger->records);
        self::assertSame(404, $app->handle(new Request('GET', '/up/more'))->getStatusCode());
        self::assertCount(1, $logger->records);
        self::assertSame('debug', $logger->records[0]['level']);
    }
}
