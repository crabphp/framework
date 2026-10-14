<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Async\Async;
use Crab\Engine\EventLoop;
use Crab\Engine\Http\Response;
use Crab\Engine\Network\Listener;
use Crab\Engine\Process\Keeper;
use Crab\Engine\Process\PidFile;
use Crab\Engine\Process\Supervisor;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;
use Crab\Engine\Server\HttpServer;
use Crab\Engine\Tests\Support\TestCase;
use Crab\Engine\Crab;

/**
 * Drain deadlines belong to workers. Their parent allows time for those
 * deadlines to close connections and run the worker's stop callbacks.
 */
final class DrainTest extends TestCase
{
    public function testGroupsWithoutAnOverrideKeepTheirExistingTimeouts(): void
    {
        $runtime = new Runtime();
        $group = $runtime->group();
        self::assertSame(5.0, $runtime->shutdownTimeout);
        self::assertNull($group->shutdownTimeout);
        self::assertSame(5.0, $this->supervisorGrace($group, $runtime->shutdownTimeout));
    }

    public function testTheSupervisorWaitsLongerThanEachWorkersDrainDeadline(): void
    {
        foreach ([1.0, 30.0, 120.0] as $drain) {
            $group = new Crab('http', shutdownTimeout: $drain);
            self::assertGreaterThan($drain, $this->supervisorGrace($group, $drain));
        }
        self::assertSame(2.0, $this->supervisorGrace(new Crab('http', shutdownTimeout: 1.0), 30.0));
    }

