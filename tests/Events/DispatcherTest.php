<?php

declare(strict_types=1);

namespace Crab\Tests\Events;

use Crab\Container\Container;
use Crab\Contracts\Events\Dispatcher as DispatcherContract;
use Crab\Events\Dispatcher;
use Crab\Events\EventsProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

interface Audited {}

class UserRegistered implements Audited
{
    /**
     * @var list<string>
     */
    public array $heard = [];

    public function __construct(
        public readonly int $id,
    ) {}
}

final class AdminRegistered extends UserRegistered {}

final class Cancellable implements StoppableEventInterface
{
    public bool $stopped = false;
    public int $heard = 0;

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}

final class Recorder
{
    public static int $made = 0;

    public function __construct(
        public readonly Container $container,
    ) {
        ++self::$made;
    }

    public function handle(UserRegistered $event): void
    {
        $event->heard[] = 'recorder';
    }
}

final class DispatcherTest extends TestCase
{
    public function testListenersHearTheirEventInTheOrderAdded(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->listen(UserRegistered::class, static function (UserRegistered $event): void {
            $event->heard[] = 'first';
        });
        $dispatcher->listen(UserRegistered::class, static function (UserRegistered $event): void {
            $event->heard[] = 'second';
        });

        $event = new UserRegistered(7);

        self::assertSame($event, $dispatcher->dispatch($event));
        self::assertSame(['first', 'second'], $event->heard);
    }

    public function testAListenerHearsSubclassesAndImplementations(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->listen(Audited::class, static function (object $event): void {
            $event->heard[] = 'audited';
        });
        $dispatcher->listen(UserRegistered::class, static function (UserRegistered $event): void {
            $event->heard[] = 'user';
        });
        $dispatcher->listen(AdminRegistered::class, static function (AdminRegistered $event): void {
            $event->heard[] = 'admin';
        });

        self::assertSame(['audited', 'user'], $dispatcher->dispatch(new UserRegistered(1))->heard);
        self::assertSame(['audited', 'user', 'admin'], $dispatcher->dispatch(new AdminRegistered(2))->heard);
    }

    public function testAnEventNoOneListensForIsReturnedUntouched(): void
    {
        $event = new UserRegistered(1);

        self::assertSame($event, new Dispatcher()->dispatch($event));
        self::assertSame([], $event->heard);
    }

    public function testAListenerClassIsMadeByTheContainerOnceAndOnlyWhenNeeded(): void
    {
        Recorder::$made = 0;
        $dispatcher = new Dispatcher(new Container());
        $dispatcher->listen(UserRegistered::class, Recorder::class);
        self::assertSame(0, Recorder::$made);

        $dispatcher->dispatch(new UserRegistered(1));
        $event = $dispatcher->dispatch(new UserRegistered(2));

        self::assertSame(1, Recorder::$made);
        self::assertSame(['recorder'], $event->heard);
    }

    public function testAStoppedEventReachesNoFurtherListener(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->listen(Cancellable::class, static function (Cancellable $event): void {
            ++$event->heard;
            $event->stopped = true;
        });
        $dispatcher->listen(Cancellable::class, static function (Cancellable $event): void {
            ++$event->heard;
        });

        self::assertSame(1, $dispatcher->dispatch(new Cancellable())->heard);
    }

    public function testWhatAListenerThrowsReachesTheDispatcherAndStopsTheRest(): void
    {
        $dispatcher = new Dispatcher();
        $dispatcher->listen(UserRegistered::class, static fn() => throw new \RuntimeException('mail is down'));
        $dispatcher->listen(UserRegistered::class, static function (UserRegistered $event): void {
            $event->heard[] = 'late';
        });
        $event = new UserRegistered(1);

        try {
            $dispatcher->dispatch($event);
            self::fail('The failure was swallowed.');
        } catch (\RuntimeException $error) {
            self::assertSame('mail is down', $error->getMessage());
        }
        self::assertSame([], $event->heard);
    }

    public function testAMistakeInAddingAListenerIsRefusedWhereItIsMade(): void
    {
        $dispatcher = new Dispatcher();

        try {
            $dispatcher->listen('App\\Events\\Missing', static fn() => null);
            self::fail('An event that is no class was accepted.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('App\\Events\\Missing', $error->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('handle()');
        $dispatcher->listen(UserRegistered::class, \stdClass::class);
    }

    public function testTheProviderBindsOneDispatcherUnderEveryName(): void
    {
        $container = new Container();
        new EventsProvider()->register($container);

        $dispatcher = $container->get(DispatcherContract::class);

        self::assertInstanceOf(Dispatcher::class, $dispatcher);
        self::assertSame($dispatcher, $container->get(EventDispatcherInterface::class));
        self::assertSame($dispatcher, $container->get(Dispatcher::class));
    }

    public function testListenersRunInTheOrderAddedWhateverTheyListenFor(): void
    {
        $dispatcher = new Dispatcher();
        $order = [];
        // For the interface, then the class, then the interface again.
        $dispatcher->listen(Audited::class, static function () use (&$order): void {
            $order[] = 'first, for the interface';
        });
        $dispatcher->listen(UserRegistered::class, static function () use (&$order): void {
            $order[] = 'second, for the class';
        });
        $dispatcher->listen(Audited::class, static function () use (&$order): void {
            $order[] = 'third, for the interface';
        });

        $dispatcher->dispatch(new UserRegistered(7));

        self::assertSame(['first, for the interface', 'second, for the class', 'third, for the interface'], $order);
    }
}
