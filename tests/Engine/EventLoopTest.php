<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\Suspension;
use Crab\Engine\Tests\Support\TestCase;
use RuntimeException;

final class EventLoopTest extends TestCase
{
    public function testTimersRunInOrderAndCanBeCancelledDisabledAndUnreferenced(): void
    {
        $loop = RevoltEventLoop::create();
        self::assertInstanceOf(EventLoop::class, $loop);
        $events = [];
        $loop->delay(0.05, static function () use (&$events): void { $events[] = 'delay'; });
        $loop->defer(static function (string $id) use (&$events): void { $events[] = 'defer ' . (strlen($id) > 0 ? 'with id' : 'without id'); });
        $cancelled = $loop->delay(0.001, static function () use (&$events): void { $events[] = 'cancelled'; });
        $loop->cancel($cancelled);
        $loop->cancel($cancelled);
        $paused = $loop->delay(0.001, static function () use (&$events): void { $events[] = 'paused'; });
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
        self::assertSame(['defer with id', 'three ticks', 'delay'], $events);

        $loop->enable($paused);
        $loop->run();
        self::assertSame('paused', end($events));
        $loop->cancel($background);
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testStreamsStopErrorsAndSuspension(): void
    {
        $loop = RevoltEventLoop::create();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        $this->deferCleanup(static function () use ($pair): void { array_map(static fn ($stream) => is_resource($stream) && fclose($stream), $pair); });
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
        $loop->setErrorHandler(static function (\Throwable $error) use (&$caught): void { $caught[] = $error->getMessage(); });
        $loop->defer(static fn () => throw new RuntimeException('contained'));
        $loop->run();
        self::assertSame(['contained'], $caught);
        $loop->setErrorHandler(null);

        $suspension = $loop->getSuspension();
        self::assertInstanceOf(Suspension::class, $suspension);
        $loop->delay(0.001, static fn () => $suspension->resume('value'));
        self::assertSame('value', $suspension->suspend());
        $loop->delay(0.001, static fn () => $suspension->throw(new RuntimeException('thrown into the waiter')));
        $this->expectExceptionMessage('thrown into the waiter');
        $suspension->suspend();
    }

    public function testBackendsAndTheCurrentLoop(): void
    {
        $loop = RevoltEventLoop::create('select');
        self::assertSame($loop, EventLoop::install($loop));
        self::assertSame($loop, EventLoop::current());
        self::assertSame($loop->driver, \Revolt\EventLoop::getDriver(), 'Libraries built on Revolt must find the installed loop.');

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
