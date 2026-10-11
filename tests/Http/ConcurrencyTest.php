<?php

declare(strict_types=1);

namespace Crab\Tests\Http;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Http\Concurrency;
use Crab\Http\Request;
use Crab\Http\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ConcurrencyTest extends TestCase
{
    public function testNoMoreThanTheLimitRunAtOnceAndTheRestTakeTheirTurnInOrder(): void
    {
        $loop = EventLoop::current();
        $limit = new Concurrency(2);
        $running = 0;
        $most = 0;
        $order = [];
        $handler = self::handler(static function (ServerRequestInterface $request) use ($loop, &$running, &$most, &$order): ResponseInterface {
            $order[] = $request->getUri()->getPath();
            $most = max($most, ++$running);
            Async::sleep($loop, 0.01);
            --$running;
            return Response::json(['ok' => true]);
        });

        $tasks = [];
        foreach (['/1', '/2', '/3', '/4', '/5'] as $path) {
            $tasks[] = Task::spawn($loop, static fn (): ResponseInterface => $limit->process(self::request($path), $handler));
        }
        $loop->delay(0.005, static function () use ($limit): void {
            self::assertSame(['limit' => 2, 'running' => 2, 'waiting' => 3], $limit->stats());
        });
        foreach ($tasks as $task) {
            self::assertSame(200, $task->await()->getStatusCode());
        }

        self::assertSame(2, $most);
        self::assertSame(['/1', '/2', '/3', '/4', '/5'], $order);
        self::assertSame(['limit' => 2, 'running' => 0, 'waiting' => 0], $limit->stats());
    }

    public function testARequestThatWaitsTooLongIsRefusedAndNeverReachesTheApplication(): void
    {
        $loop = EventLoop::current();
        $limit = new Concurrency(1, wait: 0.02);
        $reached = [];
        $handler = self::handler(static function (ServerRequestInterface $request) use ($loop, &$reached): ResponseInterface {
            $reached[] = $request->getUri()->getPath();
            Async::sleep($loop, 0.1);
            return Response::json(['ok' => true]);
        });

        $slow = Task::spawn($loop, static fn (): ResponseInterface => $limit->process(self::request('/slow'), $handler));
        $late = Task::spawn($loop, static fn (): ResponseInterface => $limit->process(self::request('/late'), $handler));

        $refused = $late->await();
        self::assertSame(503, $refused->getStatusCode());
        self::assertSame('1', $refused->getHeaderLine('Retry-After'));
        self::assertSame('The server is busy. Try again shortly.', json_decode((string) $refused->getBody(), true)['error']['message']);
        self::assertSame(200, $slow->await()->getStatusCode());
        self::assertSame(['/slow'], $reached);
    }

    public function testAPlaceIsGivenBackWhenTheApplicationThrows(): void
    {
        $limit = new Concurrency(1);
        $handler = self::handler(static fn (): ResponseInterface => throw new \RuntimeException('broken'));
        try {
            $limit->process(self::request('/'), $handler);
            self::fail('What the application throws must pass through.');
        } catch (\RuntimeException $error) {
            self::assertSame('broken', $error->getMessage());
        }
        self::assertSame(['limit' => 1, 'running' => 0, 'waiting' => 0], $limit->stats());
        self::assertSame(204, $limit->process(self::request('/'), self::handler(static fn (): ResponseInterface => Response::noContent()))->getStatusCode());
    }

    public function testALimitThatCouldNeverWorkIsRefusedWhenItIsWritten(): void
    {
        foreach ([[0, 5.0], [1, 0.0], [1, INF]] as [$count, $wait]) {
            try {
                new Concurrency($count, $wait);
                self::fail("A limit of {$count} waiting {$wait} must be refused.");
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('not ', $error->getMessage());
            }
        }
    }

    private static function request(string $path): Request
    {
        return (new Request('GET', $path))->withHeader('Accept', 'application/json');
    }

    private static function handler(\Closure $handle): RequestHandlerInterface
    {
        return new class ($handle) implements RequestHandlerInterface {
            public function __construct(private readonly \Closure $handle)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->handle)($request);
            }
        };
    }
}
