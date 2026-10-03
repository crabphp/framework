<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\Listener;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;
use Crab\Engine\Server\HttpServer;
use Crab\Engine\Tests\Support\TestCase;
use Crab\Engine\WorkerGroup;
use Generator;
use OverflowException;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use RuntimeException;
use stdClass;
use Throwable;

final class RuntimeTest extends TestCase
{
    public function testBoundedBootstrapTasksRunAndUnwindDuringDrain(): void
    {
        $cleaned = $ran = $bounded = false;
        $runtime = new Runtime(shutdownTimeout: 0.1);
        $runtime->group(maxTasks: 1)->onStart(static function (Worker $worker) use (&$cleaned, &$ran, &$bounded): void {
            $worker->spawn(static function (Cancellation $token) use ($worker, &$cleaned, &$ran): void {
                try {
                    $ran = true;
                    Async::sleep($worker->loop, 60, $token);
                } finally {
                    $cleaned = true;
                }
            });
            try {
                $worker->spawn(static function (): void {});
            } catch (OverflowException) {
                $bounded = true;
            }
            $worker->loop->delay(0.005, $worker->stop(...));
        });
        $runtime->run(singleProcess: true);

        self::assertTrue($bounded, 'The worker must reject tasks beyond its limit.');
        self::assertTrue($ran, 'The admitted task must run.');
        self::assertTrue($cleaned, 'Shutdown must unwind the task finally block.');
    }

