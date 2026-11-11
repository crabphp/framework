<?php

declare(strict_types=1);

namespace Crab\Tests\Config;

use Crab\App;
use Crab\Config\Config;
use PHPUnit\Framework\TestCase;

/**
 * app.timezone is the zone every date of the process is in.
 */
final class TimezoneTest extends TestCase
{
    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    public function testTheApplicationIsInUtcUnlessItSaysOtherwise(): void
    {
        date_default_timezone_set('America/New_York');
        new App(new Config([]));
        self::assertSame('UTC', date_default_timezone_get());

        new App(new Config(['app' => ['timezone' => 'Asia/Ho_Chi_Minh']]));
        self::assertSame('Asia/Ho_Chi_Minh', date_default_timezone_get());
        self::assertSame('+07:00', new \DateTimeImmutable('2026-01-01 00:00:00')->format('P'));
    }

    public function testAZoneThatDoesNotExistIsSaidAtStartAndChangesNothing(): void
    {
        date_default_timezone_set('Europe/Paris');
        $app = new App(new Config(['app' => ['timezone' => 'Asia/Hanoi']]));
        self::assertSame('Europe/Paris', date_default_timezone_get());
        self::assertSame(
            ['app.timezone is "Asia/Hanoi"; it takes a time zone, such as "UTC".'],
            $app->config->problems(),
        );
    }
}
