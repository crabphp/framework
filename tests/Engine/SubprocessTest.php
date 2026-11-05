<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Process\ProcessGroup;
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
        if (ProcessGroup::wrap(['true']) === null) {
            self::markTestSkipped('Process groups need setsid or perl, and the posix extension.');
        }
        $marker = 'sleep 30.' . random_int(100_000, 999_999);
        $started = microtime(true);
        $result = Subprocess::run(['/bin/sh', '-c', "{$marker} & echo started"], loop: $this->loop);

        self::assertSame("started\n", $result->stdout);
        self::assertLessThan(2.0, microtime(true) - $started, 'A background child held the command open.');
        Async::sleep($this->loop, 0.1);
        // A killed child may linger as a zombie until whatever adopted it reaps it; only a live one has survived.
        $alive = [];
        foreach (preg_split(
            '/\s+/',
            trim((string) shell_exec('pgrep -f ' . escapeshellarg($marker))),
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) as $pid) {
            $state = trim((string) shell_exec('ps -o stat= -p ' . (int) $pid));
            if ($state !== '' && !str_starts_with($state, 'Z')) {
                $alive[] = "{$pid} ({$state})";
            }
        }
        self::assertSame([], $alive, 'The background child survived.');
    }

    public function testPerlLeadsAGroupWhereThereIsNoSetsid(): void
    {
        if (!is_executable('/usr/bin/perl')) {
            self::markTestSkipped('No perl.');
        }
        // Started as proc_open starts it, not under setsid, as on macOS.
        $process = proc_open(
            ['/usr/bin/perl', '-e', ProcessGroup::PERL, '--', '/bin/sh', '-c', 'echo $$ $(ps -o pgid= -p $$); exit 3'],
            [1 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $pid = proc_get_status($process)['pid'];
        $out = preg_split('/\s+/', trim((string) stream_get_contents($pipes[1])));
        fclose($pipes[1]);
        self::assertSame(3, proc_close($process), 'The exit code is the command\'s.');
        self::assertSame([(string) $pid, (string) $pid], $out, 'The command keeps the process id and leads its own group.');

        $missing = proc_open(['/usr/bin/perl', '-e', ProcessGroup::PERL, '--', '/no/such/program'], [2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($missing);
        self::assertStringContainsString('/no/such/program', (string) stream_get_contents($pipes[2]));
        fclose($pipes[2]);
        self::assertNotSame(0, proc_close($missing));
    }

    public function testItRefusesWhatItCannotRun(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Subprocess::run([], loop: $this->loop);
    }
}
