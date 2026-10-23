<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Suspension;
use Crab\Engine\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use RuntimeException;

final class EventLoopTest extends TestCase
{
    public function testTimersRunInOrderAndCanBeCancelledDisabledAndUnreferenced(): void
    {
        $loop = new StreamSelectEventLoop();
        self::assertInstanceOf(EventLoop::class, $loop);
        $events = [];
        $deadline = $loop->delay(
            5,
            static fn() => throw new RuntimeException(
                'The repeating timer did not deliver three ticks within five seconds.',
            ),
        );
        $loop->unreference($deadline);
        $loop->delay(0.05, static function () use (&$events): void {
            $events[] = 'delay';
        });
        $loop->defer(static function (string $id) use (&$events): void {
            $events[] = 'defer ' . (strlen($id) > 0 ? 'with id' : 'without id');
        });
        $cancelled = $loop->delay(0.001, static function () use (&$events): void {
            $events[] = 'cancelled';
        });
        $loop->cancel($cancelled);
        $loop->cancel($cancelled);
        $paused = $loop->delay(0.001, static function () use (&$events): void {
            $events[] = 'paused';
        });
        $loop->disable($paused);
        self::assertFalse($loop->isEnabled($paused));
        $ticks = 0;
        $repeat = $loop->repeat(0.001, static function (string $id) use ($loop, &$ticks, &$events): void {
            if (++$ticks === 3) {
                $events[] = 'three ticks';
                $loop->cancel($id);
            }
        });
        self::assertTrue($loop->isEnabled($repeat));
        $background = $loop->repeat(60, static function (): void {});
        $loop->unreference($background);
        self::assertContains($background, $loop->getIdentifiers());

        $loop->run();
        self::assertSame('defer with id', array_shift($events));
        self::assertEqualsCanonicalizing(['three ticks', 'delay'], $events);
        self::assertSame(3, $ticks);
        $loop->cancel($deadline);

        $loop->enable($paused);
        $loop->run();
        self::assertSame('paused', end($events));
        $loop->cancel($background);
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testStreamsStopErrorsAndSuspension(): void
    {
        $loop = new StreamSelectEventLoop();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        $this->deferCleanup(static function () use ($pair): void {
            array_map(static fn($stream) => is_resource($stream) && fclose($stream), $pair);
        });
        $read = '';
        $loop->onWritable($pair[0], static function (string $id, mixed $stream) use ($loop): void {
            fwrite($stream, 'ping');
            $loop->cancel($id);
        });
        $loop->onReadable($pair[1], static function (string $id, mixed $stream) use ($loop, &$read): void {
            $read = fread($stream, 16);
            $loop->cancel($id);
            $loop->stop();
        });
        $keepsGoing = $loop->repeat(60, static function (): void {});
        $loop->run();
        self::assertSame('ping', $read);
        $loop->cancel($keepsGoing);

        $caught = [];
        $loop->setErrorHandler(static function (\Throwable $error) use (&$caught): void {
            $caught[] = $error->getMessage();
        });
        $loop->defer(static fn() => throw new RuntimeException('contained'));
        $loop->run();
        self::assertSame(['contained'], $caught);
        $loop->setErrorHandler(null);

        $suspension = $loop->getSuspension();
        self::assertInstanceOf(Suspension::class, $suspension);
        $loop->delay(0.001, static fn() => $suspension->resume('value'));
        self::assertSame('value', $suspension->suspend());
        $loop->delay(0.001, static fn() => $suspension->throw(new RuntimeException('thrown into the waiter')));
        $this->expectExceptionMessage('thrown into the waiter');
        $suspension->suspend();
    }

    public function testCallbacksThatSuspendFinishAndTheirFibersAreReused(): void
    {
        $loop = new StreamSelectEventLoop();
        $finished = [];
        // More than the spare-fiber cap, twice over, so spares are both reused and retired.
        for ($round = 0; $round < 2; $round++) {
            for ($i = 0; $i < 300; $i++) {
                $loop->defer(static function () use ($loop, $i, &$finished): void {
                    $suspension = $loop->getSuspension();
                    $loop->delay(0.001 * ($i % 7), static fn() => $suspension->resume($i));
                    $value = $suspension->suspend();
                    $second = $loop->getSuspension();
                    $loop->defer(static fn() => $second->resume($value * 2));
                    $finished[] = $second->suspend();
                });
            }
            $loop->run();
        }
        self::assertCount(600, $finished);
        self::assertSame(array_sum(range(0, 299)) * 4, array_sum($finished));
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testFailuresOfResumedCallbacksReachTheErrorHandlerOrEndRun(): void
    {
        $loop = new StreamSelectEventLoop();
        $caught = [];
        $loop->setErrorHandler(static function (\Throwable $error) use (&$caught): void {
            $caught[] = $error->getMessage();
        });
        $loop->defer(static function () use ($loop): void {
            $suspension = $loop->getSuspension();
            $loop->delay(0.001, static fn() => $suspension->resume());
            $suspension->suspend();
            throw new RuntimeException('after resuming');
        });
        $loop->run();
        self::assertSame(['after resuming'], $caught);

        $loop->setErrorHandler(null);
        $loop->defer(static fn() => throw new RuntimeException('unhandled'));
        $ranAfter = false;
        $loop->defer(static function () use (&$ranAfter): void {
            $ranAfter = true;
        });
        try {
            $loop->run();
            self::fail('run() returned despite an unhandled failure.');
        } catch (RuntimeException $error) {
            self::assertSame('unhandled', $error->getMessage());
        }
        // The loop is still usable and picks up where it stopped.
        $loop->run();
        self::assertTrue($ranAfter);
        self::assertFalse($loop->isRunning());
    }

    public function testWatchersEnabledDuringATurnFireOnTheNextAndUnknownIdsAreRejected(): void
    {
        $loop = new StreamSelectEventLoop();
        $order = [];
        $loop->defer(static function () use ($loop, &$order): void {
            $order[] = 'first';
            $loop->defer(static function () use (&$order): void {
                $order[] = 'next turn';
            });
            $order[] = 'still first turn';
        });
        $loop->defer(static function () use (&$order): void {
            $order[] = 'second';
        });
        $loop->run();
        self::assertSame(['first', 'still first turn', 'second', 'next turn'], $order);

        $loop->disable('missing');
        $loop->cancel('missing');
        $loop->unreference('missing');
        $rejected = 0;
        foreach ([$loop->enable(...), $loop->reference(...), $loop->isEnabled(...)] as $method) {
            try {
                $method('missing');
            } catch (\Error) {
                $rejected++;
            }
        }
        self::assertSame(3, $rejected);
    }

    public function testAMainProgramSuspensionNobodyResumesReportsTheDeadlock(): void
    {
        $loop = new StreamSelectEventLoop();
        $suspension = $loop->getSuspension();
        $loop->defer(static function (): void {});
        $this->expectExceptionMessage('Event loop ended without resuming the current suspension');
        $suspension->suspend();
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testSignalsRunTheirCallbacks(): void
    {
        $loop = new StreamSelectEventLoop();
        $received = null;
        $loop->onSignal(SIGUSR1, static function (string $id, int $signal) use ($loop, &$received): void {
            $received = $signal;
            $loop->cancel($id);
        });
        $loop->defer(static function (): void {
            posix_kill(posix_getpid(), SIGUSR1);
        });
        $loop->run();
        self::assertSame(SIGUSR1, $received);
    }

    public function testBackendsAndTheCurrentLoop(): void
    {
        $loop = RevoltEventLoop::create('select');
        self::assertSame($loop, EventLoop::install($loop));
        self::assertSame($loop, EventLoop::current());
        self::assertSame(
            $loop->driver,
            \Revolt\EventLoop::getDriver(),
            'Libraries built on Revolt must find the installed loop.',
        );

        $rejected = 0;
        try {
            RevoltEventLoop::create('epoll');
        } catch (\InvalidArgumentException) {
            ++$rejected;
        }
        foreach (['ev', 'event', 'uv'] as $backend) {
            try {
                self::assertInstanceOf(EventLoop::class, RevoltEventLoop::create($backend));
            } catch (RuntimeException $error) {
                self::assertStringContainsString("needs the {$backend} PHP extension", $error->getMessage());
            }
        }
        self::assertSame(1, $rejected);
    }
}
