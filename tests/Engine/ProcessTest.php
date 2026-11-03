<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Process\Process;
use Crab\Engine\Tests\Support\TestCase;

final class ProcessTest extends TestCase
{
    private StreamSelectEventLoop $loop;

    protected function setUp(): void
    {
        $this->loop = new StreamSelectEventLoop();
        $loop = $this->loop;
        $this->deferCleanup(static function () use ($loop): void {
            foreach ($loop->getIdentifiers() as $id) {
                $loop->cancel($id);
            }
        });
    }

    public function testItIsTalkedToWhileItRuns(): void
    {
        $process = Process::start(['cat'], loop: $this->loop);
        $output = '';
        $process->onOutput(static function (string $chunk) use (&$output): void {
            $output .= $chunk;
        });
        $process->write("one\n");
        $this->waitFor(static function () use (&$output): bool {
            return $output === "one\n";
        });
        $process->write("two\n");
        $this->waitFor(static function () use (&$output): bool {
            return $output === "one\ntwo\n";
        });
        self::assertSame("one\ntwo\n", $output, 'Each line comes back while it keeps running.');
        self::assertTrue($process->running());

        $code = false;
        $process->onExit(static function (?int $exit) use (&$code): void {
            $code = $exit;
        });
        $process->stop();
        $this->waitFor(static function () use (&$code): bool {
            return $code !== false;
        });
        self::assertSame(0, $code, 'Closing its input ends cat cleanly.');
        self::assertFalse($process->running());
        self::assertSame([], $this->loop->getIdentifiers(), 'A watcher outlived the process.');
    }

    public function testOutputBeforeAnyoneListensIsKeptAndTheExitCodeIsGiven(): void
    {
        $process = Process::start(['/bin/sh', '-c', 'echo early; echo oops >&2; exit 3'], loop: $this->loop);
        $code = false;
        $process->onExit(static function (?int $exit) use (&$code): void {
            $code = $exit;
        });
        $this->waitFor(static function () use (&$code): bool {
            return $code !== false;
        });
        $output = $error = '';
        $process->onOutput(static function (string $chunk) use (&$output): void {
            $output .= $chunk;
        });
        $process->onError(static function (string $chunk) use (&$error): void {
            $error .= $chunk;
        });
        self::assertSame(3, $code);
        self::assertSame("early\n", $output);
        self::assertSame("oops\n", $error);
        $late = false;
        $process->onExit(static function (?int $exit) use (&$late): void {
            $late = $exit;
        });
        self::assertSame(3, $late, 'Asked after it ended, the answer comes at once.');
    }

    public function testAStopItIgnoresBecomesAKillAndTakesWhatItStarted(): void
    {
        $marker = sys_get_temp_dir() . '/crab-process-' . bin2hex(random_bytes(4));
        // Ignores its input closing, and starts a child that would outlive it.
        $process = Process::start([
            '/bin/sh',
            '-c',
            "trap '' TERM; (sleep 30; touch {$marker}) & sleep 30",
        ], loop: $this->loop);
        $code = false;
        $process->onExit(static function (?int $exit) use (&$code): void {
            $code = $exit;
        });
        $process->stop(grace: 0.1);
        $this->waitFor(static function () use (&$code): bool {
            return $code !== false;
        });
        self::assertFalse($process->running(), 'Past its grace, it is killed.');
        self::assertNull($code, 'A signal ended it.');
        $survivor = trim((string) shell_exec('pgrep -g ' . $process->pid() . ' 2>/dev/null'));
        self::assertSame('', $survivor, 'What it started went with it.');
        @unlink($marker);
    }

    private function waitFor(\Closure $done, float $seconds = 5.0): void
    {
        $start = microtime(true);
        while (!$done() && (microtime(true) - $start) < $seconds) {
            $suspension = $this->loop->getSuspension();
            $this->loop->delay(0.01, static fn() => $suspension->resume());
            $suspension->suspend();
        }
    }
}
