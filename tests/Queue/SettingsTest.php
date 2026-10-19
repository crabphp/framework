<?php

declare(strict_types=1);

namespace Crab\Tests\Queue;

use Crab\App;
use Crab\Config\Config;
use Crab\Console\Input;
use Crab\Console\Output;
use Crab\Contracts\Clock\Clock;
use Crab\Contracts\Queue\Store;
use Crab\Contracts\Runner;
use Crab\Queue\Commands\Work;
use Crab\Queue\Consumer;
use Crab\Queue\MemoryStore;
use Crab\Queue\QueueProvider;
use Crab\Queue\Supervisor;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingsFailure
{
    public function handle(): void
    {
        throw new \RuntimeException('deliberate job failure');
    }
}

final class SettingsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidRetrySettings(): iterable
    {
        foreach (['tries', 'retry_after'] as $setting) {
            foreach ([
                '2tries',
                '1.5',
                '9223372036854775808',
                '',
                '0',
                '-1',
                '2e1',
                "2\n",
                0,
                -1,
                1.0,
                1.5,
                true,
                false,
                null,
                [],
            ] as $index => $value) {
                yield $setting . ' case ' . $index => [$setting, $value];
            }
        }
        foreach ([
            ['10seconds'],
            ['1.5'],
            ['9223372036854775808'],
            [-1],
            ['-1'],
            [true],
            [null],
            [[]],
            [''],
            [1.0],
            [0 => 0, 2 => 1],
            ['later' => 1],
            1,
            '10',
            null,
            false,
        ] as $index => $value) {
            yield 'backoff case ' . $index => ['backoff', $value];
        }
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function invalidSupervisorSettings(): iterable
    {
        yield from self::invalidRetrySettings();
        foreach (['1second', 'banana', '', -1, '-0.1', INF, NAN, '1e999', true, false, null, []] as $index => $value) {
            yield 'sleep case ' . $index => ['sleep', $value];
        }
    }

    #[DataProvider('invalidSupervisorSettings')]
    public function testInvalidRetryAndSleepSettingsStopStandaloneAndIntegratedWorkersBeforeWork(
        string $setting,
        mixed $value,
    ): void {
        foreach ([false, true] as $named) {
            $settings = $named
                ? ['supervisors' => ['mail' => ['queues' => ['mail'], $setting => $value]]]
                : [$setting => $value];
            $app = $this->app($settings);
            $opened = 0;
            $app->container->singleton(Store::class, static function () use (&$opened): Store {
                ++$opened;
                return new MemoryStore();
            });
            $runner = $this->createMock(Runner::class);
            $runner->expects(self::never())->method('run');
            $runner->expects(self::never())->method('runAll');
            $command = new Work($runner, new Consumer(new MemoryStore()), $app->config);
            $stream = fopen('php://memory', 'w+');
            try {
                foreach ([
                    static fn() => $named ? Supervisor::all($app->config) : Supervisor::from($app->config, 'queue'),
                    static fn() => $command->handle(new Input(['--quiet']), new Output($stream)),
                    static fn() => $app->alongside(['queue']),
                ] as $start) {
                    $error = null;
                    try {
                        $start();
                    } catch (\Throwable $caught) {
                        $error = $caught;
                    }
                    self::assertInstanceOf(\InvalidArgumentException::class, $error);
                    self::assertStringContainsString($setting, $error->getMessage());
                    self::assertStringContainsString('queue.' . $setting, $error->getMessage());
                    self::assertStringContainsString($named ? 'mail' : 'queue', $error->getMessage());
                    self::assertSame(0, $opened, 'Invalid settings must be refused before opening the queue store.');
                }
            } finally {
                fclose($stream);
            }
        }
    }

    #[DataProvider('invalidRetrySettings')]
    public function testTheProviderRefusesTheSameRetrySettingsBeforeOpeningItsStore(string $setting, mixed $value): void
    {
        $app = $this->app([$setting => $value]);
        $opened = 0;
        $app->container->singleton(Store::class, static function () use (&$opened): Store {
            ++$opened;
            return new MemoryStore();
        });
        $error = null;
        try {
            $app->container->get(Consumer::class);
        } catch (\Throwable $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(\InvalidArgumentException::class, $error);
        self::assertStringContainsString('queue.' . $setting, $error->getMessage());
        self::assertSame(0, $opened);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array{int, list<int>, int, float}}>
     */
    public static function validSettings(): iterable
    {
        yield 'defaults' => [[], [3, [10, 60], 90, 1.0]];
        yield 'numeric strings' => [
            ['tries' => '2', 'backoff' => ['0', '3'], 'retry_after' => '7', 'sleep' => '0.25'],
            [2, [0, 3], 7, 0.25],
        ];
        yield 'zero pauses' => [['backoff' => [], 'sleep' => 0], [3, [], 90, 0.0]];
        yield 'zero string pause' => [['backoff' => [0], 'sleep' => '0'], [3, [0], 90, 0.0]];
        yield 'fractional pause' => [['sleep' => 0.5], [3, [10, 60], 90, 0.5]];
        yield 'exponent pause' => [['sleep' => '2e-1'], [3, [10, 60], 90, 0.2]];
        yield 'integer limits' => [
            ['tries' => (string) PHP_INT_MAX, 'backoff' => [(string) PHP_INT_MAX], 'retry_after' => PHP_INT_MAX],
            [PHP_INT_MAX, [PHP_INT_MAX], PHP_INT_MAX, 1.0],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array{int, list<int>, int, float} $expected
     */
    #[DataProvider('validSettings')]
    public function testValidRetrySettingsAndZeroOrFractionalPausesKeepTheirValues(
        array $settings,
        array $expected,
    ): void {
        foreach ([false, true] as $named) {
            $app = $this->app($named ? ['supervisors' => ['mail' => ['queues' => ['mail'], ...$settings]]] : $settings);
            $supervisor = $named ? Supervisor::all($app->config)['mail'] : Supervisor::from($app->config, 'queue');
            self::assertSame($expected, [
                $supervisor->tries,
                $supervisor->backoff,
                $supervisor->retryAfter,
                $supervisor->sleep,
            ]);
            self::assertInstanceOf(Consumer::class, $app->container->get(Consumer::class));
        }
    }

    public function testConfiguredRetriesBackoffAndRecoveryControlBothConsumerPaths(): void
    {
        foreach ([false, true] as $named) {
            $settings = ['tries' => '2', 'backoff' => ['3'], 'retry_after' => '7'];
            $app = $this->app($named ? ['supervisors' => ['mail' => ['queues' => ['mail'], ...$settings]]] : $settings);
            $clock = new FrozenClock('2026-01-31T12:00:00Z');
            $app->container->instance(Clock::class, $clock);
            $store = $app->container->get(Store::class);
            $consumer = $app->container->get(Consumer::class);
            if ($named) {
                $consumer = $consumer->within(Supervisor::all($app->config)['mail']);
            }
            $now = $clock->now()->getTimestamp();
            $store->push('mail', SettingsFailure::class, [], $now, $now);
            self::assertTrue($consumer->runNext(['mail']));
            self::assertSame($now + 3, $store->jobs('mail', 'delayed', $now)[0]->availableAt);
            self::assertFalse($consumer->runNext(['mail']));
            $clock->advance('+3 seconds');
            self::assertTrue($consumer->runNext(['mail']));
            self::assertSame(2, $store->failed()[0]->attempts);

            $now = $clock->now()->getTimestamp();
            $id = $store->push('mail', SettingsFailure::class, [], $now, $now);
            self::assertSame($id, $store->reserve(['mail'], $now, 7)?->id);
            $clock->advance('+6 seconds');
            self::assertFalse($consumer->runNext(['mail']));
            $clock->advance('+1 second');
            self::assertTrue($consumer->runNext(['mail']));
            self::assertSame($id, $store->failed()[1]->id);
            self::assertSame(2, $store->failed()[1]->attempts);
        }
    }

    public function testAStandaloneConsumerDoesNotReadUnusedWorkerOrPollingSettings(): void
    {
        $app = $this->app(['workers' => 0, 'concurrency' => null, 'sleep' => NAN]);

        self::assertFalse($app->container->get(Consumer::class)->runNext());
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function app(array $settings): App
    {
        $app = new App(new Config([
            'queue' => ['store' => 'memory', ...$settings],
            'log' => ['path' => 'php://memory'],
        ]));
        $app->register(QueueProvider::class);
        return $app;
    }
}
