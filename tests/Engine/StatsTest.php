<?php

declare(strict_types=1);

namespace Crab\Tests\Engine;

use Crab\Engine\Crab;
use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Process\StatsFile;
use Crab\Engine\Process\Worker;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * A runtime told where to keep its stats writes one file for the server,
 * kept up to date as workers count, die, are started again and are
 * replaced, and removed when it stops.
 */
final class StatsTest extends TestCase
{
    private string $directory;
    /**
     * @var resource|null
     */
    private mixed $process = null;
    private int $port;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-stats-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            $pid = proc_get_status($this->process)['pid'];
            if (function_exists('posix_kill')) {
                // The fixture leads a process group of its own, with its workers.
                posix_kill(-$pid, SIGKILL);
            }
            proc_terminate($this->process, SIGKILL);
            proc_close($this->process);
        }
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testAWorkerCountsAndCarriesItsCountsInItsStats(): void
    {
        $worker = new Worker(new StreamSelectEventLoop(), new Crab('web'), 0, ['tcp' => [], 'udp' => []], 1.0);
        self::assertSame([], $worker->stats()['counts']);

        $worker->count('requests');
        $worker->count('requests');
        $worker->count('failed', 3);

        self::assertSame(['requests' => 2, 'failed' => 3], $worker->stats()['counts']);
        self::assertEqualsWithDelta(microtime(true), $worker->stats()['started'], 5.0);
    }

    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function testASupervisedServerKeepsItsFileThroughARestartAndAReload(): void
    {
        $pid = $this->start('supervised');
        $server = $this->await($pid, static fn(array $server): bool => self::ready($server) === 2);
        self::assertSame($pid, $server['pid']);
        self::assertSame(['address' => "tcp://127.0.0.1:{$this->port}", 'mode' => 'supervised'], $server['about']);
        self::assertSame('running', $server['state']);
        self::assertSame(['echo', 'echo'], array_column($server['workers'], 'group'));
        self::assertSame([0, 1], array_column($server['workers'], 'index'));

        $this->send(5);
        $server = $this->await($pid, static fn(array $server): bool => self::counted($server) === 5);
        self::assertSame(5, self::counted($server), 'What every worker counted reaches the file.');

        $killed = $server['workers'][0]['pid'];
        posix_kill($killed, SIGKILL);
        $server = $this->await(
            $pid,
            static fn(array $server): bool => (
                $server['workers'][0]['restarts'] === 1
                && $server['workers'][0]['state'] === 'ready'
            ),
        );
        self::assertNotSame($killed, $server['workers'][0]['pid']);
        self::assertSame(0, $server['workers'][1]['restarts']);

        $before = array_column($server['workers'], 'pid');
        posix_kill($pid, SIGUSR1);
        $server = $this->await(
            $pid,
            static fn(array $server): bool => (
                count($server['workers']) === 2
                && self::ready($server) === 2
                && array_intersect(array_column($server['workers'], 'pid'), $before) === []
            ),
        );
        self::assertSame('running', $server['state']);
        self::assertSame(1, $server['workers'][0]['restarts'], 'A reload is not a restart.');

        $this->stop();
        self::assertFileDoesNotExist("{$this->directory}/{$pid}.json");
    }

    #[RequiresPhpExtension('posix')]
    public function testAServerInOneProcessWritesItsOwnFile(): void
    {
        $pid = $this->start('single');
        $this->await($pid, static fn(array $server): bool => self::ready($server) === 1);
        $this->send(3);
        $server = $this->await($pid, static fn(array $server): bool => self::counted($server) === 3);
        self::assertSame($pid, $server['workers'][0]['pid'], 'The one process is server and worker.');
        self::assertSame(0, $server['workers'][0]['restarts']);

        $this->stop();
        self::assertFileDoesNotExist("{$this->directory}/{$pid}.json");
    }

    #[RequiresPhpExtension('posix')]
    public function testReadingLeavesOutAndRemovesTheFilesOfServersThatHaveGone(): void
    {
        $own = new StatsFile($this->directory, ['mode' => 'test']);
        $own->write('running', [StatsFile::worker('web', 0, getmypid(), 'ready')]);
        // A process that has ended, and a live one that is not the process that wrote the file.
        $gone = $this->endedPid();
        file_put_contents("{$this->directory}/{$gone}.json", json_encode([
            'pid' => $gone,
            'identity' => '1',
            'started' => 1.0,
        ]));
        $parent = posix_getppid();
        file_put_contents("{$this->directory}/{$parent}.json", json_encode([
            'pid' => $parent,
            'identity' => 'not its start',
            'started' => 1.0,
        ]));

        $servers = StatsFile::read($this->directory);

        self::assertCount(1, $servers);
        self::assertSame(getmypid(), $servers[0]['pid']);
        self::assertSame(['mode' => 'test'], $servers[0]['about']);
        self::assertSame('web', $servers[0]['workers'][0]['group']);
        self::assertFileDoesNotExist("{$this->directory}/{$gone}.json");
        self::assertFileDoesNotExist("{$this->directory}/{$parent}.json");
        $own->remove();
        self::assertSame([], StatsFile::read($this->directory));
    }

    private function start(string $mode): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($probe);
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $this->process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/stats.php', $mode, (string) $this->port, $this->directory],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::assertIsResource($this->process);
        fclose($pipes[0]);
        return proc_get_status($this->process)['pid'];
    }

    /**
     * Each line on a connection of its own, so that every worker may take some.
     */
    private function send(int $lines): void
    {
        for ($i = 0; $i < $lines; ++$i) {
            $socket = stream_socket_client("tcp://127.0.0.1:{$this->port}", $code, $error, 1);
            self::assertIsResource($socket);
            stream_set_timeout($socket, 1);
            fwrite($socket, "hello\n");
            self::assertNotFalse(fgets($socket));
            fclose($socket);
        }
    }

    private function stop(): void
    {
        $pid = proc_get_status($this->process)['pid'];
        posix_kill($pid, SIGTERM);
        $deadline = microtime(true) + 5;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
            usleep(10_000);
        }
        self::assertFalse(proc_get_status($this->process)['running'], 'The server stops.');
        proc_close($this->process);
        $this->process = null;
    }

    /**
     * The server's file, once it shows what the condition looks for.
     *
     * @param \Closure(array<string, mixed>): bool $until
     * @return array<string, mixed>
     */
    private function await(int $pid, \Closure $until, float $timeout = 6.0): array
    {
        $deadline = microtime(true) + $timeout;
        $server = null;
        do {
            $read = json_decode((string) @file_get_contents("{$this->directory}/{$pid}.json"), true);
            if (is_array($read)) {
                $server = $read;
                if ($until($server)) {
                    return $server;
                }
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        self::fail('The stats file did not come to show it in time; last: ' . json_encode($server));
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function ready(array $server): int
    {
        return count(array_filter($server['workers'], static fn(array $worker): bool => $worker['state'] === 'ready'));
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function counted(array $server): int
    {
        return array_sum(array_map(
            static fn(array $worker): int => $worker['counts']['messages'] ?? 0,
            $server['workers'],
        ));
    }

    private function endedPid(): int
    {
        $process = proc_open([PHP_BINARY, '-r', ''], [], $pipes);
        self::assertIsResource($process);
        $pid = proc_get_status($process)['pid'];
        proc_close($process);
        return $pid;
    }
}
