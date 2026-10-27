<?php

declare(strict_types=1);

namespace Crab\Tests\Container;

use Crab\Container\Container;
use Crab\Container\ContainerException;
use Crab\Container\NotFoundException;
use Crab\Contracts\Container\Container as ContainerContract;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

interface Clock {}

final class SystemClock implements Clock {}

final class Mailer
{
    public function __construct(
        public Clock $clock,
        public SystemClock $system,
        public ?Clock $fallback = null,
        public ?Missing $missing = null,
        public string $from = 'crab@example.com',
        public ?int $limit = null,
    ) {}
}

interface Missing {}

final class NeedsMissing
{
    public function __construct(
        public Missing $missing,
    ) {}
}

final class NeedsScalar
{
    public function __construct(
        public string $dsn,
    ) {}
}

final class First
{
    public function __construct(
        public Second $second,
    ) {}
}

final class Second
{
    public function __construct(
        public First $first,
    ) {}
}

abstract class Shape {}

final class ContainerTest extends TestCase
{
    public function testAutowiresConstructorsAndKeepsOnePerContainer(): void
    {
        $container = new Container();
        $container->singleton(Clock::class, SystemClock::class);
        $mailer = $container->get(Mailer::class);

        self::assertInstanceOf(SystemClock::class, $mailer->clock);
        self::assertSame($mailer, $container->get(Mailer::class));
        self::assertSame($mailer->system, $container->get(SystemClock::class));
        self::assertSame($mailer->system, $mailer->clock);
        self::assertSame($mailer->clock, $mailer->fallback);
        self::assertNull($mailer->missing);
        self::assertSame(['crab@example.com', null], [$mailer->from, $mailer->limit]);
    }

    public function testBindingsSingletonsAndInstances(): void
    {
        $container = new Container();
        $container->bind(Clock::class, SystemClock::class);
        self::assertNotSame($container->get(Clock::class), $container->get(Clock::class));
        self::assertNotSame($container->get(Clock::class), $container->get(SystemClock::class));

        $container->bind('counter', static function (): object {
            static $made = 0;
            return (object) ['made' => ++$made];
        });
        self::assertSame([1, 2], [$container->get('counter')->made, $container->get('counter')->made]);

        $container->singleton('config', static fn(ContainerInterface $c): array => [
            'clock' => $c->get(Clock::class)::class,
        ]);
        self::assertSame(['clock' => SystemClock::class], $container->get('config'));

        $container->instance('nothing', null);
        self::assertTrue($container->has('nothing'));
        self::assertNull($container->get('nothing'));

        $clock = new SystemClock();
        $container->instance(Clock::class, $clock);
        self::assertSame($clock, $container->get(Clock::class));
        $container->singleton(Clock::class, SystemClock::class);
        self::assertNotSame($clock, $container->get(Clock::class));
    }

    public function testContainerProvidesItself(): void
    {
        $container = new Container();
        self::assertSame($container, $container->get(ContainerInterface::class));
        self::assertSame($container, $container->get(ContainerContract::class));
        self::assertSame($container, $container->get(Container::class));
    }

    public function testHas(): void
    {
        $container = new Container();
        $container->bind('named', static fn() => 1);
        self::assertTrue($container->has('named'));
        self::assertTrue($container->has(SystemClock::class));
        self::assertFalse($container->has(Clock::class));
        self::assertFalse($container->has(Shape::class));
        self::assertFalse($container->has('unknown'));
    }

    public function testUnknownEntriesAreNotFound(): void
    {
        $container = new Container();
        foreach (['unknown', Clock::class, Shape::class] as $id) {
            try {
                $container->get($id);
                self::fail("{$id} was resolved.");
            } catch (NotFoundException $error) {
                self::assertStringContainsString($id, $error->getMessage());
            }
        }
    }

    public function testUnresolvableDependenciesAreContainerErrors(): void
    {
        $container = new Container();
        foreach ([
            NeedsMissing::class => Missing::class,
            NeedsScalar::class => '$dsn',
            First::class => 'depends on itself',
        ] as $id => $message) {
            try {
                $container->get($id);
                self::fail("{$id} was resolved.");
            } catch (ContainerException $error) {
                self::assertNotInstanceOf(NotFoundException::class, $error);
                self::assertStringContainsString($message, $error->getMessage());
            }
        }
        $container->bind(NeedsScalar::class, static fn() => new NeedsScalar('sqlite::memory:'));
        self::assertSame('sqlite::memory:', $container->get(NeedsScalar::class)->dsn);
    }

    public function testAProblemIsFoundAlongWhatAnEntryNeedsWithoutMakingAnything(): void
    {
        $container = new Container();
        $made = 0;
        $container->singleton(Clock::class, static function () use (&$made): SystemClock {
            ++$made;
            return new SystemClock();
        });
        self::assertNull($container->problem(Mailer::class), 'An optional entry with no binding is passed over.');
        self::assertSame(0, $made, 'A closure is not run to find out.');

        self::assertSame(
            'Crab\Tests\Container\NeedsMissing needs Crab\Tests\Container\Missing, which has no binding and is not a class that can be constructed.',
            $container->problem(NeedsMissing::class),
        );
        self::assertSame(
            'Crab\Tests\Container\NeedsScalar takes $dsn, which cannot be autowired; bind Crab\Tests\Container\NeedsScalar with a closure.',
            $container->problem(NeedsScalar::class),
        );
        self::assertSame(
            'Crab\Tests\Container\First needs Crab\Tests\Container\Second, which needs Crab\Tests\Container\First, which depends on itself.',
            $container->problem(First::class),
        );
        $container->singleton('shape', Shape::class);
        self::assertSame(
            'shape is bound to Crab\Tests\Container\Shape, which has no binding and is not a class that can be constructed.',
            $container->problem('shape'),
        );

        // What was sound is asked again once a binding changes.
        $container->singleton(Missing::class, Shape::class);
        self::assertStringContainsString(
            'is bound to Crab\Tests\Container\Shape',
            (string) $container->problem(NeedsMissing::class),
        );
        $container->instance(Missing::class, new class implements Missing {});
        self::assertNull($container->problem(NeedsMissing::class));
    }
}
