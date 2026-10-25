<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\App;
use Crab\Contracts\Http\ErrorRenderer;
use Crab\Contracts\Http\Problem;
use Crab\Http\Cors;
use Crab\Http\ErrorResponse;
use Crab\Http\HttpException;
use Crab\Http\Request;
use Crab\Http\Response;
use Crab\Http\Stream;
use Crab\Log\Logger;
use Crab\Validation\Email;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * An application that binds an ErrorRenderer has every error written in
 * its format, wherever the error came from, with the headers middleware
 * gave it kept.
 */
final class ErrorRendererTest extends TestCase
{
    public function testEveryErrorIsWrittenByTheApplicationsRenderer(): void
    {
        $app = $this->app(new LegacyErrors());

        $missing = $app->handle(new Request('GET', '/nothing-here'));
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame(['message' => 'Not Found', 'errors' => []], self::json($missing));

        $wrong = $app->handle(new Request('DELETE', '/users/7'));
        self::assertSame(405, $wrong->getStatusCode());
        self::assertSame('GET, HEAD', $wrong->getHeaderLine('Allow'), "The router's header is kept.");
        self::assertSame('application/json', $wrong->getHeaderLine('Content-Type'));

        $invalid = $app->handle(new Request('GET', '/invite?email=nope'));
        self::assertSame(422, $invalid->getStatusCode());
        self::assertSame(
            ['message' => 'The request is invalid.', 'errors' => ['email' => ['must be an email address']]],
            self::json($invalid),
        );

        $thrown = $app->handle(new Request('GET', '/users/7'));
        self::assertSame(['message' => 'No such user.', 'errors' => []], self::json($thrown));
        self::assertSame('30', $thrown->getHeaderLine('X-Cache-For'), "The exception's header is kept.");

        $failed = $app->handle(new Request('GET', '/fail'));
        self::assertSame(500, $failed->getStatusCode());
        self::assertSame(['message' => 'Internal Server Error', 'errors' => []], self::json($failed));

        $refused = $app->handle(new Request('GET', '/account'));
        self::assertSame(401, $refused->getStatusCode());
        self::assertSame(['message' => 'Sign in first.', 'errors' => []], self::json($refused));
        self::assertSame('Bearer', $refused->getHeaderLine('WWW-Authenticate'), "The middleware's header is kept.");
    }

    public function testMiddlewareInFrontOfTheErrorHandlerIsReachedTooAndAddsItsHeaders(): void
    {
        $app = $this->app(new LegacyErrors());

        $missing = $app->handle(new Request('GET', '/nothing-here', ['Origin' => 'https://app.example.com']));
        self::assertSame('*', $missing->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame(['message' => 'Not Found', 'errors' => []], self::json($missing));

        // An error the CORS middleware returns itself, in front of the error handler.
        $refused = $this->app(new LegacyErrors(), cors: ['https://app.example.com'])->handle(new Request(
            'OPTIONS',
            '/users/7',
            [
                'Origin' => 'https://elsewhere.example',
                'Access-Control-Request-Method' => 'GET',
            ],
        ));
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame(['message' => 'This origin may not call the API.', 'errors' => []], self::json($refused));
    }

    public function testTheRendererSeesTheCauseAndMayChooseTheStatus(): void
    {
        $app = $this->app(new class implements ErrorRenderer {
            public function render(Problem $problem, ServerRequestInterface $request): ResponseInterface
            {
                return (
                    $problem->cause instanceof ReplayGone
                        ? Response::json(['error' => ['code' => 'replay_gone']], 410)
                        : ErrorResponse::render($problem, $request)
                );
            }
        });

        $gone = $app->handle(new Request('GET', '/replay'));
        self::assertSame([410, ['error' => ['code' => 'replay_gone']]], [$gone->getStatusCode(), self::json($gone)]);

        $missing = $app->handle(new Request('GET', '/nothing-here', ['Accept' => 'application/json']));
        self::assertSame(['error' => ['status' => 404, 'message' => 'Not Found']], self::json($missing));
    }

    public function testAResponseChangedByMiddlewareIsNoLongerTheProblemsToWrite(): void
    {
        $response = ErrorResponse::make(new Request('GET', '/'), 404);
        self::assertSame(404, $response->problem()?->status);
        self::assertNotNull($response->withHeader('X-Seen', 'yes')->problem());
        self::assertNull($response->withStatus(410)->problem());
        self::assertNull($response->withBody(new Stream('gone'))->problem());
        self::assertNull(Response::json([], 404)->problem(), 'Only an error response carries a problem.');
    }

    public function testAFailingRendererLeavesCrabsOwnFormatAndIsLogged(): void
    {
        $log = fopen('php://memory', 'w+b');
        $app = $this->app(new class implements ErrorRenderer {
            public function render(Problem $problem, ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('renderer bug');
            }
        });
        $app->container->instance(LoggerInterface::class, new Logger($log));

        $missing = $app->handle(new Request('GET', '/nothing-here'));
        self::assertSame([404, "404 Not Found\n"], [$missing->getStatusCode(), (string) $missing->getBody()]);
        rewind($log);
        self::assertStringContainsString('The error renderer failed: renderer bug', (string) stream_get_contents($log));
    }

    public function testWithoutARendererErrorsAreCrabsOwn(): void
    {
        $app = App::configure()->create();
        $app->router->get('/users/{id}', static fn(int $id): never => throw new HttpException(404, 'No such user.'));

        $missing = $app->handle(new Request('GET', '/users/7', ['Accept' => 'application/json']));
        self::assertSame(['error' => ['status' => 404, 'message' => 'No such user.']], self::json($missing));
    }

    /**
     * @param list<string> $cors
     */
    private function app(ErrorRenderer $renderer, array $cors = ['*']): App
    {
        $app = App::configure()->withMiddleware(prepend: new Cors($cors))->create();
        $app->container->instance(ErrorRenderer::class, $renderer);
        $app->container->instance(LoggerInterface::class, new Logger(fopen('php://memory', 'w+b')));
        $app->router->get('/users/{id}', static fn(int $id): never => throw new HttpException(404, 'No such user.', [
            'X-Cache-For' => '30',
        ]));
        $app->router->get('/invite', static fn(#[Email] string $email): string => $email);
        $app->router->get('/fail', static fn(): never => throw new \RuntimeException('secret'));
        $app->router->get('/replay', static fn(): never => throw new ReplayGone(410));
        $app->router->get('/account', static fn(): string => 'yours')->middleware(new SignInFirst());
        return $app;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}

/**
 * Laravel's shape, as an existing client may expect it.
 */
final class LegacyErrors implements ErrorRenderer
{
    public function render(Problem $problem, ServerRequestInterface $request): ResponseInterface
    {
        return Response::json(['message' => $problem->message, 'errors' => $problem->fields], $problem->status);
    }
}

final class ReplayGone extends HttpException {}

final class SignInFirst implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return ErrorResponse::make($request, 401, 'Sign in first.', ['WWW-Authenticate' => 'Bearer']);
    }
}
