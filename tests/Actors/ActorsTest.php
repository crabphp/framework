<?php

declare(strict_types=1);

namespace Crab\Tests\Actors;

use Crab\Actors\Actors;
use Crab\Contracts\Actors\Inbox;
use Crab\Contracts\Actors\NoReply;
use Crab\Contracts\Broadcast\Carrier;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use PHPUnit\Framework\TestCase;

final class ActorsTest extends TestCase
{
    public function testEachKeyHasOneActorThatKeepsItsStateBetweenMessages(): void
    {
        $actors = new Actors();

        self::assertSame(1, $actors->ask(Counter::class, 'a', 'add'));
        self::assertSame(2, $actors->ask(Counter::class, 'a', 'add'));
        self::assertSame(1, $actors->ask(Counter::class, 'b', 'add'));
        self::assertSame(2, $actors->count());

        $actors->stop();
    }

    public function testMessagesAreTakenOneAtATimeInTheOrderSent(): void
    {
        $actors = new Actors();
        Recorder::$seen = [];

        $actors->tell(Recorder::class, 'k', 1);
        $actors->tell(Recorder::class, 'k', 2);
        $actors->tell(Recorder::class, 'k', 3);
        $seen = $actors->ask(Recorder::class, 'k', 'seen');

        self::assertSame(['start 1', 'end 1', 'start 2', 'end 2', 'start 3', 'end 3'], $seen);
        $actors->stop();
    }

    public function testMessagesAndRepliesTravelAsJson(): void
    {
        $actors = new Actors();

        $reply = $actors->ask(Echoes::class, 'k', [
            'at' => new \DateTimeImmutable('2026-01-31 12:00:00 UTC'),
            'n' => 1.0,
        ]);

        self::assertSame(
            ['at' => ['date' => '2026-01-31 12:00:00.000000', 'timezone_type' => 3, 'timezone' => 'UTC'], 'n' => 1.0],
            $reply,
        );
        $this->expectException(\InvalidArgumentException::class);
        $actors->tell(Echoes::class, 'k', ['bytes' => "\xff"]);
    }

    public function testAnIdleActorEndsAndItsNextMessageStartsItAfresh(): void
    {
        $actors = new Actors(idle: 0.05);

        self::assertSame(1, $actors->ask(Counter::class, 'a', 'add'));
        self::assertSame(2, $actors->ask(Counter::class, 'a', 'add'));
        Async::sleep(EventLoop::current(), 0.1);

        self::assertSame(0, $actors->count());
        self::assertSame(1, $actors->ask(Counter::class, 'a', 'add'));
        $actors->stop();
    }

    public function testAnActorThatFailsRefusesTheAskAndIsStartedAgainByTheNextMessage(): void
    {
        $failed = new \ArrayObject();
        $actors = new Actors(logger: new class($failed) extends \Psr\Log\AbstractLogger {
            public function __construct(
                private \ArrayObject $failed,
            ) {}

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->failed[] = "{$level}: {$message}";
            }
        });

