<?php

declare(strict_types=1);

namespace Crab\Engine\Tests;

use Crab\Engine\Process\FileWatcher;
use Crab\Engine\Process\PidFile;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;
use Crab\Engine\Tests\Support\TestCase;

final class ProcessToolsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-process-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/app/Deep', recursive: true);
        mkdir($this->directory . '/vendor/package', recursive: true);
        mkdir($this->directory . '/storage');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testAPidFileNamesALiveProcessOrNothing(): void
    {
        $file = new PidFile($this->directory . '/run/deep/server.pid');
        self::assertNull($file->pid(), 'No file, no process.');
        $file->write();
        self::assertSame(getmypid() . "\n", file_get_contents($file->path), 'The file holds the number and nothing else.');
        self::assertSame(getmypid(), $file->pid());
        self::assertSame([], glob($file->path . '.*'), 'Nothing is left of writing it.');

        file_put_contents($file->path, "999999999\n");
        self::assertNull($file->pid(), 'A file left by a process that has gone names nothing.');
        $file->remove();
        self::assertFileExists($file->path, 'A file that names another process is not this one\'s to remove.');
        file_put_contents($file->path, 'not a number');
        self::assertNull($file->pid());

        $file->write();
        $file->remove();
        self::assertFileDoesNotExist($file->path);
        $file->remove();
        self::assertTrue(PidFile::alive(getmypid()));
        self::assertFalse(PidFile::alive(999999999));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be');
        (new PidFile('/dev/null/server.pid'))->write();
    }

    public function testAFileWatcherReportsWhatWasAddedChangedAndRemoved(): void
    {
        $long = time() - 3600;
        $files = ['/app/One.php', '/app/Deep/Two.php', '/app/notes.txt', '/vendor/package/Three.php', '/storage/Cache.php', '/.env'];
        foreach ($files as $file) {
            file_put_contents($this->directory . $file, 'first');
            touch($this->directory . $file, $long);
        }
        $watcher = new FileWatcher([$this->directory . '/app', $this->directory . '/.env', $this->directory . '/missing', $this->directory]);
        self::assertSame(3, $watcher->count(), 'PHP files below a directory, a file named outright, and nothing under vendor or storage.');
        self::assertSame([], $watcher->changes());

        file_put_contents($this->directory . '/app/One.php', 'changed, and longer');
        file_put_contents($this->directory . '/app/notes.txt', 'not watched');
        file_put_contents($this->directory . '/vendor/package/Three.php', 'not watched');
        file_put_contents($this->directory . '/storage/Cache.php', 'not watched');
        self::assertSame([$this->directory . '/app/One.php'], $watcher->changes());
        self::assertSame([], $watcher->changes(), 'A change is reported once.');

        // The same size within the same second: only the content tells.
        file_put_contents($this->directory . '/app/One.php', 'changed, and LONGER');
        self::assertSame([$this->directory . '/app/One.php'], $watcher->changes());
        file_put_contents($this->directory . '/.env', 'A=2');
        file_put_contents($this->directory . '/app/Deep/New.php', 'new');
        unlink($this->directory . '/app/Deep/Two.php');
        $changed = $watcher->changes();
        sort($changed);
        self::assertSame([$this->directory . '/.env', $this->directory . '/app/Deep/New.php', $this->directory . '/app/Deep/Two.php'], $changed);

        touch($this->directory . '/app/One.php', $long + 60);
        self::assertSame([$this->directory . '/app/One.php'], $watcher->changes(), 'A new time alone is a change.');
        self::assertSame([], $watcher->changes());

        file_put_contents($this->directory . '/app/page.html', 'a');
        $templates = new FileWatcher([$this->directory . '/app'], ['html']);
        self::assertSame(1, $templates->count());
    }

    public function testARuntimeSaysWhenItIsReady(): void
    {
        $events = [];
        $runtime = new Runtime(shutdownTimeout: 0.1);
        $runtime->group()->onStart(static function (Worker $worker) use (&$events): void {
            $events[] = 'worker started';
            $worker->loop->delay(0.02, $worker->stop(...));
        });
        self::assertSame($runtime, $runtime->onReady(static function () use (&$events): void {
            $events[] = 'ready';
        }));
        $runtime->run(singleProcess: true);
        self::assertSame(['worker started', 'ready'], $events);
    }

    public function testASupervisedRuntimeIsReadyWhenEveryWorkerIs(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('Supervised workers need pcntl and posix.');
        }
        $code = <<<'PHP'
            require $argv[1];
            $runtime = new Crab\Engine\Runtime(shutdownTimeout: 0.2);
            $runtime->group('a', workers: 2)->onStart(static function (): void {
                usleep(30000);
                fwrite(STDOUT, "started\n");
            });
            $runtime->group('b', workers: 1)->onStart(static fn () => fwrite(STDOUT, "started\n"));
            $runtime->onReady(static function () use ($runtime): void {
                fwrite(STDOUT, "ready\n");
                $runtime->stop();
            });
            $runtime->run();
            fwrite(STDOUT, "stopped\n");
            PHP;
        $process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertSame("started\nstarted\nstarted\nready\nstopped\n", $output, 'Ready comes once, after all three workers.');
    }

    public function testASignalToTheParentRunsItsCallbackAndIsIgnoredByWorkers(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('Signals need pcntl and posix.');
        }
        $code = <<<'PHP'
            require $argv[1];
            $runtime = new Crab\Engine\Runtime(shutdownTimeout: 0.2);
            $runtime->group('a', workers: $argv[2] === 'single' ? 1 : 2);
            $seen = 0;
            $runtime->onSignal(SIGUSR2, static function () use ($runtime, &$seen): void {
                fwrite(STDOUT, "signal in " . (getmypid() === (int) getenv('PARENT') ? 'parent' : 'worker') . "\n");
                if (++$seen === 2) {
                    $runtime->stop();
                    return;
                }
                posix_kill(getmypid(), SIGUSR2);
            });
            // To the whole group: the workers must neither die of it nor answer it.
            $runtime->onReady(static fn () => posix_kill(0, SIGUSR2));
            putenv('PARENT=' . getmypid());
            posix_setpgid(0, 0);
            $runtime->run($argv[2] === 'single');
            fwrite(STDOUT, "stopped\n");
            PHP;
        foreach (['supervised', 'single'] as $mode) {
            $process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php', $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
            self::assertSame("signal in parent\nsignal in parent\nstopped\n", $output, $mode);
        }
    }

    public function testAnIdleHttpConnectionIsReadAMomentLongerWhenTheServerStops(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('Stopping by signal needs pcntl and posix.');
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $address = 'tcp://' . stream_socket_get_name($probe, false);
        fclose($probe);
        $code = <<<'PHP'
            require $argv[1];
            $runtime = new Crab\Engine\Runtime(shutdownTimeout: 3.0);
            $runtime->group()->listen($argv[2], new Crab\Engine\Server\HttpServer(
                static fn () => Crab\Engine\Http\Response::text('served'),
            ));
            $runtime->onReady(static fn () => fwrite(STDOUT, "ready\n"));
            $runtime->run(singleProcess: true);
            PHP;
        $server = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php', $address], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertSame("ready\n", fgets($pipes[1]));

        // Three clients the server cannot tell apart yet: all connected, none has sent anything.
        $late = stream_socket_client($address);
        $kept = stream_socket_client($address);
        $silent = stream_socket_client($address);
        fwrite($kept, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
        self::assertStringContainsString('served', (string) fread($kept, 8192));
        usleep(50_000);

        $asked = microtime(true);
        proc_terminate($server, SIGTERM);
        usleep(30_000);
        // Their requests were on the way before they could know the server was stopping.
        fwrite($late, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
        fwrite($kept, "GET / HTTP/1.1\r\nHost: x\r\n\r\n");
        foreach ([$late, $kept] as $client) {
            stream_set_timeout($client, 5);
            $response = (string) stream_get_contents($client);
            self::assertStringContainsString('200 OK', $response);
            self::assertStringContainsString('served', $response);
            self::assertMatchesRegularExpression('/connection: close/i', $response, 'It is answered as the last on its connection.');
        }
        stream_set_timeout($silent, 5);
        self::assertSame('', stream_get_contents($silent), 'One that sends nothing is closed.');
        for ($waited = 0; $waited < 200 && proc_get_status($server)['running']; ++$waited) {
            usleep(20_000);
        }
        self::assertFalse(proc_get_status($server)['running']);
        self::assertLessThan(1.5, microtime(true) - $asked, 'The server does not wait long for it.');
        self::assertSame('', stream_get_contents($pipes[2]));
        proc_close($server);
    }

    public function testAProcessGoesIntoTheBackgroundAndReportsToItsLauncher(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('The background needs pcntl and posix.');
        }
        $code = <<<'PHP'
            require $argv[1];
            [, , $log, $mode, $marker] = $argv;
            $daemon = Crab\Engine\Process\Daemon::start($log);
            if (!$daemon->background) {
                [$started, $message] = $daemon->await($mode === 'silent' ? 0.3 : 5.0);
                fwrite(STDOUT, json_encode([$started, $message]) . "\n");
                exit($started ? 0 : 1);
            }
            echo "echoed\n";
            fwrite(fopen('php://stdout', 'w'), "to standard output\n");
            fwrite(fopen('php://stderr', 'w'), "to standard error\n");
            Crab\Engine\FailureReporter::report('A report from the background');
            if ($mode === 'ready') {
                $daemon->ready((string) getmypid());
                $daemon->fail('ignored: it already said it was ready');
                file_put_contents($marker, json_encode([posix_getsid(0) === posix_getpid() ? 'leads its session' : 'in a session of its own', posix_getppid(), posix_ttyname(0)]));
            } elseif ($mode === 'fail') {
                $daemon->fail("it could not\nstart");
            } elseif ($mode === 'silent') {
                usleep(600000);
            }
            PHP;
        $run = function (string $mode) use ($code): array {
            $log = $this->directory . "/logs/{$mode}.log";
            $marker = $this->directory . "/{$mode}.marker";
            $process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php', $log, $mode, $marker],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            $status = proc_close($process);
            return [$status, json_decode(trim($output), true), $errors, $log, $marker];
        };
        [$status, $report, $errors, $log, $marker] = $run('ready');
        self::assertSame([0, true, ''], [$status, $report[0], $errors]);
        $pid = (int) $report[1];
        self::assertNotSame(getmypid(), $pid);
        for ($waited = 0; $waited < 100 && !is_file($marker); ++$waited) {
            usleep(20000);
        }
        [$session, $parent, $terminal] = json_decode((string) file_get_contents($marker), true);
        self::assertSame(['in a session of its own', false], [$session, $terminal], 'It left the terminal and cannot take one back.');
        self::assertNotSame(getmypid(), $parent, 'It is no child of the process that launched it.');
        self::assertSame("echoed\nto standard output\nto standard error\n[Crab] A report from the background\n", file_get_contents($log), 'Everything it prints goes to its file.');

        [$status, $report] = $run('fail');
        self::assertSame([1, [false, "it could not\nstart"]], [$status, $report]);
        [$status, $report] = $run('exit');
        self::assertSame([1, [false, 'It ended before reporting.']], [$status, $report]);
        [$status, $report] = $run('silent');
        self::assertSame([1, [false, 'It did not report within 0.3 seconds.']], [$status, $report]);
        // Let the one that stayed silent end before its files are removed.
        usleep(700000);
    }
}
