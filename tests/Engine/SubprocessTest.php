<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Process\Subprocess;
use Crab\Engine\Tests\Support\TestCase;

final class SubprocessTest extends TestCase
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

    public function testItReturnsBothStreamsAndTheExitCode(): void
    {
        $result = Subprocess::run(['/bin/sh', '-c', 'echo out; echo err >&2; exit 3'], loop: $this->loop);

        self::assertSame(3, $result->exitCode);
        self::assertFalse($result->ok());
        self::assertSame("out\n", $result->stdout);
        self::assertSame("err\n", $result->stderr);
        self::assertStringContainsString('out', $result->output);
        self::assertStringContainsString('err', $result->output);
        self::assertSame([], $this->loop->getIdentifiers(), 'A watcher outlived the command.');
    }

    public function testItFeedsInputLargerThanAPipeAndRunsWhereAndWithWhatItIsTold(): void
    {
        $input = str_repeat("line\n", 100_000);
        $result = Subprocess::run(['wc', '-l'], input: $input, loop: $this->loop);
        self::assertTrue($result->ok());
        self::assertSame('100000', trim($result->stdout));

        $directory = sys_get_temp_dir();
        $result = Subprocess::run(
            ['/bin/sh', '-c', 'pwd; echo "$CRAB_VALUE"'],
            directory: $directory,
            environment: ['CRAB_VALUE' => 'given', 'PATH' => '/usr/bin:/bin'],
            loop: $this->loop,
        );
        self::assertSame(realpath($directory) . "\ngiven\n", $result->stdout);
    }

    public function testItKillsACommandPastItsTimeout(): void
    {
        $started = microtime(true);
        $result = Subprocess::run(['sleep', '5'], timeout: 0.1, loop: $this->loop);

        self::assertTrue($result->timedOut);
        self::assertNull($result->exitCode);
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testItStopsACommandAtItsOutputLimit(): void
    {
        $result = Subprocess::run(['yes'], maxBytes: 1000, loop: $this->loop);

        self::assertTrue($result->truncated);
        self::assertNull($result->exitCode);
        self::assertSame(1000, strlen($result->output));
    }

    public function testCommandsRunConcurrentlyWithOtherTasks(): void
    {
        $loop = $this->loop;
        $started = microtime(true);
        $tasks = [];
        foreach ([1, 2, 3] as $_) {
            $tasks[] = Task::spawn($loop, static fn() => Subprocess::run(['sleep', '0.3'], loop: $loop));
        }
        foreach ($tasks as $task) {
            self::assertTrue($task->await()->ok());
        }

        self::assertLessThan(0.8, microtime(true) - $started, 'Commands ran one after another.');
    }

    public function testCancellationKillsTheCommandAndThrows(): void
    {
        $loop = $this->loop;
        $cancellation = new Cancellation();
        $loop->delay(0.1, static fn() => $cancellation->cancel());
        $started = microtime(true);

        try {
            Subprocess::run(['sleep', '5'], cancellation: $cancellation, loop: $loop);
            self::fail('A cancelled command returned.');
        } catch (CancelledException) {
        }
        self::assertLessThan(2.0, microtime(true) - $started);
    }

    public function testNothingTheCommandStartedOutlivesIt(): void
    {
        if (!function_exists('posix_kill') || !is_executable('/usr/bin/setsid') && !is_executable('/bin/setsid')) {
            self::markTestSkipped('Process groups need setsid and the posix extension.');
        }
        $marker = 'sleep 30.' . random_int(100_000, 999_999);
        $started = microtime(true);
        $result = Subprocess::run(
            ['/bin/sh', '-c', "{$marker} & echo started"],
            loop: $this->loop,
        );

        self::assertSame("started\n", $result->stdout);
        self::assertLessThan(2.0, microtime(true) - $started, 'A background child held the command open.');
        Async::sleep($this->loop, 0.1);
        self::assertSame('', trim((string) shell_exec('pgrep -f ' . escapeshellarg($marker))), 'The background child survived.');
    }

    public function testItRefusesWhatItCannotRun(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Subprocess::run([], loop: $this->loop);
    }
}