    public function testDetachedTaskFailureIsReported(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'crab-task-error-');
        self::assertNotFalse($log);
        $this->deferCleanup(static function () use ($log): void { if (is_file($log)) { unlink($log); } });
        $previousLog = ini_set('error_log', $log);
        try {
            $runtime = new Runtime(shutdownTimeout: 0.1);
            $runtime->group()->onStart(static function (Worker $worker): void {
                $worker->spawn(static function (): void {
                    throw new RuntimeException('deliberate detached task failure');
                });
                $worker->loop->delay(0.005, $worker->stop(...));
            });
            $runtime->run(singleProcess: true);
            self::assertStringContainsString('deliberate detached task failure', file_get_contents($log));
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
        }
    }

    public function testActiveOutboundConnectionReservesAdmissionCapacity(): void
    {
        $this->assertOutboundAdmission('complete');
    }

    public function testCancellingPendingOutboundConnectionReleasesAdmissionCapacity(): void
    {
        $this->assertOutboundAdmission('cancel');
    }

    public function testBootstrapFailureRollsBackListenersPendingConnectionsAndTasks(): void
    {
        $loop = $this->driver();
        $bound = $this->ownStream(Listener::bind('tcp://127.0.0.1:0'));
        $outbound = $this->ownStream(Listener::bind('tcp://127.0.0.1:0'));
        $outboundAddress = 'tcp://' . stream_socket_get_name($outbound, false);
        $cancelled = 0;
        $ran = false;
        $group = new WorkerGroup('bootstrap-failure');
        $group->listen('tcp://127.0.0.1:0', static function (): void {});
        $group->onStart(function (Worker $worker) use ($outboundAddress, &$cancelled, &$ran): void {
            $worker->connect($outboundAddress, $this->guardCallback(static function (?Connection $connection, ?Throwable $error) use (&$cancelled): void {
                self::assertNull($connection);
                self::assertNotNull($error, 'Bootstrap rollback must cancel pending connections.');
                $cancelled++;
            }));
            $worker->spawn(static function () use (&$ran): void { $ran = true; });
            throw new RuntimeException('Expected bootstrap rollback');
        });
        $worker = new Worker($loop, $group, 0, ['tcp' => [$bound], 'udp' => []], 0.1);
        $failure = null;
        try {
            $worker->run();
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertSame('Expected bootstrap rollback', $failure->getMessage());
        self::assertSame(1, $cancelled);
        self::assertFalse($ran, 'A task queued by failed bootstrap must not run.');
        self::assertFalse(is_resource($bound), 'Bootstrap failure must release the listener.');
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testControlEofRemovesReadInterestWhileActiveTasksFinish(): void
    {
        $loop = $this->driver();
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        $this->ownStream($pair[0]);
        $this->ownStream($pair[1]);
        $watcherRemoved = false;
        $group = new WorkerGroup('control-eof');
        $group->onStart(static function (Worker $worker) use ($loop, $pair, &$watcherRemoved): void {
            $worker->spawn(static function () use ($loop): void { Async::sleep($loop, 0.02); });
            $loop->defer(static function () use ($pair): void { fclose($pair[0]); });
            $loop->delay(0.005, static function () use ($loop, &$watcherRemoved): void {
                $watcherRemoved = true;
                foreach ($loop->getIdentifiers() as $id) {
                    if ($loop->getType($id) === CallbackType::Readable) {
                        $watcherRemoved = false;
                    }
                }
            });
        });
        $worker = new Worker($loop, $group, 0, ['tcp' => [], 'udp' => []], 0.1, $pair[1]);
        $worker->run();
        self::assertTrue($watcherRemoved, 'EOF must stop read interest before the task finishes.');
        self::assertSame([], $loop->getIdentifiers());
    }

    public function testHttpHandlerAsyncCleanupRemainsOwnedDuringDrain(): void
    {
        [$started, $done, $elapsed, $connections] = $this->httpDrainCleanup('handler', 0.01, 0.2);
        self::assertTrue($started);
        self::assertTrue($done, 'HTTP handler finally block escaped worker ownership.');
        self::assertSame(0, $connections);
        self::assertLessThan(0.2, $elapsed);
    }

    public function testHttpStreamProducerAsyncCleanupRemainsOwnedDuringDrain(): void
    {
        [$started, $done, $elapsed, $connections] = $this->httpDrainCleanup('producer', 0.01, 0.2);
        self::assertTrue($started);
        self::assertTrue($done, 'HTTP producer finally block escaped worker ownership.');
        self::assertSame(0, $connections);
        self::assertLessThan(0.2, $elapsed);
    }

    public function testWorkerDeadlineBoundsUncooperativeHttpCleanup(): void
    {
        [$started, $done, $elapsed] = $this->httpDrainCleanup('handler', 60, 0.04);
        self::assertTrue($started);
        self::assertFalse($done);
        self::assertLessThan(0.3, $elapsed, 'Uncooperative cleanup bypassed the worker deadline.');
    }

    public function testSingleProcessWorkerServesDataAndStopsOnItsTimer(): void
    {
        $fixture = $this->startFixture('single');
        try {
            $this->ready($fixture, 1);
            $socket = $this->connectTo($fixture->port);
            fwrite($socket, "single\n");
            self::assertStringEndsWith(":single\n", $this->readLine($socket));
        } finally {
            $result = $this->finishFixture($fixture, signal: false);
        }
        $this->assertFixtureExit($result);
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testSupervisorRestartsReloadsAndReapsWorkers(): void
    {
        $fixture = $this->startFixture('supervised');
        try {
            $initial = $this->ready($fixture, 2);
            self::assertCount(2, array_unique($initial), 'Workers must be distinct processes.');
            $socket = $this->connectTo($fixture->port);
            fwrite($socket, "parallel\n");
            self::assertStringEndsWith(":parallel\n", $this->readLine($socket));
            fclose($socket);
            self::assertTrue(posix_kill($initial[0], SIGKILL));
            $restarted = $this->ready($fixture, 1)[0];
            self::assertNotContains($restarted, $initial);
            $parent = proc_get_status($fixture->process)['pid'];
            self::assertTrue(posix_kill($parent, SIGUSR1));
            $reloaded = $this->ready($fixture, 2);
            self::assertSame([], array_intersect($reloaded, [$initial[1], $restarted]));
            $socket = $this->connectTo($fixture->port);
            fwrite($socket, "reload\n");
            self::assertStringEndsWith(":reload\n", $this->readLine($socket));
            fclose($socket);
        } finally {
            $result = $this->finishFixture($fixture);
        }
        $this->assertFixtureExit($result);
        foreach (array_merge($initial, [$restarted], $reloaded) as $pid) {
            self::assertFalse(posix_kill($pid, 0), "Child $pid must be reaped.");
        }
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testSupervisorTerminatesABlockedWorkerByItsDeadline(): void
    {
        $fixture = $this->startFixture('supervised');
        try {
            $pids = $this->ready($fixture, 2);
            $socket = $this->connectTo($fixture->port);
            fwrite($socket, "block\n");
            self::assertStringEndsWith(":block\n", $this->readLine($socket));
            $start = microtime(true);
        } finally {
            $result = $this->finishFixture($fixture);
        }
        $this->assertFixtureExit($result);
        self::assertLessThan(2.0, microtime(true) - $start, 'The parent must enforce shutdown of a blocked callback.');
        foreach ($pids as $pid) {
            self::assertFalse(posix_kill($pid, 0), 'Blocked workers must be reaped.');
        }
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testSignalDuringStartupDrainsAllSpawnedWorkers(): void
    {
        $fixture = $this->startFixture('startup-stop');
        try {
            $result = $this->finishFixture($fixture, signal: false);
        } finally {
            $this->finishFixture($fixture);
        }
        $this->assertFixtureExit($result);
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testRepeatedStartupFailureExhaustsTheRestartBudget(): void
    {
        $fixture = $this->startFixture('fail');
        try {
            $result = $this->finishFixture($fixture, signal: false);
        } finally {
            $this->finishFixture($fixture);
        }
        $this->assertFixtureExit($result, expected: 255);
    }

    private function assertOutboundAdmission(string $mode): void
    {
        $loop = $this->driver();
        $bound = $this->ownStream(Listener::bind('tcp://127.0.0.1:0'));
        $incomingAddress = 'tcp://' . stream_socket_get_name($bound, false);
        $outbound = $this->ownStream(Listener::bind('tcp://127.0.0.1:0'));
        $outboundAddress = 'tcp://' . stream_socket_get_name($outbound, false);
        $accepted = $completions = 0;
        $released = false;
        $worker = null;
        $group = new WorkerGroup('admission', maxConnections: 1);
        $group->listen($incomingAddress, $this->guardCallback(static function (Connection $connection) use (&$accepted, &$released, &$worker): void {
            self::assertTrue($released, 'An inbound socket was accepted before outbound capacity was released.');
            $accepted++;
            $connection->close();
            $worker->stop();
        }));
        $group->onStart($this->guardCallback(function (Worker $worker) use ($loop, $mode, $outboundAddress, $incomingAddress, &$accepted, &$completions, &$released): void {
            $cancel = $worker->connect($outboundAddress, $this->guardCallback(function (?Connection $connection, ?Throwable $error) use ($loop, $mode, &$accepted, &$completions, &$released): void {
                $completions++;
                if ($mode === 'cancel') {
                    self::assertNull($connection);
                    self::assertNotNull($error);
                    $released = true;
                } else {
                    self::assertNotNull($connection);
                    self::assertNull($error);
                    $loop->delay(0.005, $this->guardCallback(static function () use ($connection, &$accepted, &$released): void {
                        self::assertSame(0, $accepted, 'Active outbound connections must reserve admission capacity.');
                        $released = true;
                        $connection->close();
                    }));
                }
            }));
            $incoming = stream_socket_client($incomingAddress, $code, $error, 1);
            self::assertIsResource($incoming, 'Could not queue an inbound client.');
            $this->ownStream($incoming);
            if ($mode === 'cancel') {
                $loop->defer(static fn () => $cancel());
            }
            $loop->delay(1, $this->guardCallback(static function (): void {
                self::fail('Admission did not resume after capacity release.');
            }));
        }));
        $worker = new Worker($loop, $group, 0, ['tcp' => [$bound], 'udp' => []], 0.1);
        $worker->run();
        self::assertSame(1, $accepted);
        self::assertSame(1, $completions);
        self::assertSame([], $loop->getIdentifiers());
    }

    /**
     * @return array{bool, bool, float, int}
     */
    private function httpDrainCleanup(string $stage, float $cleanupSeconds, float $shutdownTimeout): array
    {
        $loop = $this->driver();
        $bound = $this->ownStream(Listener::bind('tcp://127.0.0.1:0'));
        $address = 'tcp://' . stream_socket_get_name($bound, false);
        $peer = stream_socket_client($address, $code, $message, 1);
        self::assertIsResource($peer);
        $this->ownStream($peer);
        fwrite($peer, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $connection = null;
        $cleanupStarted = $cleanupDone = false;
        $http = new HttpServer(static function (Request $request) use ($loop, $stage, $cleanupSeconds, &$cleanupStarted, &$cleanupDone): string|Response {
            $operation = static function () use ($loop, $request, $cleanupSeconds, &$cleanupStarted, &$cleanupDone): void {
                try {
                    Async::sleep($loop, 60, $request->cancellation);
                } finally {
                    $cleanupStarted = true;
                    Async::sleep($loop, $cleanupSeconds);
                    $cleanupDone = true;
                }
            };
            if ($stage === 'handler') {
                $operation();
                return 'finished';
            }
            return new Response(body: (static function () use ($operation): Generator {
                yield 'started';
                $operation();
                yield 'finished';
            })());
        });
        $group = new WorkerGroup('http-drain');
        $group->listen($address, static function (Connection $value) use ($http, &$connection): void {
            $connection = $value;
            $http->attach($value);
        });
        $group->onStart(static function (Worker $worker) use (&$connection): void {
            $worker->loop->delay(0.005, $worker->stop(...));
            $worker->loop->delay(0.015, static function () use (&$connection): void { $connection->close(); });
        });
        $worker = new Worker($loop, $group, 0, ['tcp' => [$bound], 'udp' => []], $shutdownTimeout);
        $start = hrtime(true);
        $worker->run();
        self::assertInstanceOf(Connection::class, $connection);
        self::assertTrue($connection->isClosed());
        self::assertSame([], $loop->getIdentifiers());
        return [$cleanupStarted, $cleanupDone, (hrtime(true) - $start) / 1e9, $worker->stats()['connections']];
    }

    private function driver(): StreamSelectDriver
    {
        $driver = new StreamSelectDriver();
        $this->deferCleanup(static function () use ($driver): void {
            foreach ($driver->getIdentifiers() as $id) {
                $driver->cancel($id);
            }
        });
        return $driver;
    }

    /**
     * @param resource $stream
     * @return resource
     */
    private function ownStream(mixed $stream): mixed
    {
        $this->deferCleanup(static function () use ($stream): void {
            if (is_resource($stream)) {
                fclose($stream);
            }
        });
        return $stream;
    }

    private function startFixture(string $mode): stdClass
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $code, $error);
        self::assertIsResource($probe);
        $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $stderr = tempnam(sys_get_temp_dir(), 'crab-runtime-');
        self::assertNotFalse($stderr);
        $this->deferCleanup(static function () use ($stderr): void { if (is_file($stderr)) { unlink($stderr); } });
        $process = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/runtime.php', $mode, (string) $port],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']], $pipes);
        self::assertIsResource($process);
        $fixture = (object) ['process' => $process, 'stdout' => $pipes[1], 'port' => $port,
            'stderr' => $stderr, 'ready' => [], 'buffer' => '', 'result' => null];
        $this->deferCleanup(fn () => $this->finishFixture($fixture));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        return $fixture;
    }

    /**
     * @return list<int>
     */
    private function ready(stdClass $fixture, int $count, float $timeout = 3): array
    {
        $deadline = microtime(true) + $timeout;
        while (count($fixture->ready) < $count && microtime(true) < $deadline) {
            $this->collectReady($fixture);
            if (count($fixture->ready) < $count) {
                usleep(5000);
            }
        }
        self::assertGreaterThanOrEqual($count, count($fixture->ready), 'Worker readiness deadline.');
        return array_splice($fixture->ready, 0, $count);
    }

    private function collectReady(stdClass $fixture): void
    {
        $fixture->buffer .= stream_get_contents($fixture->stdout);
        while (($end = strpos($fixture->buffer, "\n")) !== false) {
            $line = substr($fixture->buffer, 0, $end);
            $fixture->buffer = substr($fixture->buffer, $end + 1);
            $value = json_decode($line, true);
            if (isset($value['pid']) && is_int($value['pid'])) {
                $fixture->ready[] = $value['pid'];
            }
        }
    }

    /**
     * @return resource
     */
    private function connectTo(int $port): mixed
    {
        $socket = stream_socket_client("tcp://127.0.0.1:$port", $code, $error, 1);
        self::assertIsResource($socket);
        $this->ownStream($socket);
        stream_set_timeout($socket, 1);
        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function readLine(mixed $socket): string
    {
        $line = fgets($socket);
        self::assertNotFalse($line, 'Worker response deadline.');
        return $line;
    }

    /**
     * @return array{timedOut: bool, exit: int, errors: string}
     */
    private function finishFixture(stdClass $fixture, bool $signal = true): array
    {
        if ($fixture->result !== null) {
            return $fixture->result;
        }
        if ($signal) {
            proc_terminate($fixture->process, 15);
        }
        $deadline = microtime(true) + 6;
        do {
            $this->collectReady($fixture);
            $status = proc_get_status($fixture->process);
            if (!$status['running']) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $timedOut = $status['running'];
        if ($timedOut) {
            // The fixture created this process group before forking workers.
            if (function_exists('posix_kill')) {
                posix_kill(-$status['pid'], 9);
            }
            proc_terminate($fixture->process, 9);
        }
        fclose($fixture->stdout);
        $exit = proc_close($fixture->process);
        if ($exit === -1) {
            $exit = $status['exitcode'];
        }
        $errors = file_get_contents($fixture->stderr);
        unlink($fixture->stderr);
        return $fixture->result = ['timedOut' => $timedOut, 'exit' => $exit, 'errors' => $errors];
    }

    /**
     * @param array{timedOut: bool, exit: int, errors: string} $result
     */
    private function assertFixtureExit(array $result, int $expected = 0): void
    {
        self::assertFalse($result['timedOut'], 'Supervisor shutdown exceeded its deadline.');
        self::assertSame($expected, $result['exit'], 'Fixture errors: ' . $result['errors']);
    }
}
