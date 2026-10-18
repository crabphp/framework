<?php

declare(strict_types=1);

namespace Crab\Tests\Clock;

use Crab\App;
use Crab\Clock\SystemClock;
use Crab\Contracts\Clock\Clock;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class ClockTest extends TestCase
{
    public function testTheSystemClockTellsTheTimeInItsZone(): void
    {
        $before = time();
        $now = new SystemClock()->now();
        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual(time(), $now->getTimestamp());
        self::assertSame(date_default_timezone_get(), $now->getTimezone()->getName());
        self::assertSame(
            'Asia/Ho_Chi_Minh',
            new SystemClock(new \DateTimeZone('Asia/Ho_Chi_Minh'))->now()->getTimezone()->getName(),
        );
    }

    public function testAFrozenClockStandsStillUntilItIsMoved(): void
    {
        $clock = new FrozenClock('2026-01-31 12:00:00');
        self::assertSame($clock->now(), $clock->now());
        self::assertSame('2026-01-31 12:00:00', $clock->now()->format('Y-m-d H:i:s'));
        $clock->advance('+90 minutes');
        self::assertSame('13:30', $clock->now()->format('H:i'));
        $clock->advance('-1 day');
        self::assertSame('2026-01-30', $clock->now()->format('Y-m-d'));
        $moment = new \DateTimeImmutable('2030-05-05 05:05:05');
        $clock->set($moment);
        self::assertSame($moment, $clock->now());
        self::assertEqualsWithDelta(time(), new FrozenClock()->now()->getTimestamp(), 2);
    }

    public function testTheApplicationBindsTheClock(): void
    {
        $app = new App();
        $clock = $app->container->get(Clock::class);
        self::assertInstanceOf(SystemClock::class, $clock);
        self::assertSame($clock, $app->container->get(ClockInterface::class), 'The PSR-20 name gives the same clock.');
        self::assertSame($clock, $app->container->get(SystemClock::class));

        $frozen = new FrozenClock('2026-01-31 12:00:00');
        self::assertInstanceOf(Clock::class, $frozen);
        $replaced = new App();
        $replaced->container->instance(Clock::class, $frozen);
        self::assertSame(
            $frozen,
            $replaced->container->get(Clock::class),
            'A test replaces it with one that stands still.',
        );
        self::assertSame($frozen, $replaced->container->get(ClockInterface::class), 'And the PSR-20 name follows.');
    }
}
