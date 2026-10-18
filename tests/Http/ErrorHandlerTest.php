<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\Engine\Async\CancelledException;
use Crab\Http\ClosureHandler;
use Crab\Http\ErrorHandler;
use Crab\Http\HttpException;
use Crab\Http\Request;
use Crab\Log\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ErrorHandlerTest extends TestCase
{
    /**
     * @var resource
     */
    private mixed $log;

    protected function setUp(): void
    {
        $this->log = fopen('php://memory', 'w+b');
    }

    public function testResponsesPassThrough(): void
    {
        $response = $this->handle(static fn() => 'fine');
        self::assertSame([200, 'fine'], [$response->getStatusCode(), (string) $response->getBody()]);
        self::assertSame('', $this->logged());
    }

    public function testFailureIsLoggedAndHiddenFromTheClient(): void
    {
        $response = $this->handle(static fn() => throw new \RuntimeException('secret detail'));
        self::assertSame(500, $response->getStatusCode());
        self::assertSame("500 Internal Server Error\n", (string) $response->getBody());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString(' ERROR GET /fail?x=1 failed: secret detail', $this->logged());
        self::assertStringContainsString('RuntimeException: secret detail in ' . __FILE__, $this->logged());
    }

    public function testErrorsAsWellAsExceptionsAreHandled(): void
    {
        $response = $this->handle(static fn() => intdiv(1, 0));
        self::assertSame(500, $response->getStatusCode());
        self::assertStringContainsString('DivisionByZeroError', $this->logged());
    }

    public function testDebugShowsTheFailure(): void
    {
        $text = (string) $this->handle(
            static fn() => throw new \RuntimeException('secret detail'),
            debug: true,
        )->getBody();
        self::assertStringStartsWith("500 secret detail\n\nRuntimeException: secret detail in " . __FILE__, $text);

        $response = $this->handle(
            static fn() => throw new \RuntimeException('secret detail'),
            debug: true,
            accept: 'application/json',
        );
        $error = json_decode((string) $response->getBody(), true)['error'];
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame([500, 'secret detail', 'RuntimeException', __FILE__], [
            $error['status'],
            $error['message'],
            $error['exception'],
            $error['file'],
        ]);
        self::assertIsInt($error['line']);
        self::assertNotEmpty($error['trace']);
    }

    public function testFailureTextThatIsNotUtf8StillAnswersAsJson(): void
    {
        $response = $this->handle(
            static fn() => throw new \RuntimeException("bad \xB1 byte"),
            debug: true,
            accept: 'application/json',
        );
        self::assertSame(500, $response->getStatusCode());
        self::assertSame("bad \u{FFFD} byte", json_decode((string) $response->getBody(), true)['error']['message']);
    }

    public function testHttpExceptionIsAnAnswer(): void
    {
        $response = $this->handle(static fn() => throw new HttpException(404, 'No such user', [
            'X-Reason' => 'missing',
        ]));
        self::assertSame([404, "404 No such user\n", 'missing'], [
            $response->getStatusCode(),
            (string) $response->getBody(),
            $response->getHeaderLine('X-Reason'),
        ]);
        self::assertSame('', $this->logged());

        $json = $this->handle(static fn() => throw new HttpException(403), accept: 'text/html, application/json');
        self::assertSame('{"error":{"status":403,"message":"Forbidden"}}', (string) $json->getBody());

        $unavailable = $this->handle(static fn() => throw new HttpException(503, 'Back soon'), debug: false);
        self::assertSame("503 Back soon\n", (string) $unavailable->getBody());
        self::assertStringContainsString('failed: Back soon', $this->logged());

        $this->expectException(\InvalidArgumentException::class);
        new HttpException(200);
    }

    public function testCancellationIsNotAnswered(): void
    {
        try {
            $this->handle(static fn() => throw new CancelledException('gone'));
            self::fail('A cancelled request was answered.');
        } catch (CancelledException) {
            self::assertSame('', $this->logged());
        }
    }

    private function handle(\Closure $route, bool $debug = false, string $accept = '*/*'): ResponseInterface
    {
        return new ErrorHandler(new Logger($this->log), $debug)->process(new Request('GET', '/fail?x=1', [
            'Accept' => $accept,
        ]), new ClosureHandler($route));
    }

    private function logged(): string
    {
        rewind($this->log);
        return stream_get_contents($this->log);
    }
}