    public function testAGroupRejectsInvalidDrainDeadlines(): void
    {
        foreach ([0.0, -1.0, INF, NAN] as $drain) {
            try {
                new Crab('http', shutdownTimeout: $drain);
                self::fail('An invalid worker drain deadline was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('shutdown timeout', $error->getMessage());
            }
        }
    }

    public function testAKeeperRejectsInvalidGenerationStopAllowancesBeforeWritingARequest(): void
    {
        foreach ([0.0, -1.0, INF, NAN] as $timeout) {
            try {
                Keeper::request(new PidFile('/unused'), 0, '', [], '', 1.0, $timeout);
                self::fail('An invalid generation stop allowance was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('generation stop timeout', $error->getMessage());
            }
        }
    }

    public function testAReloadCarriesTheNewAllowanceAndRetiringGenerationsKeepTheirOwn(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('A keeper needs pcntl and posix.');
        }
        $directory = realpath(sys_get_temp_dir()) . '/crab-drain-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $probe = Listener::bind('tcp://127.0.0.1:0');
        $address = 'tcp://' . stream_socket_get_name($probe, false);
        fclose($probe);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $fixture = __DIR__ . '/Fixtures/drain.php';
        $process = proc_open([PHP_BINARY, $fixture, 'keep', $autoload, $directory, $address],
            [1 => ['pipe', 'w'], 2 => ['file', $directory . '/errors.log', 'w']], $pipes, $directory);
        self::assertIsResource($process);
        $this->deferCleanup(static function () use ($process, $pipes, $directory): void {
            if (is_resource($process)) {
                proc_terminate($process, SIGTERM);
                proc_close($process);
            }
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        });
        stream_set_timeout($pipes[1], 3);
        self::assertSame("ready\n", fgets($pipes[1]));
        $pidFile = new PidFile($directory . '/service.pid');
        $pid = (int) $pidFile->pid();
        foreach (['second' => 0.08, 'third' => 1.0] as $name => $allowance) {
            $answer = Keeper::request($pidFile, $pid, $address, [$fixture, 'serve', $autoload, $directory, $name], $directory, 3.0, $allowance);
            self::assertTrue($answer['ok'], json_encode($answer));
        }
        // Stop while both prior generations are still retiring. The first
        // keeps 1.0 seconds, the second is cut at 0.08, the third gets 1.0.
        proc_terminate($process, SIGTERM);
        $deadline = microtime(true) + 3.0;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        self::assertFalse($status['running'], (string) file_get_contents($directory . '/errors.log'));
        self::assertSame(0, proc_close($process));
        self::assertFileExists($directory . '/first.finished');
        self::assertFileDoesNotExist($directory . '/second.finished');
        self::assertFileExists($directory . '/third.finished');
    }

    public function testAGroupCutsAllRemainingConnectionsAtItsOwnDeadline(): void
    {
        [$stats, $responses, $elapsed] = $this->drainRequests(0.04, 0.5, 2);
        self::assertSame(2, $stats['cutConnections']);
        self::assertSame(['', ''], $responses);
        self::assertGreaterThanOrEqual(0.04, $elapsed);
        self::assertLessThan(2.0, $elapsed, 'The group did not finish after cutting its requests.');
    }

    public function testRestartingAndReloadingWithoutAnOverrideKeepTheCurrentGenerationAllowance(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('A keeper needs pcntl and posix.');
        }
        $directory = realpath(sys_get_temp_dir()) . '/crab-drain-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $probe = Listener::bind('tcp://127.0.0.1:0');
        $address = 'tcp://' . stream_socket_get_name($probe, false);
        fclose($probe);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $fixture = __DIR__ . '/Fixtures/drain.php';
        $process = proc_open([PHP_BINARY, $fixture, 'keep', $autoload, $directory, $address, '0.08'],
            [1 => ['pipe', 'w'], 2 => ['file', $directory . '/errors.log', 'w']], $pipes, $directory);
        self::assertIsResource($process);
        $this->deferCleanup(static function () use ($process, $pipes, $directory): void {
            if (is_resource($process)) {
                proc_terminate($process, SIGTERM);
                proc_close($process);
            }
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        });
        stream_set_timeout($pipes[1], 3);
        self::assertSame("ready\n", fgets($pipes[1]));
        $pidFile = new PidFile($directory . '/service.pid');
        $keeper = (int) $pidFile->pid();
        $answer = Keeper::request($pidFile, $keeper, $address, [$fixture, 'serve', $autoload, $directory, 'second'], $directory, 3.0, 0.8);
        self::assertTrue($answer['ok'], json_encode($answer));
        $second = (int) $answer['pid'];
        self::assertTrue(posix_kill($second, SIGKILL));
        $deadline = microtime(true) + 5.0;
        do {
            $log = (string) file_get_contents($directory . '/service.log');
            if (str_contains($log, 'Serving again, generation pid ')) {
                break;
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);
        self::assertStringContainsString('Serving again, generation pid ', $log);
        $answer = Keeper::request($pidFile, $keeper, $address, [$fixture, 'serve', $autoload, $directory, 'third'], $directory, 3.0);
        self::assertTrue($answer['ok'], json_encode($answer));
        proc_terminate($process, SIGTERM);
        self::assertSame(0, proc_close($process));
        self::assertFileExists($directory . '/second.finished', 'Automatic restart lost the current 0.8-second allowance.');
        self::assertFileExists($directory . '/third.finished', 'A reload without an override lost the current allowance.');
    }

    public function testARequestFinishingBeforeTheDeadlineIsAnsweredAndIsNotCountedAsCut(): void
    {
        [$stats, $responses] = $this->drainRequests(0.2, 0.02, 1);
        self::assertSame(0, $stats['cutConnections']);
        self::assertStringContainsString('200 OK', $responses[0]);
        self::assertStringEndsWith('complete', $responses[0]);
    }

    public function testRetiringDeadlinesAndStopContinueWhileAReplacementNeverBecomesReady(): void
    {
        $this->checkInterruptedStartup('hang');
    }

    public function testRetiringDeadlinesAndStopContinueWhileAFailedReplacementIsBeingCleanedUp(): void
    {
        $this->checkInterruptedStartup('fail');
    }

    public function testGroupForceIncludesAGenerationBeforeTheKeeperPublishesItsFork(): void
    {
        $this->checkForceDuringFork('generation');
    }

    public function testGroupForceIncludesAWorkerBeforeItsSupervisorRegistersItsFork(): void
    {
        $this->checkForceDuringFork('worker');
    }

    /**
     * Fixture-only wrappers pause after real forks, at intervals for which
     * the framework deliberately has no production hook or pid snapshot.
     */
    private function checkForceDuringFork(string $kind): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('A keeper needs pcntl and posix.');
        }
        $directory = realpath(sys_get_temp_dir()) . '/crab-drain-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $socket = Listener::bind('tcp://127.0.0.1:0');
        $address = 'tcp://' . stream_socket_get_name($socket, false);
        self::assertGreaterThanOrEqual(8200, (int) substr(strrchr($address, ':'), 1));
        fclose($socket);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $fixture = __DIR__ . '/Fixtures/drain.php';
        $process = proc_open([PHP_BINARY, $fixture, 'keep-fork', $autoload, $directory, $address],
            [1 => ['pipe', 'w'], 2 => ['file', $directory . '/errors.log', 'w']], $pipes, $directory);
        self::assertIsResource($process);
        $pid = proc_get_status($process)['pid'];
        $forcer = null;
        $forcePipes = [];
        $this->deferCleanup(static function () use ($process, $pipes, $pid, $directory, &$forcer, &$forcePipes): void {
            if (posix_getpgid($pid) === $pid) {
                posix_kill(-$pid, SIGKILL);
            }
            if (is_resource($forcer)) {
                proc_terminate($forcer, SIGKILL);
                proc_close($forcer);
            }
            if (is_resource($process)) {
                proc_terminate($process, SIGKILL);
                proc_close($process);
            }
            foreach ([$pipes[1], ...array_values($forcePipes)] as $stream) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        });
        stream_set_timeout($pipes[1], 3);
        self::assertSame("ready\n", fgets($pipes[1]));
        self::assertSame($pid, posix_getpgid($pid));
        $state = json_decode(file_get_contents($directory . '/service.pid.state'), true);
        $generation = $state['generations'][0]['pid'];
        if ($kind === 'generation') {
            file_put_contents($directory . '/service.pid.reload', json_encode([
                'id' => 'fork-barrier', 'address' => $address, 'directory' => $directory,
                'command' => [$fixture, 'supervise-new', $autoload, $directory, $address], 'stopTimeout' => 35.0,
            ]));
            posix_kill($pid, SIGUSR2);
        } else {
            file_put_contents($directory . '/hold-worker-fork', 'yes');
            posix_kill($generation, SIGUSR1);
        }
        $barrier = $directory . '/' . $kind . '-forked';
        $deadline = microtime(true) + 3.0;
        do {
            $workers = array_map(static fn (string $line): array => json_decode($line, true),
                file($directory . '/observed-workers', FILE_IGNORE_NEW_LINES));
            if (is_file($barrier) && count($workers) === 2) {
                break;
            }
            usleep(5_000);
        } while (microtime(true) < $deadline);
        self::assertFileExists($barrier);
        self::assertCount(2, $workers);
        $new = (int) file_get_contents($barrier);
        self::assertTrue(posix_kill($new, 0));
        if ($kind === 'generation') {
            $unpublished = json_decode(file_get_contents($directory . '/service.pid.state'), true);
            self::assertNotContains($new, array_column($unpublished['generations'], 'pid'));
        }
        $before = microtime(true);
        $forcer = proc_open([PHP_BINARY, $fixture, 'force', $autoload, $directory, (string) $pid],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $forcePipes, $directory);
        self::assertIsResource($forcer);
        $deadline = microtime(true) + 4.0;
        do {
            // Reap this test's keeper while the independent force command waits.
            proc_get_status($process);
            $status = proc_get_status($forcer);
            if (!$status['running']) {
                break;
            }
            usleep(5_000);
        } while (microtime(true) < $deadline);
        self::assertFalse($status['running']);
        $error = stream_get_contents($forcePipes[2]);
        $output = stream_get_contents($forcePipes[1]);
        self::assertSame(0, proc_close($forcer), $error);
        self::assertSame("forced\n", $output);
        self::assertLessThan(4.0, microtime(true) - $before);
        foreach (array_unique([$pid, $generation, $new, ...array_column($workers, 'pid'), ...array_column($workers, 'generation')]) as $owned) {
            self::assertFalse(posix_kill($owned, 0), "Owned process {$owned} survived group force.");
        }
        self::assertFileDoesNotExist($directory . '/service.pid.state');
    }

    public function testTheRecordedAllowanceUsesRemainingRetireeTimeAndRejectsAnotherKeeper(): void
    {
        $directory = realpath(sys_get_temp_dir()) . '/crab-drain-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $pidFile = new PidFile($directory . '/service.pid');
        $keeper = new Keeper($pidFile, $directory . '/service.log');
        $this->deferCleanup(static function () use ($directory): void {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        });
        self::assertNull($keeper->allowance(getmypid()), 'An old pid file leaves the fallback to its caller.');
        file_put_contents($pidFile->path . '.state', json_encode([
            'pid' => getmypid(),
            'generations' => [
                ['pid' => 100, 'stopTimeout' => 1.0, 'deadline' => null],
                ['pid' => 101, 'stopTimeout' => 30.0, 'deadline' => microtime(true) + 10.0],
            ],
        ]));
        self::assertGreaterThan(10.0, $keeper->allowance(getmypid()));
        self::assertLessThanOrEqual(11.0, $keeper->allowance(getmypid()));
        self::assertNull($keeper->allowance(getmypid() + 1));
    }

    /**
     * The retiree has 0.2 seconds, the current generation 0.3, and the
     * unready replacement 0.4. Files are barriers, not startup sleeps.
     */
    private function checkInterruptedStartup(string $mode): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('A keeper needs pcntl and posix.');
        }
        $directory = realpath(sys_get_temp_dir()) . '/crab-drain-' . bin2hex(random_bytes(6));
        mkdir($directory);
        $probe = Listener::bind('tcp://127.0.0.1:0');
        $address = 'tcp://' . stream_socket_get_name($probe, false);
        fclose($probe);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $fixture = __DIR__ . '/Fixtures/drain.php';
        $process = proc_open([PHP_BINARY, $fixture, 'keep', $autoload, $directory, $address, '0.2'],
            [1 => ['pipe', 'w'], 2 => ['file', $directory . '/errors.log', 'w']], $pipes, $directory);
        self::assertIsResource($process);
        $this->deferCleanup(static function () use ($process, $pipes, $directory): void {
            if (is_resource($process)) {
                proc_terminate($process, SIGTERM);
                proc_close($process);
            }
            if (is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        });
        stream_set_timeout($pipes[1], 3);
        self::assertSame("ready\n", fgets($pipes[1]));
        $pidFile = new PidFile($directory . '/service.pid');
        $keeper = (int) $pidFile->pid();
        self::assertSame($keeper . "\n", file_get_contents($pidFile->path), 'The pid file stays readable as a number.');
        $answer = Keeper::request($pidFile, $keeper, $address, [$fixture, 'serve', $autoload, $directory, 'second'], $directory, 3.0, 0.3);
        self::assertTrue($answer['ok'], json_encode($answer));
        $first = $answer['previous'];
        $second = $answer['pid'];
        file_put_contents($pidFile->path . '.reload', json_encode([
            'id' => 'startup-barrier', 'address' => $address, 'directory' => $directory,
            'command' => [$fixture, $mode, $autoload, $directory, 'third'], 'stopTimeout' => 0.4,
        ]));
        posix_kill($keeper, SIGUSR2);
        $deadline = microtime(true) + 2.0;
        while (!is_file($directory . '/third.started') && microtime(true) < $deadline) {
            usleep(5_000);
        }
        self::assertFileExists($directory . '/third.started');
        $third = (int) file_get_contents($directory . '/third.started');
        $retiredAt = (float) file_get_contents($directory . '/first.stopping');
        while (posix_kill($first, 0) && microtime(true) < $retiredAt + 1.2) {
            usleep(5_000);
        }
        self::assertFalse(posix_kill($first, 0), 'Replacement startup or cleanup hid the retiree deadline.');
        self::assertLessThan(1.2, microtime(true) - $retiredAt);
        self::assertTrue(posix_kill($third, 0), 'Stop must interrupt a replacement still being started or cleaned up.');
        $before = microtime(true);
        proc_terminate($process, SIGTERM);
        $deadline = $before + 1.5;
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(5_000);
        } while (microtime(true) < $deadline);
        self::assertFalse($status['running'], (string) file_get_contents($directory . '/errors.log'));
        self::assertSame(0, proc_close($process));
        self::assertFileExists($directory . '/second.stopping');
        $stoppedAt = (float) file_get_contents($directory . '/second.stopping');
        self::assertLessThan(0.8, $stoppedAt - $before, 'The current generation was stopped after serial replacement cleanup.');
        self::assertLessThan(1.5, microtime(true) - $before);
        self::assertFalse(posix_kill($second, 0));
        self::assertFalse(posix_kill($third, 0));
        self::assertFileDoesNotExist($pidFile->path . '.state');
    }

    /**
     * Reads the supervisor's computed allowance without a wall-clock wait
     * or a fork: the same method sets the deadline when it retires a child.
     */
    private function supervisorGrace(Crab $group, float $timeout): float
    {
        $supervisor = new Supervisor([$group->name => $group], [], static fn () => EventLoop::current(), $timeout);
        $method = new \ReflectionMethod($supervisor, 'grace');
        return $method->invoke($supervisor, $group->name . ':0');
    }

    /**
     * Opens real HTTP connections on a free port and stops while every
     * handler is suspended, so the group's deadline decides the outcome.
     *
     * @return array{array<string, mixed>, list<string>, float}
     */
    private function drainRequests(float $drain, float $requestSeconds, int $count): array
    {
        $socket = Listener::bind('tcp://127.0.0.1:0');
        $address = 'tcp://' . stream_socket_get_name($socket, false);
        $peers = [];
        for ($index = 0; $index < $count; ++$index) {
            $peer = stream_socket_client($address, $code, $message, 1);
            self::assertIsResource($peer);
            $this->deferCleanup(static function () use ($peer): void {
                if (is_resource($peer)) {
                    fclose($peer);
                }
            });
            fwrite($peer, "GET / HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
            $peers[] = $peer;
        }
        $runtime = new Runtime(shutdownTimeout: 1.0);
        $worker = null;
        $started = 0;
        $stats = [];
        $runtime->group('http', shutdownTimeout: $drain)
            ->listen($address, new HttpServer(static function () use (&$worker, &$started, $count, $requestSeconds): Response {
                if (++$started === $count) {
                    $worker->loop->delay(0.005, static function () use ($worker): void {
                        $worker->stop();
                        $worker->stop();
                    });
                }
                Async::sleep($worker->loop, $requestSeconds);
                return Response::text('complete');
            }), socket: $socket)
            ->onStart(static function (Worker $value) use (&$worker): void {
                $worker = $value;
            })
            ->onStop(static function (Worker $worker) use (&$stats): void {
                $stats = $worker->stats();
            });
        $before = hrtime(true);
        $runtime->run(singleProcess: true);
        $elapsed = (hrtime(true) - $before) / 1e9;
        $responses = [];
        foreach ($peers as $peer) {
            $responses[] = (string) stream_get_contents($peer);
        }
        self::assertSame($count, $started);
        self::assertSame([], $this->reports);
        return [$stats, $responses, $elapsed];
    }
}
