<?php

declare(strict_types=1);

namespace Crab\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Drives a real application through its command line: served in the
 * background, reloaded with new code while requests arrive, stopped,
 * restarted, and restarted by the watcher when a file changes.
 */
final class ServerTest extends TestCase
{
    private string $directory;
    private string $address;
    /**
     * The address as the commands show it: something to open.
     */
    private string $url;
    /**
     * @var resource|null A watcher left running by a test that failed.
     */
    private mixed $watcher = null;
    /**
     * Subprocess handles owned by the additional lifecycle fixtures.
     *
     * @var list<array{process: resource, pipes: array<int, resource>, pid: int, role: string}>
     */
    private array $fixtures = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('Serving in the background needs pcntl and posix.');
        }
        // The real path: where the temporary folder is a link, __DIR__ in the application names its target.
        $this->directory = realpath(sys_get_temp_dir()) . '/crab-server-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/app', recursive: true);
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        file_put_contents(
            $this->directory . '/cua',
            "<?php\nrequire '{$autoload}';\n\$app = require __DIR__ . '/app.php';\n\$arguments = array_slice(\$argv, 1);\n\$status = \$app->console->run(\$arguments);\nexit(\$status);\n",
        );
        file_put_contents(
            $this->directory . '/app.php',
            "<?php\nreturn Crab\\App::configure(__DIR__)->withRouting(routes: 'app/routes.php')->create();\n",
        );
        $this->version('one');

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->address = 'tcp://' . stream_socket_get_name($probe, false);
        fclose($probe);
        $this->url = str_replace('tcp://', 'http://', $this->address);
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory)) {
            return;
        }
        if (is_resource($this->watcher)) {
            proc_terminate($this->watcher, 9);
            proc_close($this->watcher);
        }
        foreach ($this->fixtures as $fixture) {
            if ($fixture['role'] === 'keep-pipeline' && posix_getpgid($fixture['pid']) === $fixture['pid']) {
                // This entire pipeline group was created by this test.
                posix_kill(-$fixture['pid'], SIGKILL);
            }
            proc_terminate($fixture['process'], SIGKILL);
            proc_close($fixture['process']);
            foreach ($fixture['pipes'] as $pipe) {
                if (!is_resource($pipe)) {
                    continue;
                }

                fclose($pipe);
            }
        }
        $this->cua('stop', '--force', '--timeout=3');
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testTheServerRunsInTheBackgroundAndIsStopped(): void
    {
        self::assertSame([1, "No server is running in the background.\n"], $this->cua('status'));
        self::assertSame([0, "No server is running in the background.\n"], $this->cua('stop'));
        self::assertStringContainsString('No server is running in the background to reload', $this->cua('reload')[1]);

        [$status, $output] = $this->cua('start', '--daemon', "--address={$this->address}", '--workers=2');
        self::assertSame(0, $status, $output);
        $pid = $this->pid();
        self::assertSame("Crab server running on {$this->url}\n", $output);
        self::assertSame('one', $this->get(), 'It accepts connections by the time the command returns.');
        self::assertSame(
            1,
            posix_getpgid($pid) === posix_getpgid(getmypid()) ? 0 : 1,
            'It is not in the group of the terminal that started it.',
        );
        self::assertSame(
            [
                0,
                "The server is running in the background, pid {$pid}.\nIts output goes to {$this->directory}/storage/logs/server.log.\n",
            ],
            $this->cua('status'),
        );
        self::assertMatchesRegularExpression(
            "~\\[keeper {$pid}\\] Serving on {$this->address}, generation pid \\d+\\.~",
            (string) file_get_contents($this->directory . '/storage/logs/server.log'),
        );

        [$status, $output] = $this->cua('start', '-d', "--address={$this->address}");
        self::assertSame([
            1,
            "A server is already running in the background, pid {$pid}. Use `reload` to give it new code, or `stop` to end it.\n",
        ], [$status, $output]);
        [$status, $output] = $this->cua('start', "--address={$this->address}", '--single');
        self::assertSame(1, $status, 'A second server in the foreground cannot take the address by accident.');
        self::assertStringContainsString('Address already in use', $output);
        self::assertStringNotContainsString('server running', $output);
        self::assertStringContainsString('choose another --address=', $output);

        self::assertSame([0, "Stopped the server, pid {$pid}.\n"], $this->cua('stop'));
        self::assertFalse(posix_kill($pid, 0), 'The process has gone.');
        self::assertNull($this->get());
        self::assertFileDoesNotExist($this->directory . '/storage/crab.pid');
        self::assertSame(1, $this->cua('status')[0]);
        self::assertStringContainsString(
            "[keeper {$pid}] Stopped.",
            (string) file_get_contents($this->directory . '/storage/logs/server.log'),
        );

        // A file left behind by a crash, whose number now belongs to something else: this test's own process.
        file_put_contents($this->directory . '/storage/crab.pid', getmypid() . "\n");
        self::assertSame(
            [1, "No server is running in the background.\n"],
            $this->cua('status'),
            'A live process that is not the server is not taken for it.',
        );
        self::assertSame([0, "No server is running in the background.\n"], $this->cua('stop'), 'And is not stopped.');
        self::assertTrue(posix_kill(getmypid(), 0));
        self::assertSame(
            0,
            $this->cua('start', '--daemon', "--address={$this->address}")[0],
            'Nor does it stand in the way of starting.',
        );
        self::assertNotSame(getmypid(), $this->pid());
        self::assertSame(0, $this->cua('stop')[0]);

        self::assertSame(1, $this->cua('start', '--daemon', '--single')[0]);
        [$status, $output] = $this->cua('start', '--help');
        self::assertSame(0, $status, 'Asking a command for help describes it and does not run it.');
        self::assertStringStartsWith("Start the application's server, for production\n\nOptions:\n", $output);
        self::assertSame($output, $this->cua('start', '-h', "--address={$this->address}")[1]);
        self::assertNull($this->get());
    }

    public function testTheDefaultDrainKeepsTheWorkersFiveSecondsAndTheServersFifteenSeconds(): void
    {
        $app = new \Crab\App();
        self::assertSame(5.0, $app->config->get('server.drain'));
        self::assertSame(5.0, $app->drain());
        $runtime = $app->runtime($this->address);
        self::assertSame(5.0, $runtime->shutdownTimeout);
        $groups = new \ReflectionProperty($runtime, 'groups');
        self::assertSame(5.0, $groups->getValue($runtime)['http']->shutdownTimeout);
        $server = new \Crab\Server($app);
        self::assertSame(15.0, new \ReflectionMethod($server, 'grace')->invoke($server));
    }

    public function testEveryOuterAllowanceExceedsTheHttpDrainDeadline(): void
    {
        foreach ([1.0, 30.0, 120.0] as $drain) {
            $app = new \Crab\App(new \Crab\Config\Config(['server' => ['drain' => $drain]]));
            $runtime = $app->runtime($this->address);
            $groups = new \ReflectionProperty($runtime, 'groups')->getValue($runtime);
            $supervisor = new \Crab\Engine\Process\Supervisor(
                $groups,
                [],
                static fn() => \Crab\Engine\EventLoop::current(),
                $runtime->shutdownTimeout,
            );
            $workerWait = new \ReflectionMethod($supervisor, 'grace')->invoke($supervisor, 'http:0');
            $server = new \Crab\Server($app);
            $outerWait = new \ReflectionMethod($server, 'grace')->invoke($server);
            self::assertGreaterThan($drain, $workerWait);
            self::assertGreaterThan(
                $workerWait,
                $outerWait,
                'The keeper, watcher and default stop wait use this allowance.',
            );
        }
    }

    public function testHttpDrainIsKeptSeparateFromTheOtherServersAllowance(): void
    {
        $app = new \Crab\App(new \Crab\Config\Config(['server' => ['drain' => 0.3, 'with' => ['slow']]]));
        $app->servers->add('slow', static fn() => [], grace: 30.0);
        $runtime = $app->runtime($this->address);
        $groups = new \ReflectionProperty($runtime, 'groups')->getValue($runtime);
        self::assertSame(30.0, $runtime->shutdownTimeout);
        self::assertSame(0.3, $groups['http']->shutdownTimeout);
        $server = new \Crab\Server($app);
        self::assertSame(35.0, new \ReflectionMethod($server, 'grace')->invoke($server));
    }

    public function testANumericDrainFromTheEnvironmentIsReadAsSeconds(): void
    {
        $app = new \Crab\App(new \Crab\Config\Config(['server' => ['drain' => '0.3']]));
        self::assertSame(0.3, $app->drain());
        self::assertSame(0.3, $app->runtime($this->address)->shutdownTimeout);
    }

    public function testAWatchedChildLeavesALogSharedByStdoutAndStderrWhereItWas(): void
    {
        // `start > dev.log 2>&1`: stdout and stderr share one file, and so one position in it.
        $script = $this->directory . '/spawn.php';
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        file_put_contents($this->directory . '/child.php', "<?php\necho \"child\\n\";\n");
        file_put_contents(
            $script,
            "<?php\nrequire '{$autoload}';\n"
            . "fwrite(STDOUT, \"before\\n\");\n"
            . "\$spawn = new ReflectionMethod(Crab\\Server::class, 'spawn');\n"
            . "[\$process, \$report] = \$spawn->invoke(null, [__DIR__ . '/child.php']);\n"
            . "while (proc_get_status(\$process)['running']) { usleep(10_000); }\n"
            . "proc_close(\$process);\n"
            . "fwrite(STDOUT, \"after\\n\");\n",
        );
        $log = $this->directory . '/dev.log';
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' > ' . escapeshellarg($log) . ' 2>&1',
            $ignored,
            $status,
        );

        self::assertSame(0, $status);
        self::assertSame(
            "before\nchild\nafter\n",
            file_get_contents($log),
            'Starting the child must not rewind the log.',
        );
    }

    public function testAWatchedChildReportsItsCurrentAllowanceAfterTheSettingChanges(): void
    {
        $spawn = new \ReflectionMethod(\Crab\Server::class, 'spawn');
        $reported = new \ReflectionMethod(\Crab\Server::class, 'reportedGrace');
        $terminate = new \ReflectionMethod(\Crab\Server::class, 'terminate');
        foreach ([1.0 => 15.0, 30.0 => 36.0, 120.0 => 126.0] as $drain => $expected) {
            $this->drainSetting($drain);
            [$process, $report] = $spawn->invoke(null, [
                $this->directory . '/cua',
                'start',
                '--single',
                '--quiet',
                "--address={$this->address}",
            ]);
            try {
                $deadline = microtime(true) + 3.0;
                $allowance = -1.0;
                while (is_resource($report) && microtime(true) < $deadline) {
                    $allowance = $reported->invokeArgs(null, [&$report, $allowance]);
                    usleep(5_000);
                }
                self::assertSame($expected, $allowance, 'The launcher must use the child\'s new setting.');
                self::assertSame('one', $this->get(retry: true));
            } finally {
                $terminate->invoke(null, $process, $expected);
                if (is_resource($report)) {
                    fclose($report);
                }
            }
        }
    }

    public function testStartRefusesInvalidDrainSettingsBeforeStarting(): void
    {
        foreach ([0, 'abc', -1, null, false, INF, NAN] as $drain) {
            $this->drainSetting($drain);
            foreach ([['--single'], ['--daemon'], []] as $options) {
                [$status, $output] = $this->cua('start', "--address={$this->address}", ...$options);
                self::assertSame(1, $status, $output);
                self::assertStringContainsString('server.drain', $output);
                self::assertFileDoesNotExist($this->directory . '/storage/crab.pid');
            }
        }
    }

    public function testAStoppingServerAnswersARequestThatFinishesWithinItsDrainTime(): void
    {
        $this->drainSetting(2.0);
        $this->slowVersion('one', 0.5);
        [$status, $output] = $this->cua('start', '--daemon', "--address={$this->address}");
        self::assertSame(0, $status, $output);
        $request = $this->slowRequest();
        try {
            [$status, $output] = $this->stopDuringRequest();
            self::assertSame(0, $status, $output);
            $response = (string) stream_get_contents($request);
            self::assertStringContainsString('200 OK', $response);
            self::assertStringEndsWith('one finished', $response);
            self::assertStringNotContainsString('WARNING', $this->drainLog());
        } finally {
            fclose($request);
        }
    }

    public function testAStoppingServerCutsAnOverdueRequestAndLogsOneWarning(): void
    {
        $this->drainSetting(0.3);
        // Another server's allowance must not lengthen HTTP draining.
        file_put_contents(
            $this->directory . '/config/server.php',
            "<?php return ['drain' => 0.3, 'with' => ['slow']];",
        );
        file_put_contents($this->directory . '/app.php', <<<'PHP'
            <?php
            $app = Crab\App::configure(__DIR__)->withRouting(routes: 'app/routes.php')->create();
            $app->servers->add('slow', static fn () => [], grace: 30.0);
            return $app;
            PHP);
        $this->slowVersion('one', 2.0);
        [$status, $output] = $this->cua('start', '--daemon', "--address={$this->address}");
        self::assertSame(0, $status, $output);
        $request = $this->slowRequest();
        try {
            $before = microtime(true);
            [$status, $output] = $this->stopDuringRequest();
            $elapsed = microtime(true) - $before;
            self::assertSame(0, $status, $output);
            self::assertGreaterThanOrEqual(0.25, $elapsed);
            self::assertLessThan(3.0, $elapsed, 'The server did not exit promptly at its drain deadline.');
            self::assertSame('', stream_get_contents($request));
            $log = $this->drainLog();
            self::assertSame(1, substr_count($log, 'WARNING'));
            self::assertStringContainsString('server.drain; cut 1 connections.', $log);
        } finally {
            fclose($request);
        }
    }

    public function testAReloadLetsTheOldGenerationFinishWhileNewRequestsUseTheNewCode(): void
    {
        $this->drainSetting(2.0);
        $this->slowVersion('one', 0.5);
        [$status, $output] = $this->cua('start', '--daemon', "--address={$this->address}");
        self::assertSame(0, $status, $output);
        $request = $this->slowRequest();
        try {
            $this->slowVersion('two', 0.5);
            [$status, $output] = $this->cua('reload', "--address={$this->address}");
            self::assertSame(0, $status, $output);
            self::assertSame('two', $this->get());
            $this->releaseRequest();
            $response = (string) stream_get_contents($request);
            self::assertStringContainsString('200 OK', $response);
            self::assertStringEndsWith('one finished', $response);
            self::assertStringNotContainsString('WARNING', $this->drainLog());
        } finally {
            fclose($request);
        }
    }

    public function testAWorkerLogsOneWarningWithTheCountOfAllConnectionsItCuts(): void
    {
        $this->drainSetting(0.3);
        $this->slowVersion('one', 2.0);
        [$status, $output] = $this->cua('start', '--daemon', "--address={$this->address}");
        self::assertSame(0, $status, $output);
        $first = $this->slowRequest();
        $second = $this->slowRequest();
        try {
            [$status, $output] = $this->stopDuringRequest();
            self::assertSame(0, $status, $output);
            self::assertSame('', stream_get_contents($first));
            self::assertSame('', stream_get_contents($second));
            $log = $this->drainLog();
            self::assertSame(1, substr_count($log, 'WARNING'));
            self::assertStringContainsString('server.drain; cut 2 connections.', $log);
        } finally {
            fclose($first);
            fclose($second);
        }
    }

    #[Group('slow')]
    public function testASixSecondRequestIsAnsweredInFullUnderAnEightSecondDrain(): void
    {
        $this->drainSetting(8.0);
        $this->slowVersion('one', 6.0);
        [$status, $output] = $this->cua('start', '--daemon', "--address={$this->address}");
        self::assertSame(0, $status, $output);
        $request = $this->slowRequest();
        try {
            [$status, $output] = $this->stopDuringRequest();
            self::assertSame(0, $status, $output);
            $response = (string) stream_get_contents($request);
            self::assertStringContainsString('200 OK', $response);
            self::assertStringEndsWith('one finished', $response);
            self::assertStringNotContainsString('WARNING', $this->drainLog());
        } finally {
            fclose($request);
        }
    }

    #[Group('slow')]
    public function testATwentySecondRequestIsCutAtTheSevenSecondDrainWithItsCount(): void
    {
        $this->drainSetting(7.0);
        $this->slowVersion('one', 20.0);
        [$status, $output] = $this->cua('start', '--daemon', "--address={$this->address}");
        self::assertSame(0, $status, $output);
        $request = $this->slowRequest();
        try {
            $before = microtime(true);
            [$status, $output] = $this->stopDuringRequest();
            $elapsed = microtime(true) - $before;
            self::assertSame(0, $status, $output);
            self::assertGreaterThanOrEqual(6.8, $elapsed);
            self::assertLessThan(10.0, $elapsed);
            self::assertSame('', stream_get_contents($request));
            self::assertSame(1, substr_count($this->drainLog(), 'WARNING'));
            self::assertStringContainsString('server.drain; cut 1 connections.', $this->drainLog());
        } finally {
            fclose($request);
        }
    }

    public function testAnIdleKeepAliveConnectionDoesNotIncreaseTheCutRequestCount(): void
    {
        $this->drainSetting(0.04);
        $this->slowVersion('one', 2.0);
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}")[0]);
        $idle = stream_socket_client($this->address, $code, $message, 1);
        self::assertIsResource($idle);
        stream_set_timeout($idle, 5);
        fwrite($idle, "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n");
        $headers = '';
        while (($line = fgets($idle)) !== false) {
            $headers .= $line;
            if ($line === "\r\n") {
                break;
            }
        }
        self::assertStringContainsString('200 OK', $headers);
        self::assertSame('one', fread($idle, 3));
        $active = $this->slowRequest();
        try {
            [$status, $output] = $this->stopDuringRequest();
            self::assertSame(0, $status, $output);
            self::assertSame('', stream_get_contents($active));
            self::assertSame('', stream_get_contents($idle));
            self::assertSame(1, substr_count($this->drainLog(), 'WARNING'));
            self::assertStringContainsString('server.drain; cut 1 connections.', $this->drainLog());
        } finally {
            fclose($active);
            fclose($idle);
        }
    }

    public function testABlockedHttpWorkerIsEndedAtItsOwnAllowanceAndReportedOnce(): void
    {
        $this->drainSetting(0.1);
        file_put_contents(
            $this->directory . '/config/server.php',
            "<?php return ['drain' => 0.1, 'with' => ['slow']];",
        );
        file_put_contents($this->directory . '/app.php', <<<'PHP'
            <?php
            $app = Crab\App::configure(__DIR__)->withRouting(routes: 'app/routes.php')->create();
            $app->servers->add('slow', static fn (): array => [
                new Crab\Contracts\Workers('slow', setup: static function (Crab\Engine\Crab $group): void {}),
            ], grace: 30.0);
            return $app;
            PHP);
        file_put_contents($this->directory . '/app/routes.php', <<<'PHP'
            <?php
            Crab\Route::get('/slow', static function (): string {
                file_put_contents(dirname(__DIR__) . '/in-flight', (string) getmypid());
                sleep(20);
                return 'too late';
            });
            PHP);
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}")[0]);
        $request = $this->slowRequest();
        try {
            $worker = trim((string) file_get_contents($this->directory . '/in-flight'));
            $before = microtime(true);
            [$status, $output] = $this->cua('stop');
            self::assertSame(0, $status, $output);
            self::assertLessThan(4.0, microtime(true) - $before, 'Another group stretched the HTTP hard deadline.');
            self::assertSame('', stream_get_contents($request));
            $log = (string) file_get_contents($this->directory . '/storage/logs/server.log');
            $notice = "Worker {$worker} in group \"http\" did not stop within 1.1 seconds; ending it.";
            self::assertSame(1, substr_count($log, $notice), $log);
            self::assertStringNotContainsString(
                'WARNING',
                $this->drainLog(),
                'A blocked worker cannot count its requests.',
            );
        } finally {
            fclose($request);
        }
    }

    public function testAnExplicitFifteenSecondStopDoesNotDeriveItsWaitFromConfiguration(): void
    {
        $this->drainSetting(30.0);
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}")[0]);
        $pid = $this->pid();
        $this->drainSetting('abc');
        // An old pid file has no keeper record and uses the legacy fallback.
        unlink($this->directory . '/storage/crab.pid.state');
        [$status, $output] = $this->cua('stop');
        self::assertSame(1, $status, $output);
        self::assertStringContainsString('server.drain', $output);
        self::assertTrue(posix_kill($pid, 0), 'A legacy pid file uses the configuration fallback.');
        self::assertSame([0, "Stopped the server, pid {$pid}.\n"], $this->cua('stop', '--timeout=15'));
        self::assertFalse(posix_kill($pid, 0));
    }

    public function testARestartWithSettingsThatDoNotFitLeavesTheServerRunning(): void
    {
        $this->drainSetting(30.0);
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}")[0]);
        $pid = $this->pid();
        $this->drainSetting('abc');
        foreach ([['restart', '--timeout=15'], ['restart'], ['reload']] as $command) {
            [$status, $output] = $this->cua(...[...$command, "--address={$this->address}"]);
            self::assertSame(1, $status, $output);
            self::assertSame(
                "A setting does not fit:\n  server.drain is \"abc\"; it takes a number greater than 0.\n",
                $output,
            );
            self::assertTrue(posix_kill($pid, 0), 'Nothing was stopped: the settings were checked first.');
            self::assertSame('one', $this->get(retry: true));
        }
        $this->drainSetting(30.0);
        self::assertSame(0, $this->cua('stop')[0]);
    }

    public function testAThrowingWarningLoggerPreservesTheCountAndProviderCleanup(): void
    {
        $this->checkThrowingLogger(false);
    }

    public function testTheDaemonLeadsItsGroupAndLeavesNoDrainRecordsAfterNormalStop(): void
    {
        $this->observeProcesses();
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}", '--workers=2')[0]);
        $pid = $this->pid();
        self::assertSame($pid, posix_getpgid($pid));
        self::assertSame($pid, posix_getsid($pid));
        $processes = $this->observedProcesses($pid);
        self::assertCount(1, $processes);
        self::assertCount(2, $processes[0]['workers']);
        self::assertSame($pid, posix_getpgid($processes[0]['pid']));
        foreach (file($this->directory . '/test-processes', FILE_IGNORE_NEW_LINES) as $line) {
            $worker = json_decode($line, true);
            self::assertSame($pid, $worker['group']);
            self::assertSame($pid, $worker['session']);
            self::assertNotContains(
                'php://fd/5',
                $worker['streams'],
                'No worker inherits the removed record descriptor.',
            );
            foreach ($worker['streams'] as $uri) {
                self::assertStringNotContainsString('crab.pid.generation.', $uri);
            }
        }
        $state = json_decode(file_get_contents($this->directory . '/storage/crab.pid.state'), true);
        self::assertSame(\Crab\Engine\Process\PidFile::identity($pid), $state['identity']);
        self::assertSame(['pid', 'stopTimeout', 'deadline'], array_keys($state['generations'][0]));
        self::assertSame(0, $this->cua('stop')[0]);
        $this->assertProcessesGone($pid, $processes);
        self::assertSame([$this->directory . '/storage/crab.pid.lock'], glob($this->directory . '/storage/crab.pid*'));
    }

    public function testForceOfAHungKeeperInAnotherJobsGroupReportsAPartialStop(): void
    {
        mkdir($this->directory . '/config');
        file_put_contents($this->directory . '/config/server.php', "<?php return ['pid' => 'storage/service.pid'];");
        $fixture = __DIR__ . '/Engine/Fixtures/drain.php';
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $processes = [];
        $streams = [];
        try {
            foreach (['storage', 'unrelated'] as $name) {
                $process = proc_open(
                    [PHP_BINARY, $fixture, 'unresponsive', $autoload, $this->directory . '/' . $name, 'start'],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                self::assertIsResource($process);
                $processes[] = $process;
                array_push($streams, ...array_values($pipes));
                stream_set_timeout($pipes[1], 3);
                self::assertSame("ready\n", fgets($pipes[1]));
            }
            $keeper = proc_get_status($processes[0])['pid'];
            $unrelated = proc_get_status($processes[1])['pid'];
            self::assertNotSame($keeper, posix_getpgid($keeper));
            self::assertSame(posix_getpgid($keeper), posix_getpgid($unrelated));
            [$command, $pipes] = $this->startCommand('stop', '--force');
            $processes[] = $command;
            array_push($streams, ...array_values($pipes));
            $deadline = microtime(true) + 5.0;
            do {
                // Reap our child while the command checks that its keeper is gone.
                proc_get_status($processes[0]);
                $status = proc_get_status($command);
                if (!$status['running']) {
                    break;
                }
                usleep(5_000);
            } while (microtime(true) < $deadline);
            self::assertFalse($status['running'], 'A partial force must still have a bounded wait.');
            [$exit, $output] = $this->finishCommand($command, $pipes);
            self::assertSame(1, $exit, $output);
            self::assertStringContainsString("Ended only the keeper, pid {$keeper}", $output);
            self::assertStringContainsString(
                'Its workers may still be running and are not this command\'s to signal',
                $output,
            );
            self::assertStringContainsString('Whatever started the server must end them', $output);
            self::assertStringNotContainsString('within their drain time', $output);
            self::assertFalse(posix_kill($keeper, 0));
            self::assertTrue(posix_kill($unrelated, 0), 'Another process in the job must survive.');
            self::assertFileDoesNotExist($this->directory . '/storage/service.pid');
        } finally {
            foreach ($processes as $process) {
                if (!is_resource($process)) {
                    continue;
                }

                proc_terminate($process, SIGKILL);
                proc_close($process);
            }
            foreach ($streams as $stream) {
                if (!is_resource($stream)) {
                    continue;
                }

                fclose($stream);
            }
        }
    }

    public function testForcePreservesASiblingInAShellPipelineGroup(): void
    {
        mkdir($this->directory . '/config');
        file_put_contents($this->directory . '/config/server.php', "<?php return ['pid' => 'storage/service.pid'];");
        [$keeper, $pipes, $pid] = $this->launchDrainFixture(
            'keep-pipeline',
            $this->directory . '/storage',
            $this->address,
            'start',
        );
        $owned = [];
        try {
            self::assertSame($pid, posix_getpgid($pid));
            self::assertNotSame($pid, posix_getsid($pid));
            $observation = json_decode(trim(file_get_contents($this->directory . '/storage/observed-workers')), true);
            $owned = [$observation['pid'], $observation['generation']];
            [$sibling, $siblingPipes, $siblingPid] = $this->launchDrainFixture(
                'unresponsive',
                $this->directory . '/unrelated',
                'start',
                (string) $pid,
            );
            self::assertSame($pid, posix_getpgid($siblingPid));
            posix_kill($pid, SIGSTOP);
            [$status, $output] = $this->cua('stop', '--force');
            self::assertSame(1, $status, $output);
            self::assertStringContainsString('did not lead its own session', $output);
            self::assertStringContainsString('Whatever started the server must end them', $output);
            self::assertTrue(posix_kill($siblingPid, 0), 'The sibling belongs to the shell job, not the server.');
            foreach ($owned as $process) {
                self::assertTrue(posix_kill($process, 0), 'Limited force must not signal the surrounding group.');
            }
        } finally {
            foreach ($owned as $process) {
                posix_kill($process, SIGKILL);
            }
        }
    }

    public function testAStaleStartIdentityCannotSelectAnotherLiveServeProcess(): void
    {
        mkdir($this->directory . '/config');
        file_put_contents($this->directory . '/config/server.php', "<?php return ['pid' => 'storage/service.pid'];");
        [$old, $oldPipes, $oldPid] = $this->launchDrainFixture('unresponsive', $this->directory . '/old', 'start');
        $oldIdentity = \Crab\Engine\Process\PidFile::identity($oldPid);
        // ps lstart has one-second resolution; make these real births distinct.
        usleep(1_100_000);
        [$other, $otherPipes, $pid] = $this->launchDrainFixture('unresponsive', $this->directory . '/storage', 'start');
        self::assertNotNull($oldIdentity);
        self::assertNotSame($oldIdentity, \Crab\Engine\Process\PidFile::identity($pid));
        $pidFile = new \Crab\Engine\Process\PidFile($this->directory . '/storage/service.pid');
        file_put_contents($pidFile->path . '.state', json_encode(['pid' => $pid, 'identity' => $oldIdentity]));
        foreach (['status', 'stop'] as $command) {
            [$status, $output] = $this->cua($command, '--force');
            self::assertSame(1, $status, $output);
            self::assertStringContainsString('is stale', $output);
            self::assertStringContainsString('started at another time', $output);
            self::assertTrue(posix_kill($pid, 0), 'No signal may reach the unrelated process.');
        }
        try {
            new \Crab\Engine\Process\Keeper($pidFile, $this->directory . '/service.log')->force($pid);
            self::fail('The direct force path must also check the recorded identity.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('is stale', $error->getMessage());
            self::assertTrue(posix_kill($pid, 0));
        }
    }

    public function testForceEndsARegisteredProgramInTheKeepersSession(): void
    {
        $this->checkRegisteredProgramStop(true);
    }

    public function testARegisteredProgramsOrdinaryCleanupStillRuns(): void
    {
        $this->checkRegisteredProgramStop(false);
    }

    public function testACompoundRegisteredLineStopsEveryPipelineProgramWithoutRunningTheNextCommand(): void
    {
        $this->checkRegisteredProgramStop(false, compound: true);
    }

    public function testReloadLetsACompoundRegisteredProgramsCleanupFinish(): void
    {
        $this->checkRegisteredProgramStop(false, compound: true, reload: true);
    }

    public function testForceEndsARegisteredProgramWhenItsKeeperIsSuspended(): void
    {
        $this->checkRegisteredProgramStop(true, suspended: true);
    }

    public function testPlainStopWaitsForADrainingWorkerWhoseSupervisorCrashed(): void
    {
        $this->checkCrashedSupervisorStop(false);
    }

    public function testPlainStopNamesACrashedSupervisorsWorkerThatOutlivesTheAllowance(): void
    {
        $this->checkCrashedSupervisorStop(true);
    }

    public function testAZombieKeeperIsReportedAsEndedWithoutFailingAnEmptyGroupStop(): void
    {
        mkdir($this->directory . '/config');
        file_put_contents($this->directory . '/config/server.php', "<?php return ['pid' => 'storage/service.pid'];");
        [$keeper, $pipes, $pid] = $this->launchDrainFixture(
            'unresponsive-session',
            $this->directory . '/storage',
            'start',
        );
        self::assertSame($pid, posix_getsid($pid));
        // Do not poll/reap our child while the independent command observes it.
        [$status, $output] = $this->cua('stop', '--force');
        self::assertSame(0, $status, $output);
        self::assertTrue(\Crab\Engine\Process\PidFile::zombie($pid));
        self::assertStringContainsString('has ended and is waiting to be collected by its parent', $output);
        self::assertStringNotContainsString('has not exited', $output);
        self::assertSame([], \Crab\Engine\Process\PidFile::groupMembers($pid));
        self::assertFileDoesNotExist($this->directory . '/storage/service.pid');
    }

    public function testReloadReportsAKeeperThatEndedBeforeAnswering(): void
    {
        $this->observeProcesses();
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}")[0]);
        $pid = $this->pid();
        $processes = $this->observedProcesses($pid);
        self::assertSame($pid, posix_getsid($pid));
        posix_kill($pid, SIGSTOP);
        [$command, $pipes] = $this->startCommand('reload', "--address={$this->address}");
        try {
            $deadline = microtime(true) + 3.0;
            while (!is_file($this->directory . '/storage/crab.pid.reload') && microtime(true) < $deadline) {
                usleep(5_000);
            }
            self::assertFileExists($this->directory . '/storage/crab.pid.reload');
            posix_kill(-$pid, SIGKILL);
            [$status, $output] = $this->finishCommand($command, $pipes);
            self::assertSame(1, $status, $output);
            self::assertStringContainsString(
                "The server, pid {$pid}, ended before it answered; it is no longer running.",
                $output,
            );
            self::assertStringNotContainsString('goes on serving as it was', $output);
            $this->assertProcessesGone($pid, $processes);
        } finally {
            if (is_resource($command)) {
                proc_terminate($command, SIGKILL);
                proc_close($command);
            }
        }
    }

    /**
     * Keeps a registered program observable through both force and normal
     * cancellation; its SIGTERM marker verifies ordinary cleanup survives.
     */
    private function checkRegisteredProgramStop(
        bool $force,
        bool $suspended = false,
        bool $compound = false,
        bool $reload = false,
    ): void {
        mkdir($this->directory . '/config');
        file_put_contents($this->directory . '/config/server.php', "<?php return ['with' => ['aux']];");
        $source = file_get_contents($this->directory . '/app.php');
        $source = str_replace('return Crab\\App', '$app = Crab\\App', $source);
        $line = "PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/aux.php')";
        if ($compound) {
            $line = "'true && ' . {$line} . ' | cat; touch should-not-run'";
        }
        $source .= "\n\$app->servers->process('aux', {$line});\nreturn \$app;\n";
        file_put_contents($this->directory . '/app.php', $source);
        file_put_contents($this->directory . '/aux.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            if (is_dir('/proc/self')) {
                // Linux stat must be read past the last command-name parenthesis.
                file_put_contents('/proc/self/comm', 'aux (worker))');
            }
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function (): void {
                usleep(100_000);
                file_put_contents(__DIR__ . '/aux-stopped', 'yes');
                exit(0);
            });
            file_put_contents(__DIR__ . '/aux-pid', json_encode([
                'pid' => getmypid(), 'group' => posix_getpgid(0), 'session' => posix_getsid(0),
            ]));
            while (true) { usleep(10_000); }
            PHP);
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}")[0]);
        $pid = $this->pid();
        $deadline = microtime(true) + 3.0;
        while (!is_file($this->directory . '/aux-pid') && microtime(true) < $deadline) {
            usleep(5_000);
        }
        self::assertFileExists($this->directory . '/aux-pid');
        $program = json_decode(file_get_contents($this->directory . '/aux-pid'), true);
        try {
            self::assertSame($pid, $program['session']);
            self::assertSame($pid, $program['group']);
            if ($suspended) {
                posix_kill($pid, SIGSTOP);
            }
            [$status, $output] = $reload
                ? $this->cua('reload', "--address={$this->address}")
                : $this->cua('stop', ...$force ? ['--force'] : []);
            self::assertSame(0, $status, $output);
            if ($reload) {
                // Reload accepts new work while the old generation finishes.
                $deadline = microtime(true) + 3.0;
                while (\Crab\Engine\Process\PidFile::running($program['pid']) && microtime(true) < $deadline) {
                    usleep(5_000);
                }
            }
            self::assertFalse(\Crab\Engine\Process\PidFile::running($program['pid']));
            if (!$force) {
                self::assertFileExists($this->directory . '/aux-stopped');
            }
            if ($compound) {
                self::assertFileDoesNotExist($this->directory . '/should-not-run');
            }
        } finally {
            if (\Crab\Engine\Process\PidFile::running($program['pid'])) {
                posix_kill($program['pid'], SIGKILL);
            }
        }
    }

    /**
     * Holds a crashed supervisor's old request independently of the new
     * generation. A stopped old worker exercises the same bounded wait's
     * failure result without depending on a precise handler duration.
     */
    private function checkCrashedSupervisorStop(bool $outlives): void
    {
        $this->drainSetting(5.0);
        $this->slowVersion('one', 0.2);
        $this->observeProcesses();
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}")[0]);
        $pid = $this->pid();
        $request = $this->slowRequest();
        $worker = (int) file_get_contents($this->directory . '/in-flight');
        $processes = $this->observedProcesses($pid);
        posix_kill($processes[0]['pid'], SIGKILL);
        $command = null;
        try {
            $deadline = microtime(true) + 5.0;
            while (count($this->observedProcesses($pid)) < 2 && microtime(true) < $deadline) {
                usleep(5_000);
            }
            self::assertCount(2, $this->observedProcesses($pid));
            $deadline = microtime(true) + 3.0;
            while (!is_file($this->directory . '/draining') && microtime(true) < $deadline) {
                usleep(5_000);
            }
            self::assertFileExists($this->directory . '/draining');
            if ($outlives) {
                posix_kill($worker, SIGSTOP);
            }
            $before = microtime(true);
            [$command, $pipes] = $this->startCommand('stop', ...$outlives ? ['--timeout=0.6'] : []);
            if (!$outlives) {
                $deadline = microtime(true) + 3.0;
                while (\Crab\Engine\Process\PidFile::running($pid) && microtime(true) < $deadline) {
                    usleep(5_000);
                }
                self::assertFalse(\Crab\Engine\Process\PidFile::running($pid));
                self::assertTrue(proc_get_status($command)['running'], 'Stop must still wait for the old worker.');
                self::assertTrue(posix_kill($worker, 0));
                file_put_contents($this->directory . '/release', 'yes');
            }
            [$status, $output] = $this->finishCommand($command, $pipes);
            self::assertSame($outlives ? 1 : 0, $status, $output);
            if ($outlives) {
                self::assertStringContainsString('still has running processes', $output);
                self::assertStringContainsString("their pids are {$worker}", $output);
                self::assertLessThan(
                    2.0,
                    microtime(true) - $before,
                    'The group gets the remaining allowance, not another full wait.',
                );
            } else {
                self::assertStringContainsString('one finished', stream_get_contents($request));
                self::assertFalse(\Crab\Engine\Process\PidFile::running($worker));
                self::assertSame('', $this->drainLog());
            }
        } finally {
            posix_kill($worker, SIGKILL);
            fclose($request);
            if (is_resource($command)) {
                proc_terminate($command, SIGKILL);
                proc_close($command);
            }
        }
    }

    /**
     * Starts only this test's subprocess and keeps its handle until cleanup,
     * allowing zombie tests to deliberately defer parent collection.
     *
     * @return array{resource, array<int, resource>, int}
     */
    private function launchDrainFixture(string $role, string $directory, string ...$arguments): array
    {
        if (!is_dir($directory)) {
            mkdir($directory, recursive: true);
        }
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . '/Engine/Fixtures/drain.php',
                $role,
                dirname(__DIR__) . '/vendor/autoload.php',
                $directory,
                ...$arguments,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $pid = proc_get_status($process)['pid'];
        $this->fixtures[] = ['process' => $process, 'pipes' => $pipes, 'pid' => $pid, 'role' => $role];
        stream_set_timeout($pipes[1], 3);
        self::assertSame("ready\n", fgets($pipes[1]));
        return [$process, $pipes, $pid];
    }

    public function testForceCutsAnInFlightRequestAndCollectsEveryGenerationAndWorker(): void
    {
        $this->drainSetting(30.0);
        $this->slowVersion('one', 20.0);
        $this->observeProcesses();
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}", '--workers=2')[0]);
        $pid = $this->pid();
        $request = $this->slowRequest();
        $worker = (int) file_get_contents($this->directory . '/in-flight');
        try {
            $this->version('two');
            self::assertSame(0, $this->cua('reload', "--address={$this->address}", '--workers=2')[0]);
            $processes = $this->observedProcesses($pid);
            self::assertCount(2, $processes, 'Force must include the retiring generation too.');
            $before = microtime(true);
            [$status, $output] = $this->cua('stop', '--force');
            self::assertSame(0, $status, $output);
            self::assertLessThan(3.0, microtime(true) - $before);
            self::assertSame('', stream_get_contents($request));
            self::assertFalse(posix_kill($worker, 0), 'The request worker must be gone before force returns.');
            $this->assertProcessesGone($pid, $processes);
            self::assertSame([], glob($this->directory . '/storage/crab.pid.generation.*'));
            self::assertFileDoesNotExist($this->directory . '/storage/crab.pid.state');
        } finally {
            fclose($request);
        }
    }

    public function testForceAlsoEndsDrainingWorkersWhoseGenerationCrashed(): void
    {
        $this->drainSetting(30.0);
        $this->slowVersion('one', 20.0);
        $this->observeProcesses();
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}")[0]);
        $pid = $this->pid();
        $request = $this->slowRequest();
        try {
            $processes = $this->observedProcesses($pid);
            $worker = (int) file_get_contents($this->directory . '/in-flight');
            posix_kill($processes[0]['pid'], SIGKILL);
            $deadline = microtime(true) + 5.0;
            $log = $this->directory . '/storage/logs/server.log';
            while (
                !str_contains((string) file_get_contents($log), 'Serving again, generation pid ')
                && microtime(true) < $deadline
            ) {
                usleep(5_000);
            }
            self::assertStringContainsString('Serving again, generation pid ', (string) file_get_contents($log));
            self::assertTrue(posix_kill($worker, 0), 'The old worker still has a held request to drain.');
            $processes = $this->observedProcesses($pid);
            self::assertCount(2, $processes);
            [$status, $output] = $this->cua('stop', '--force');
            self::assertSame(0, $status, $output);
            self::assertSame('', stream_get_contents($request));
            $this->assertProcessesGone($pid, $processes);
        } finally {
            fclose($request);
        }
    }

    #[Group('slow')]
    public function testPlainStopUsesTheRunningDrainAfterConfigurationIsShortened(): void
    {
        $this->checkChangedConfigurationStop(1.0, 16.0);
    }

    public function testPlainStopAnswersTheRequestDespiteAnInvalidNewDrainSetting(): void
    {
        $this->checkChangedConfigurationStop('abc', 0.5);
    }

    /**
     * The held request must finish using the running generation's setting,
     * even though the command loads different configuration from disk.
     */
    private function checkChangedConfigurationStop(mixed $drain, float $seconds): void
    {
        $this->drainSetting(30.0);
        $this->slowVersion('one', $seconds);
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}")[0]);
        $pid = $this->pid();
        $request = $this->slowRequest();
        $this->drainSetting($drain);
        try {
            $keeper = new \Crab\Engine\Process\Keeper(
                new \Crab\Engine\Process\PidFile($this->directory . '/storage/crab.pid'),
                '',
            );
            self::assertGreaterThanOrEqual(36.0, $keeper->allowance($pid));
            [$status, $output] = $this->stopDuringRequest();
            self::assertSame(0, $status, $output);
            $response = (string) stream_get_contents($request);
            self::assertStringContainsString('200 OK', $response);
            self::assertStringEndsWith('one finished', $response);
            self::assertStringNotContainsString('WARNING', $this->drainLog());
        } finally {
            fclose($request);
        }
    }

    /**
     * Reads pids observed by this test's provider, including old workers
     * after their generation crashed. Production keeps no worker record.
     *
     * @return list<array{pid: int, workers: list<int>}>
     */
    private function observedProcesses(int $pid): array
    {
        $generations = [];
        foreach (file($this->directory . '/test-processes', FILE_IGNORE_NEW_LINES) as $line) {
            $worker = json_decode($line, true);
            if ($worker['group'] !== $pid) {
                continue;
            }
            $generation = $worker['generation'];
            $generations[$generation]['pid'] = $generation;
            $generations[$generation]['workers'][] = $worker['pid'];
        }
        return array_values($generations);
    }

    /**
     * Success from force means every generation and worker the fixture
     * observed is gone, including a worker whose supervisor crashed.
     *
     * @param list<array{pid: int, workers: list<int>}> $processes
     */
    private function assertProcessesGone(int $keeper, array $processes): void
    {
        self::assertFalse(posix_kill($keeper, 0));
        foreach ($processes as $generation) {
            foreach ([$generation['pid'], ...$generation['workers']] as $pid) {
                self::assertFalse(posix_kill($pid, 0), "Server process {$pid} remains alive after force.");
            }
        }
    }

    public function testAThrowingFailureLoggerStillLeavesTheCountAndFailureOnStderr(): void
    {
        $this->checkThrowingLogger(true);
    }

    /**
     * Exercises both the configured warning sink and the failure sink;
     * neither is allowed to prevent the worker's providers from stopping.
     */
    private function checkThrowingLogger(bool $failErrors): void
    {
        $this->drainSetting(0.1);
        $this->slowVersion('one', 2.0);
        $path = $this->directory . '/app.php';
        $source = (string) file_get_contents($path);
        $source = str_replace(
            'public function register(Crab\\Contracts\\Container\\Container $container): void {}',
            'public function register(Crab\\Contracts\\Container\\Container $container): void { $container->instance(Crab\\Contracts\\Log\\Logger::class, new ThrowingLogger()); }',
            $source,
        );
        $logger = <<<'PHP'

            /**
             * Records attempted messages before refusing the configured sink.
             */
            final class ThrowingLogger extends Psr\Log\AbstractLogger implements Crab\Contracts\Log\Logger
            {
                public function log($level, string|Stringable $message, array $context = []): void
                {
                    $message = strtr((string) $message, ['{message}' => $context['message'] ?? '']);
                    file_put_contents(__DIR__ . '/logger-calls', $level . ': ' . $message . "\n", FILE_APPEND);
                    if ($level === 'warning' || (FAIL_ERRORS && $level === 'error')) {
                        throw new RuntimeException($level . ' sink failed');
                    }
                }
            }

            PHP;
        $logger = str_replace('FAIL_ERRORS', var_export($failErrors, true), $logger);
        file_put_contents($path, str_replace('<?php', '<?php' . $logger, $source));
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}")[0]);
        $request = $this->slowRequest();
        try {
            [$status, $output] = $this->stopDuringRequest();
            self::assertSame(0, $status, $output);
            self::assertSame('', stream_get_contents($request));
            $stderr = (string) file_get_contents($this->directory . '/storage/logs/server.log');
            self::assertSame(1, substr_count($stderr, 'WARNING HTTP worker '), $stderr);
            self::assertMatchesRegularExpression(
                '/WARNING HTTP worker \d+ reached server\.drain; cut 1 connections\./',
                $stderr,
            );
            $calls = (string) file_get_contents($this->directory . '/logger-calls');
            self::assertSame(1, substr_count($calls, 'warning: HTTP worker '));
            self::assertSame(1, substr_count($calls, 'error: Unhandled failure: warning sink failed'), $calls);
            if ($failErrors) {
                self::assertStringContainsString('HTTP drain warning logger failed', $stderr);
                self::assertStringContainsString('warning sink failed', $stderr);
                self::assertStringContainsString('error sink failed', $stderr);
            }
            self::assertFileExists($this->directory . '/providers-stopped');
        } finally {
            fclose($request);
        }
    }

    /**
     * Gives the application's server a drain setting and a separate log
     * whose warning count is unaffected by the keeper's process messages.
     */
    private function drainSetting(mixed $drain): void
    {
        if (!is_dir($this->directory . '/config')) {
            mkdir($this->directory . '/config');
        }
        file_put_contents(
            $this->directory . '/config/server.php',
            '<?php return ' . var_export(['drain' => $drain], true) . ';',
        );
        file_put_contents($this->directory . '/config/log.php', "<?php return ['path' => 'storage/drain.log'];");
    }

    /**
     * Holds the handler until its worker observes draining. Releasing it
     * then measures request work inside the drain, independent of CLI startup.
     */
    private function slowVersion(string $version, float $seconds): void
    {
        $this->observeDrain();
        $this->version($version);
        file_put_contents(
            $this->directory . '/app/routes.php',
            "\nCrab\\Route::get('/slow', static function (): Psr\\Http\\Message\\ResponseInterface {"
            . 'file_put_contents(dirname(__DIR__) . \'/in-flight\', (string) getmypid());'
            . '$worker = Crab\\App::current()->container->get(Crab\\Engine\\Process\\Worker::class);'
            . 'while (!is_file(dirname(__DIR__) . \'/release\')) {'
            . 'if ($worker->isDraining()) { file_put_contents(dirname(__DIR__) . \'/draining\', \'yes\'); }'
            . 'Crab\\Engine\\Async\\Async::sleep($worker->loop, 0.005); }'
            . 'Crab\\Engine\\Async\\Async::sleep(Crab\\Engine\\EventLoop::current(), '
            . var_export($seconds, true)
            . ');'
            . 'return Crab\\Http\\Response::text('
            . var_export($version . ' finished', true)
            . ');'
            . '});',
            FILE_APPEND,
        );
    }

    /**
     * @return resource A request left waiting for its response.
     */
    private function slowRequest(): mixed
    {
        if (is_file($this->directory . '/in-flight')) {
            unlink($this->directory . '/in-flight');
        }
        $request = stream_socket_client($this->address, $code, $message, 1);
        self::assertIsResource($request);
        stream_set_timeout($request, 30);
        fwrite($request, "GET /slow HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
        $deadline = microtime(true) + 3.0;
        while (!is_file($this->directory . '/in-flight') && microtime(true) < $deadline) {
            usleep(5_000);
        }
        self::assertFileExists($this->directory . '/in-flight');
        return $request;
    }

    /**
     * A graceful drain writes no warning and need not create a log file.
     */
    private function drainLog(): string
    {
        $log = $this->directory . '/storage/drain.log';
        return is_file($log) ? (string) file_get_contents($log) : '';
    }

    public function testAReloadBringsNewCodeWithoutRefusingAConnection(): void
    {
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}", '--workers=2')[0]);
        $pid = $this->pid();

        // Requests keep arriving, each on a new connection, while the server is replaced.
        $load = proc_open([PHP_BINARY, '-r', <<<'PHP'
            $deadline = microtime(true) + 20.0;
            $ready = fopen('php://fd/3', 'w');
            $seen = []; $failed = 0;
            while (!isset($seen['two']) && microtime(true) < $deadline) {
                $c = @stream_socket_client($argv[1], $e, $m, 2);
                if ($c === false) { $failed++; continue; }
                stream_set_timeout($c, 5);
                fwrite($c, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
                $r = (string) stream_get_contents($c);
                fclose($c);
                if (!str_contains($r, '200 OK')) { $failed++; continue; }
                $body = substr($r, strrpos($r, "\r\n\r\n") + 4);
                $seen[$body] = ($seen[$body] ?? 0) + 1;
                if ($body === 'one' && $seen[$body] === 1) {
                    fwrite($ready, "one\n");
                }
            }
            echo json_encode(['failed' => $failed, 'seen' => $seen]);
            PHP, $this->address], [1 => ['pipe', 'w'], 3 => ['pipe', 'w']], $pipes);
        self::assertIsResource($load);
        try {
            stream_set_timeout($pipes[3], 25);
            self::assertSame(
                "one\n",
                fgets($pipes[3]),
                'The load client did not observe the old generation within twenty seconds.',
            );
            $this->version('two');
            [$status, $output] = $this->cua('reload', "--address={$this->address}", '--workers=2');
            self::assertSame(0, $status, $output);
            self::assertSame($pid, $this->pid(), 'The server keeps its process id across a reload.');
            self::assertMatchesRegularExpression(
                "~^Reloaded the server, pid {$pid}, on {$this->url} with 2 workers\\. It now runs the code as it is\\.\nProcess (\\d+) took over from process (\\d+), which finishes the requests it has and stops\\.\n$~",
                $output,
            );
            preg_match('~Process (\d+) took over from process (\d+)~', $output, $processes);
            [, $new, $old] = array_map(intval(...), $processes);
            self::assertSame('two', $this->get());

            stream_set_timeout($pipes[1], 25);
            $result = json_decode((string) stream_get_contents($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0, proc_close($load), 'The load client did not exit successfully.');
        } finally {
            if (is_resource($load)) {
                proc_terminate($load);
                proc_close($load);
            }
            foreach ($pipes as $pipe) {
                if (!is_resource($pipe)) {
                    continue;
                }
                fclose($pipe);
            }
        }
        self::assertSame(0, $result['failed'], 'No request was refused or cut short during the reload.');
        self::assertGreaterThan(0, $result['seen']['one'] ?? 0, 'The old process served until the new one was ready.');
        self::assertGreaterThan(
            0,
            $result['seen']['two'] ?? 0,
            'The load client did not observe the new generation within twenty seconds.',
        );
        for ($waited = 0; $waited < 200 && posix_kill($old, 0); ++$waited) {
            usleep(50_000);
        }
        self::assertFalse(posix_kill($old, 0), 'The old process has gone.');
        self::assertTrue(posix_kill($new, 0));
        self::assertStringContainsString(
            "generation pid {$new}, taking over from {$old}.",
            (string) file_get_contents($this->directory . '/storage/logs/server.log'),
        );
        self::assertSame([], glob($this->directory . '/storage/crab.pid.reload*'), 'Nothing is left of the request.');

        // A server process that dies is started again, on the socket that never closed.
        posix_kill($new, SIGKILL);
        $log = $this->directory . '/storage/logs/server.log';
        for (
            $waited = 0;
            $waited < 200 && !str_contains((string) file_get_contents($log), 'Serving again, generation pid ');
            ++$waited
        ) {
            usleep(50_000);
        }
        self::assertStringContainsString("Generation pid {$new} ended by itself", (string) file_get_contents($log));
        self::assertStringContainsString('Serving again, generation pid ', (string) file_get_contents($log));
        self::assertSame('two', $this->get(retry: true));
        self::assertSame([0, $pid], [$this->cua('status')[0], $this->pid()]);
    }

    public function testAReloadThatCannotStartLeavesTheOldServerServing(): void
    {
        self::assertSame(0, $this->cua('start', '--daemon', "--address={$this->address}")[0]);
        $pid = $this->pid();

        // Wrong where the application is described: the command itself cannot load it.
        file_put_contents($this->directory . '/app/routes.php', '<?php this is not PHP');
        [$status] = $this->cua('reload', "--address={$this->address}");
        self::assertNotSame(0, $status);
        self::assertSame([$pid, 'one'], [$this->pid(), $this->get()]);

        // Wrong only once a worker starts: the new server is made, fails, and reports why.
        file_put_contents(
            $this->directory . '/app.php',
            "<?php\nreturn Crab\\App::configure(__DIR__)->withMiddleware('App\\\\Missing\\\\Middleware')->withRouting(routes: 'app/routes.php')->create();\n",
        );
        $this->version('two');
        [$status, $output] = $this->cua('reload', "--address={$this->address}");
        self::assertSame(1, $status);
        self::assertStringStartsWith('The server was not reloaded, and goes on serving as it was: ', $output);
        self::assertStringContainsString(
            'App\Missing\Middleware has no binding',
            $output,
            'The first thing that went wrong is shown, not only where the log is.',
        );
        self::assertStringContainsString("Its output is in {$this->directory}/storage/logs/server.log.", $output);
        self::assertSame([$pid, 'one'], [$this->pid(), $this->get()], 'The server that was running was not touched.');
        self::assertSame(0, $this->cua('status')[0]);
    }

    public function testARestartStopsAndStartsAndForceEndsOneThatWillNotStop(): void
    {
        $this->observeProcesses();
        [$status, $output] = $this->cua('restart', "--address={$this->address}");
        self::assertSame(0, $status, 'With nothing running, a restart is a start.');
        self::assertSame("Crab server running on {$this->url}\n", $output);
        $first = $this->pid();
        $this->version('two');
        [$status, $output] = $this->cua('restart', "--address={$this->address}", '--workers=2');
        self::assertSame(0, $status, $output);
        $second = $this->pid();
        self::assertSame("Stopped the server, pid {$first}.\nCrab server running on {$this->url}\n", $output);
        self::assertNotSame($first, $second);
        self::assertSame('two', $this->get());

        // A server that ignores being asked: stopped in its tracks, so it cannot answer the signal.
        $processes = $this->observedProcesses($second);
        posix_kill($second, SIGSTOP);
        [$status, $output] = $this->cua('stop', '--timeout=0.3');
        self::assertSame(1, $status);
        self::assertStringContainsString("pid {$second}, has not stopped after 0.3 seconds", $output);
        self::assertStringContainsString('`stop --force` ends it outright', $output);
        self::assertSame([0, "Stopped the server, pid {$second}.\n"], $this->cua('stop', '--timeout=0.3', '--force'));
        self::assertFalse(posix_kill($second, 0));
        $this->assertProcessesGone($second, $processes);
        self::assertFileDoesNotExist($this->directory . '/storage/crab.pid', 'Its file is removed for it.');
        // Force has already collected the server's workers and generations.
        for ($waited = 0; $waited < 200 && $this->get() !== null; ++$waited) {
            usleep(50_000);
        }
        self::assertNull($this->get());
    }

    public function testTheWatcherRestartsTheServerWhenAFileChanges(): void
    {
        $this->watcher = proc_open(
            [PHP_BINARY, $this->directory . '/cua', 'dev', "--address={$this->address}", '--single'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
        );
        stream_set_blocking($pipes[1], false);
        $output = '';
        $everything = '';
        $until = static function (string $text) use (&$output, &$everything, $pipes): void {
            $deadline = microtime(true) + 10;
            while (!str_contains($output, $text)) {
                self::assertLessThan(
                    $deadline,
                    microtime(true),
                    "The watcher never said \"{$text}\". It said:\n{$output}",
                );
                $read = (string) fread($pipes[1], 8192);
                $output .= $read;
                $everything .= $read;
                usleep(20_000);
            }
            $output = substr($output, strpos($output, $text) + strlen($text));
        };

        $banner = "Starting Crab server on {$this->url}\nPress Ctrl+C to stop\n";
        $until($banner);
        self::assertSame('one', $this->get(retry: true));

        $this->version('two');
        $until("Restarted: app/routes.php changed.\n");
        self::assertSame('two', $this->get(retry: true));
        $said = $banner . "Restarted: app/routes.php changed.\n";

        file_put_contents($this->directory . '/app/routes.php', '<?php this is not PHP');
        $until("Restarted: app/routes.php changed.\n");
        $until("The server stopped (status 255). Save a file to start it again.\n");
        self::assertNull($this->get());

        $this->version('three');
        file_put_contents($this->directory . '/app/Added.php', '<?php // a new file is a change too');
        $until(' changed.');
        self::assertSame('three', $this->get(retry: true));
        self::assertStringStartsWith(
            $said,
            $everything,
            'It says where it serves once, and after that only what happened.',
        );
        self::assertSame(1, substr_count($everything, $this->url), 'Not again at every restart.');
        self::assertStringNotContainsString('tcp://', $everything);

        proc_terminate($this->watcher, SIGINT);
        for ($waited = 0; $waited < 200 && proc_get_status($this->watcher)['running']; ++$waited) {
            usleep(50_000);
        }
        self::assertFalse(proc_get_status($this->watcher)['running'], 'The watcher ends when it is told to.');
        proc_close($this->watcher);
        $this->watcher = null;
        self::assertNull($this->get(), 'And takes the server with it.');
    }

    /**
     * Makes the application answer "/" with this text.
     */
    public function testALargeUploadIsTakenUpToTheSizeAllowedAndTheApplicationSaysItIsUp(): void
    {
        mkdir($this->directory . '/config');
        file_put_contents($this->directory . '/config/server.php', "<?php\nreturn ['max_body' => 24 * 1024 * 1024];\n");
        file_put_contents(
            $this->directory . '/app.php',
            "<?php\nreturn Crab\\App::configure(__DIR__)->withRouting(routes: 'app/routes.php', health: '/up')->create();\n",
        );
        file_put_contents($this->directory . '/app/routes.php', <<<'PHP'
            <?php
            Crab\Route::post('/upload', static function (Psr\Http\Message\ServerRequestInterface $request): array {
                $before = memory_get_usage();
                memory_reset_peak_usage();
                $file = $request->getUploadedFiles()['file'];
                $target = dirname(__DIR__) . '/stored.bin';
                $file->moveTo($target);
                return [
                    'title' => $request->getParsedBody()['title'],
                    'name' => $file->getClientFilename(),
                    'size' => filesize($target),
                    'hash' => md5_file($target),
                    'memory' => memory_get_peak_usage() - $before,
                ];
            });
            // Calls the server it is served by, which has one worker: only a client that does not hold the worker gets an answer.
            Crab\Route::get('/call', static function (Psr\Http\Message\ServerRequestInterface $request): array {
                $http = new Crab\HttpClient\Client('http://' . $request->getHeaderLine('Host'), timeout: 3.0);
                try {
                    return ['called' => $http->get('/up')->json()];
                } finally {
                    $http->close();
                }
            });
            PHP);
        [$status, $output] = $this->cua('start', '--daemon', "--address={$this->address}");
        self::assertSame(0, $status, $output);

        $http = new \Crab\HttpClient\Client($this->url);
        try {
            $up = $http->get('/up');
            self::assertSame([200, ['status' => 'up']], [$up->getStatusCode(), $up->json()]);
            self::assertSame(
                ['called' => ['status' => 'up']],
                $http->get('/call')->json(),
                'A handler called its own worker and was answered.',
            );

            $content = random_bytes(1024 * 1024);
            $content = str_repeat($content, 16);
            $upload = static fn(string $content): \Crab\Contracts\HttpClient\Response => $http->request(
                'POST',
                '/upload',
                headers: ['Content-Type' => 'multipart/form-data; boundary=B'],
                body: "--B\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nBackup\r\n"
                . "--B\r\nContent-Disposition: form-data; name=\"file\"; filename=\"backup.bin\"\r\nContent-Type: application/octet-stream\r\n\r\n"
                . $content
                . "\r\n--B--\r\n",
            );
            $stored = $upload($content);
            self::assertSame(200, $stored->getStatusCode(), (string) $stored->getBody());
            self::assertSame(
                ['Backup', 'backup.bin', strlen($content), md5($content)],
                array_slice(array_values($stored->json()), 0, 4),
            );
            self::assertLessThan(
                2 * 1024 * 1024,
                $stored->json()['memory'],
                'A 16 MiB file was stored without being held in memory.',
            );

            self::assertSame(
                413,
                $upload(str_repeat($content, 2))->getStatusCode(),
                'A body over the size allowed is refused.',
            );
        } finally {
            $http->close();
            \Crab\Engine\EventLoop::current()->run();
        }
    }

    private function version(string $text): void
    {
        file_put_contents(
            $this->directory . '/app/routes.php',
            "<?php\nCrab\\Route::get('/', static fn (): string => '{$text}');\n",
        );
    }

    /**
     * Runs a command of the application.
     *
     * @return array{int, string} Its exit status, and what it wrote.
     */
    private function cua(string ...$arguments): array
    {
        [$process, $pipes] = $this->startCommand(...$arguments);
        return $this->finishCommand($process, $pipes);
    }

    /**
     * Starts a command without waiting, so a request can observe its stop signal.
     *
     * @return array{resource, array<int, resource>}
     */
    private function startCommand(string ...$arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, $this->directory . '/cua', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
        );
        self::assertIsResource($process);
        return [$process, $pipes];
    }

    /**
     * Collects and reaps only the command started by this test.
     *
     * @param resource $process
     * @param array<int, resource> $pipes
     * @return array{int, string}
     */
    private function finishCommand(mixed $process, array $pipes): array
    {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }

    /**
     * Exposes the current worker to the fixture and records provider cleanup.
     */
    private function observeDrain(): void
    {
        $path = $this->directory . '/app.php';
        $source = (string) file_get_contents($path);
        if (str_contains($source, 'final class DrainObserver')) {
            return;
        }
        $provider = <<<'PHP'

            /**
             * A fixture barrier and a record that worker providers were stopped.
             */
            final class DrainObserver implements Crab\Contracts\WorkerProvider
            {
                public function register(Crab\Contracts\Container\Container $container): void {}
                public function start(Crab\Engine\Process\Worker $worker, Crab\Contracts\Container\Container $container): void
                {
                    $container->instance(Crab\Engine\Process\Worker::class, $worker);
                }
                public function stop(Crab\Engine\Process\Worker $worker, Crab\Contracts\Container\Container $container): void
                {
                    file_put_contents(__DIR__ . '/providers-stopped', 'yes');
                }
            }

            PHP;
        $source = str_replace('<?php', '<?php' . $provider, $source);
        $source = str_replace('->create()', '->withProviders([DrainObserver::class])->create()', $source);
        file_put_contents($path, $source);
    }

    /**
     * Observes only this fixture's worker pids, group membership and streams.
     * These observations are never read by the server's shutdown code.
     */
    private function observeProcesses(): void
    {
        $path = $this->directory . '/app.php';
        $source = (string) file_get_contents($path);
        $provider = <<<'PHP'

            /**
             * Reports worker ownership and inherited streams to its test.
             */
            final class ProcessObserver implements Crab\Contracts\WorkerProvider
            {
                public function register(Crab\Contracts\Container\Container $container): void {}
                public function start(Crab\Engine\Process\Worker $worker, Crab\Contracts\Container\Container $container): void
                {
                    $streams = [];
                    foreach (get_resources('stream') as $stream) {
                        $streams[] = stream_get_meta_data($stream)['uri'] ?? '';
                    }
                    $observation = ['pid' => getmypid(), 'generation' => posix_getppid(),
                        'group' => posix_getpgid(0), 'session' => posix_getsid(0), 'streams' => $streams];
                    file_put_contents(__DIR__ . '/test-processes', json_encode($observation) . "\n", FILE_APPEND | LOCK_EX);
                }
                public function stop(Crab\Engine\Process\Worker $worker, Crab\Contracts\Container\Container $container): void {}
            }

            PHP;
        $source = str_replace('<?php', '<?php' . $provider, $source);
        $source = str_replace('->create()', '->withProviders([ProcessObserver::class])->create()', $source);
        file_put_contents($path, $source);
    }

    /**
     * Opens the release barrier only after the old worker is draining.
     */
    private function releaseRequest(): void
    {
        $deadline = microtime(true) + 10.0;
        while (!is_file($this->directory . '/draining') && microtime(true) < $deadline) {
            usleep(5_000);
        }
        self::assertFileExists($this->directory . '/draining');
        file_put_contents($this->directory . '/release', 'yes');
    }

    /**
     * Stops concurrently with the held request and always reaps the CLI.
     *
     * @return array{int, string}
     */
    private function stopDuringRequest(string ...$arguments): array
    {
        [$process, $pipes] = $this->startCommand(...$arguments === [] ? ['stop'] : $arguments);
        try {
            $this->releaseRequest();
        } finally {
            file_put_contents($this->directory . '/release', 'yes');
            $result = $this->finishCommand($process, $pipes);
        }
        return $result;
    }

    private function pid(): int
    {
        return (int) file_get_contents($this->directory . '/storage/crab.pid');
    }

    /**
     * @param bool $retry Whether to wait for a server that is just starting.
     * @return string|null The body of "/", or null when nothing answers.
     */
    private function get(bool $retry = false): ?string
    {
        for ($attempt = 0; $attempt < ($retry ? 100 : 1); ++$attempt) {
            $connection = @stream_socket_client($this->address, $errno, $error, 1);
            if ($connection !== false) {
                stream_set_timeout($connection, 5);
                fwrite($connection, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
                $response = (string) stream_get_contents($connection);
                fclose($connection);
                return substr($response, strrpos($response, "\r\n\r\n") + 4);
            }
            usleep(50_000);
        }
        return null;
    }

    public function testTheAddressIsShownAsSomethingToOpen(): void
    {
        self::assertSame('http://127.0.0.1:8080', \Crab\Server::describe('tcp://127.0.0.1:8080'));
        self::assertSame('http://127.0.0.1:8080', \Crab\Server::describe('tcp://127.0.0.1:8080', 1));
        self::assertSame('http://127.0.0.1:8080 with 4 workers', \Crab\Server::describe('tcp://127.0.0.1:8080', 4));
        self::assertSame(
            'http://localhost:9000',
            \Crab\Server::describe('tcp://0.0.0.0:9000'),
            'Every interface is not an address to open.',
        );
        self::assertSame('http://localhost:9000 with 2 workers', \Crab\Server::describe('tcp://[::]:9000', 2));
        self::assertSame('http://api.test:80', \Crab\Server::describe('tcp://api.test:80'));
    }
}
