<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\Middleware;
use Crab\Engine\Async\Pool;
use Crab\Engine\Async\PoolExhaustedException;
use Crab\Engine\EventLoop;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware that lets a worker work on so many requests at once. The
 * rest wait their turn, first come first served, and one that has waited
 * too long is answered 503 and told when to try again:
 *
 *     ->withMiddleware(new Concurrency(32))
 *
 *     Route::post('/login', Login::class)->middleware(new Concurrency(2, wait: 1.0));
 *
 * The count is each worker's own: a server of eight workers with a limit
 * of 32 works on up to 256 requests. A request that waits holds no
 * database connection and runs none of the application's code.
 */
final class Concurrency implements Middleware
{
    /**
     * The places, made on the first request a worker serves.
     */
    private ?Pool $places = null;
    private ?EventLoop $loop = null;

    /**
     * @param int $limit Requests one worker works on at once.
     * @param float $wait Seconds a request waits for its turn before it is refused.
     */
    public function __construct(
        private readonly int $limit,
        private readonly float $wait = 5.0,
    ) {
        if ($limit < 1) {
            throw new \InvalidArgumentException("A worker works on at least one request at once, not {$limit}.");
        }
        if (!is_finite($wait) || $wait <= 0) {
            throw new \InvalidArgumentException("A request waits a finite number of seconds greater than zero for its turn, not {$wait}.");
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $places = $this->places();
        try {
            $place = $places->acquire();
        } catch (PoolExhaustedException) {
            $after = (string) max(1, (int) ceil($this->wait));
            return ErrorResponse::make($request, 503, 'The server is busy. Try again shortly.', ['Retry-After' => $after]);
        }
        try {
            return $handler->handle($request);
        } finally {
            $places->release($place);
        }
    }

    /**
     * How many requests are being worked on and how many wait, in this worker.
     *
     * @return array{limit: int, running: int, waiting: int}
     */
    public function stats(): array
    {
        $stats = $this->places?->stats();
        return ['limit' => $this->limit, 'running' => $stats['borrowed'] ?? 0, 'waiting' => $stats['waiting'] ?? 0];
    }

    /**
     * The object may be made before the workers are forked, and each worker
     * has a loop of its own, so the places belong to the loop in use.
     */
    private function places(): Pool
    {
        $loop = EventLoop::current();
        if ($this->places === null || $this->loop !== $loop) {
            $this->loop = $loop;
            $this->places = new Pool($loop, static fn (): \stdClass => new \stdClass(), $this->limit, $this->wait);
        }
        return $this->places;
    }
}
