<?php

declare(strict_types=1);

namespace Crab\Tests\Testing;

use Crab\Contracts\Container\Container;
use Crab\Contracts\Provider;
use Crab\Contracts\WorkerProvider;
use Crab\Engine\Async\Async;
use Crab\Engine\Process\Worker;
use Crab\Testing\WorkerHarness;
use PHPUnit\Framework\TestCase;

final class Ticker implements WorkerProvider
{
    /**
     * @var list<string>
     */
    public array $events = [];
    private string $timer = '';

    public function __construct(
        private bool $timerFails = false,
    ) {}

    public function register(Container $container): void
    {
        $this->events[] = 'register';
        $container->instance('ticks', new \ArrayObject());
    }

    public function start(Worker $worker, Container $container): void
    {
        $this->events[] = 'start';
        $this->timer = $worker->loop->repeat(0.002, function () use ($container): void {
            if ($this->timerFails) {
                throw new \RuntimeException('tick failed');
            }
            $container->get('ticks')->append(true);
        });
    }

    public function stop(Worker $worker, Container $container): void
    {
        $this->events[] = 'stop';
        $worker->loop->cancel($this->timer);
    }
}

final class WorkerHarnessTest extends TestCase
{
    public function testRunsProvidersThroughAWorkersLife(): void
    {
        $ticker = new Ticker();
        $plain = new class implements Provider {
            public function register(Container $container): void
            {
                $container->instance('plain', 'registered');
            }
        };
        $result = WorkerHarness::run([$ticker, $plain], static function (Worker $worker, Container $container) use (
            $ticker,
        ): array {
            Async::sleep($worker->loop, 0.02);
            return [$ticker->events, count($container->get('ticks')) > 2, $container->get('plain')];
        });

        self::assertSame([['register', 'start'], true, 'registered'], $result);
        self::assertSame(['register', 'start', 'stop'], $ticker->events);
    }

    public function testFailuresAreThrownAfterTheWorkerStops(): void
    {
        $ticker = new Ticker();
        try {
            WorkerHarness::run([$ticker], static fn() => throw new \LogicException('body failed'));
            self::fail('The body\'s failure was lost.');
        } catch (\LogicException $error) {
            self::assertSame('body failed', $error->getMessage());
        }
        self::assertSame(['register', 'start', 'stop'], $ticker->events);

        $this->expectExceptionMessage('tick failed');
        WorkerHarness::run([new Ticker(timerFails: true)], static function (Worker $worker): void {
            Async::sleep($worker->loop, 0.01);
        });
    }
}