        self::assertSame(1, $actors->ask(Counter::class, 'a', 'add'));
        try {
            $actors->ask(Counter::class, 'a', 'fail');
            self::fail('The ask was answered.');
        } catch (NoReply $error) {
            self::assertStringContainsString('did not reply: it failed: told to', $error->getMessage());
        }
        self::assertSame(1, $actors->ask(Counter::class, 'a', 'add'));
        self::assertSame(['error: ' . Counter::class . ' for "a" failed: told to'], $failed->getArrayCopy());
        $actors->stop();
    }

    public function testMessagesAnActorLeftAreGivenToItsNextRun(): void
    {
        $actors = new Actors();

        $actors->tell(Counter::class, 'a', 'add');
        $actors->tell(Counter::class, 'a', 'quit');
        $actors->tell(Counter::class, 'a', 'add');

        self::assertSame(2, $actors->ask(Counter::class, 'a', 'add'));
        $actors->stop();
    }

    public function testAnAskEndsAtItsTimeoutOrItsCancellation(): void
    {
        $actors = new Actors();

        try {
            $actors->ask(Counter::class, 'a', 'ignore', timeout: 0.05);
            self::fail('The ask was answered.');
        } catch (NoReply $error) {
            self::assertStringContainsString('did not reply within 0.05 seconds', $error->getMessage());
        }

        $cancellation = new Cancellation();
        EventLoop::current()->delay(0.02, static fn() => $cancellation->cancel());
        $this->expectException(CancelledException::class);
        try {
            $actors->ask(Counter::class, 'a', 'ignore', cancellation: $cancellation);
        } finally {
            $actors->stop();
        }
    }

    public function testAMessageTheActorReturnsWithoutReplyingToIsRefused(): void
    {
        $actors = new Actors();

        $this->expectException(NoReply::class);
        $this->expectExceptionMessage('it returned without replying.');
        $actors->ask(Counter::class, 'a', 'quit');
    }

    public function testStoppingEndsTheActorsAndCancelsWhatTheyWaitOn(): void
    {
        $actors = new Actors();
        $loop = EventLoop::current();
        $asked = Task::spawn($loop, static fn() => $actors->ask(Counter::class, 'a', 'wait'));
        Async::sleep($loop, 0.01);

        $actors->stop();

        try {
            $asked->await();
            self::fail('The ask was answered.');
        } catch (NoReply $error) {
            self::assertStringContainsString('the process it runs in is stopping.', $error->getMessage());
        }
        self::assertSame(0, $actors->count());
        $this->expectException(NoReply::class);
        $this->expectExceptionMessage('stopping');
        $actors->ask(Counter::class, 'b', 'add');
    }

    public function testOnlyAClassWithHandleInboxAndKeyIsAnActor(): void
    {
        $actors = new Actors();
        foreach ([\stdClass::class, 'NoSuchActor', NotAnActor::class] as $class) {
            try {
                $actors->tell($class, 'k', null);
                self::fail("{$class} was taken for an actor.");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $actors->tell(Counter::class, '', null);
    }

    public function testWithACarrierEachKeyRunsInTheServerProcessItBelongsTo(): void
    {
        $carrier = new SharedCarrier();
        $loop = EventLoop::current();
        $web = new Actors(carrier: $carrier, workers: 2);
        $first = new Actors(carrier: $carrier, workers: 2);
        $second = new Actors(carrier: $carrier, workers: 2);
        $stop = new Cancellation();
        $servers = [
            Task::spawn($loop, static fn() => $first->serve([0], $stop)),
            Task::spawn($loop, static fn() => $second->serve([1], $stop)),
        ];
        Async::sleep($loop, 0.01);

        foreach (range(1, 8) as $i) {
            $web->tell(Counter::class, "key-{$i}", 'add');
        }
        foreach (range(1, 8) as $i) {
            self::assertSame(2, $web->ask(Counter::class, "key-{$i}", 'add'));
        }
        self::assertSame(0, $web->count());
        self::assertSame(8, $first->count() + $second->count());
        self::assertGreaterThan(0, $first->count());
        self::assertGreaterThan(0, $second->count());

        // An actor asks another, wherever it runs.
        Forwarder::$actors = $first;
        self::assertSame(3, $web->ask(Forwarder::class, 'f', 'key-1'));
        self::assertSame(4, $web->ask(Forwarder::class, 'g', 'key-1'));

        $stop->cancel();
        foreach ($servers as $server) {
            $server->await();
        }
        self::assertSame(0, $first->count() + $second->count());
        $this->expectException(NoReply::class);
        $web->ask(Counter::class, 'key-1', 'add', timeout: 0.05);
    }

    public function testTheServerNeedsACarrier(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('broadcast.carrier to redis');
        new Actors()->serve([0], new Cancellation());
    }
}

final class Counter
{
    public function handle(Inbox $inbox, string $key): void
    {
        $count = 0;
        foreach ($inbox as $message) {
            match ($message->data) {
                'add' => $message->reply(++$count),
                'fail' => throw new \RuntimeException('told to'),
                'quit', 'ignore' => null,
                'wait' => Async::sleep(EventLoop::current(), 10, $inbox->cancellation()),
            };
            if ($message->data === 'quit') {
                return;
            }
        }
    }
}

final class Recorder
{
    /** @var list<string> */
    public static array $seen = [];

    public function handle(Inbox $inbox, string $key): void
    {
        foreach ($inbox as $message) {
            if ($message->data === 'seen') {
                $message->reply(self::$seen);
                continue;
            }
            self::$seen[] = "start {$message->data}";
            Async::sleep(EventLoop::current(), 0.005);
            self::$seen[] = "end {$message->data}";
        }
    }
}

final class Echoes
{
    public function handle(Inbox $inbox, string $key): void
    {
        foreach ($inbox as $message) {
            $message->reply($message->data);
        }
    }
}

final class Forwarder
{
    public static Actors $actors;

    public function handle(Inbox $inbox, string $key): void
    {
        foreach ($inbox as $message) {
            $message->reply(self::$actors->ask(Counter::class, $message->data, 'add'));
        }
    }
}

final class NotAnActor
{
    public function handle(string $key): void {}
}

/**
 * Carries between the Actors of one test as Redis would between processes: later, and to every subscriber.
 */
final class SharedCarrier implements Carrier
{
    /** @var array<string, \Closure(string, string): void> */
    private array $receivers = [];

    public function publish(array $channels, string $message): void
    {
        foreach ($channels as $channel) {
            EventLoop::current()->defer(function () use ($channel, $message): void {
                ($this->receivers[$channel] ?? null)?->__invoke($channel, $message);
            });
        }
    }

    public function subscribe(string $channel, \Closure $receive, \Closure $gap): void
    {
        $this->receivers[$channel] = $receive;
    }

    public function unsubscribe(string $channel): void
    {
        unset($this->receivers[$channel]);
    }

    public function close(): void
    {
        $this->receivers = [];
    }
}
