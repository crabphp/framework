<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\EventLoop\PollEventLoop;
use Crab\Engine\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhp;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

#[RequiresPhp('>= 8.6.0')]
final class PollEventLoopTest extends TestCase
{
    public function testAStreamWatchedForBothSidesKeepsOneWhenTheOtherIsCancelled(): void
    {
        $loop = new PollEventLoop();
        self::assertContains($loop->backend(), ['Epoll', 'Kqueue', 'EventPorts', 'Poll']);
        [$left, $right] = $this->pair();
        $events = [];
        $loop->onWritable($left, static function (string $id) use ($loop, &$events): void {
            $events[] = 'writable';
            $loop->cancel($id);
        });
        $loop->onReadable($left, static function (string $id, mixed $stream) use ($loop, &$events): void {
            $events[] = 'read ' . fread($stream, 16);
            $loop->cancel($id);
        });
        $loop->delay(0.01, static function () use ($right): void {
            fwrite($right, 'pong');
        });
        $loop->run();
        self::assertSame(['writable', 'read pong'], $events);
    }

    public function testAClosedStreamsWatcherIsCancelledAndItsDescriptorReused(): void
    {
        $loop = new PollEventLoop();
        [$left, $right] = $this->pair();
        $id = $loop->onReadable($left, static function (): void {});
        $loop->delay(0.001, static function () use ($loop, $left, $id): void {
            fclose($left);
            $loop->cancel($id);
        });
        $loop->run();

        // The descriptor just freed is likely the next one handed out.
        [$again, $other] = $this->pair();
        $read = null;
        $loop->onReadable($again, static function (string $id, mixed $stream) use ($loop, &$read): void {
            $read = fread($stream, 16);
            $loop->cancel($id);
        });
        fwrite($other, 'fresh');
        $loop->run();
        self::assertSame('fresh', $read);
        fclose($right);
    }

    public function testAHangUpWakesTheReader(): void
    {
        $loop = new PollEventLoop();
        [$left, $right] = $this->pair();
        $eof = null;
        $loop->onReadable($left, static function (string $id, mixed $stream) use ($loop, &$eof): void {
            fread($stream, 16);
            $eof = feof($stream);
            $loop->cancel($id);
        });
        $loop->delay(0.001, static function () use ($right): void {
            fclose($right);
        });
        $loop->run();
        self::assertTrue($eof);
    }

    public function testItWatchesDescriptorsBeyondFdSetSize(): void
    {
        if (!function_exists('posix_getrlimit') || (posix_getrlimit()['soft openfiles'] ?? 0) < 2300) {
            self::markTestSkipped('Needs a descriptor limit of at least 2300.');
        }
        $loop = new PollEventLoop();
        $pairs = [];
        for ($i = 0; $i < 1100; ++$i) {
            $pairs[] = $this->pair();
        }
        [$last, $writer] = $pairs[array_key_last($pairs)];
        $read = null;
        $loop->onReadable($last, static function (string $id, mixed $stream) use ($loop, &$read): void {
            $read = fread($stream, 16);
            $loop->cancel($id);
        });
        fwrite($writer, 'far');
        $loop->run();
        self::assertSame('far', $read);
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testASignalInterruptsABlockingWait(): void
    {
        $loop = new PollEventLoop();
        [$left] = $this->pair();
        $idle = $loop->onReadable($left, static function (): void {});
        $received = null;
        $loop->onSignal(SIGUSR1, static function (string $id, int $signal) use ($loop, $idle, &$received): void {
            $received = $signal;
            $loop->cancel($id);
            $loop->cancel($idle);
        });
        $child = pcntl_fork();
        if ($child === 0) {
            usleep(50_000);
            posix_kill(posix_getppid(), SIGUSR1);
            exit(0);
        }
        $started = hrtime(true);
        $loop->run();
        pcntl_waitpid($child, $status);
        self::assertSame(SIGUSR1, $received);
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
    }

    /**
     * @return array{resource, resource}
     */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        $this->deferCleanup(static function () use ($pair): void {
            array_map(static fn($stream) => is_resource($stream) && fclose($stream), $pair);
        });
        return $pair;
    }
}
