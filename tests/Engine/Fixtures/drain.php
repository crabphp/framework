<?php

declare(strict_types=1);

namespace Crab\Engine\Process {
    /**
     * The guard fixture records requests without sending any signal,
     * including if the pid-one guard is accidentally removed later.
     */
    function posix_kill(int $pid, int $signal): bool
    {
        if (isset($GLOBALS['guardSignals'])) {
            $GLOBALS['guardSignals'][] = [$pid, $signal];
            return false;
        }
        return \posix_kill($pid, $signal);
    }

    /**
     * Holds a real child after proc_open, before the keeper can publish it.
     * Only the fork regression's replacement command uses this barrier.
     */
    function proc_open(
        array|string $command,
        array $descriptors,
        mixed &$pipes,
        ?string $directory = null,
        ?array $environment = null,
        ?array $options = null,
    ): mixed {
        $process = \proc_open($command, $descriptors, $pipes, $directory, $environment, $options);
        if (is_resource($process) && is_array($command) && ($command[2] ?? null) === 'supervise-new') {
            $path = $command[4];
            file_put_contents($path . '/generation-forked', (string) proc_get_status($process)['pid']);
            while (!is_file($path . '/release-fork')) {
                usleep(10_000);
            }
        }
        return $process;
    }

    /**
     * Holds a real worker fork before its parent registers that worker.
     */
    function pcntl_fork(): int
    {
        $pid = \pcntl_fork();
        $path = $GLOBALS['workerForkBarrier'] ?? null;
        if ($pid > 0 && $path !== null && is_file($path . '/hold-worker-fork')) {
            file_put_contents($path . '/worker-forked', (string) $pid);
            while (!is_file($path . '/release-fork')) {
                usleep(10_000);
            }
        }
        return $pid;
    }
}

namespace {
    /**
     * A keeper with generations that take 0.3 seconds to stop. Short outer
     * allowances would kill them before they can write their completion file.
     */

    use Crab\Engine\Network\Connection;
    use Crab\Engine\Process\Generation;
    use Crab\Engine\Process\Keeper;
    use Crab\Engine\Process\PidFile;
    use Crab\Engine\Runtime;

    require $argv[2];

    if ($argv[1] === 'guard') {
        $GLOBALS['guardSignals'] = [];
        $errors = [];
        $keeper = new Keeper(new PidFile($argv[3] . '/service.pid'), $argv[3] . '/service.log');
        foreach ([1, 0, -1] as $pid) {
            try {
                $keeper->force($pid);
            } catch (RuntimeException $error) {
                $errors[] = $error->getMessage();
            }
            try {
                $keeper->waitForGroup($pid, 0.0);
            } catch (InvalidArgumentException $error) {
                $errors[] = $error->getMessage();
            }
        }
        PidFile::alive(-1);
        PidFile::alive(0);
        echo json_encode(['errors' => $errors, 'signals' => $GLOBALS['guardSignals']]) . "\n";
        exit(0);
    }

    if ($argv[1] === 'force') {
        try {
            new Keeper(new PidFile($argv[3] . '/service.pid'), $argv[3] . '/service.log')->force((int) $argv[4]);
            echo "forced\n";
            exit(0);
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            exit(1);
        }
    }

    if (in_array($argv[1], ['keep-fork', 'keep-pipeline'], true)) {
        // Isolate only this test's process group, so fallback cannot touch its runner.
        if ($argv[1] === 'keep-pipeline') {
            if (!posix_setpgid(0, 0)) {
                throw new RuntimeException('Could not create the pipeline fixture group.');
            }
        } elseif (posix_setsid() === -1) {
            throw new RuntimeException('Could not isolate the fork fixture process group.');
        }
        $keeper = new Keeper(new PidFile($argv[3] . '/service.pid'), $argv[3] . '/service.log', stopTimeout: 35.0);
        exit($keeper->run(
            $argv[4],
            [__FILE__, 'supervise', $argv[2], $argv[3], $argv[4]],
            $argv[3],
            static fn() => print "ready\n",
        ));
    }

    if (in_array($argv[1], ['unresponsive', 'unresponsive-session'], true)) {
        if ($argv[1] === 'unresponsive-session') {
            posix_setsid();
        } elseif (isset($argv[5]) && ctype_digit($argv[5])) {
            posix_setpgid(0, (int) $argv[5]);
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGQUIT, SIG_IGN);
        new PidFile($argv[3] . '/service.pid')->write();
        echo "ready\n";
        while (true) {
            usleep(10_000);
        }
    }

    if ($argv[1] === 'keep') {
        $keeper = new Keeper(
            new PidFile($argv[3] . '/service.pid'),
            $argv[3] . '/service.log',
            startTimeout: 2.0,
            stopTimeout: (float) ($argv[5] ?? 1.0),
        );
        exit($keeper->run(
            $argv[4],
            [__FILE__, 'serve', $argv[2], $argv[3], 'first'],
            $argv[3],
            static fn() => print "ready\n",
        ));
    }

    $generation = Generation::inherit();
    if (in_array($argv[1], ['supervise', 'supervise-new'], true)) {
        $GLOBALS['workerForkBarrier'] = $argv[3];
        $runtime = new Runtime(shutdownTimeout: 30.0);
        $runtime
            ->group()
            ->listen(
                $argv[4],
                static function (Connection $connection): void {
                    $connection->end();
                },
                socket: $generation->socket,
            )
            ->onStart(static function () use ($argv): void {
                file_put_contents(
                    $argv[3] . '/observed-workers',
                    json_encode([
                        'pid' => getmypid(),
                        'generation' => posix_getppid(),
                        'group' => posix_getpgid(0),
                    ])
                        . "\n",
                    FILE_APPEND | LOCK_EX,
                );
            });
        $generation->attach($runtime);
        $runtime->run();
        exit(0);
    }
    if (in_array($argv[1], ['hang', 'fail'], true)) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, SIG_IGN);
        file_put_contents($argv[3] . '/' . $argv[4] . '.started', (string) getmypid());
        if ($argv[1] === 'fail') {
            $generation->fail('This fixture refuses to start.');
        }
        while (true) {
            usleep(10_000);
        }
    }
    $runtime = new Runtime(shutdownTimeout: 0.5);
    $runtime
        ->group()
        ->onStop(static function () use ($argv): void {
            file_put_contents($argv[3] . '/' . $argv[4] . '.stopping', (string) microtime(true));
            $deadline = microtime(true) + 0.3;
            while (microtime(true) < $deadline) {
                usleep(10_000);
            }
            file_put_contents($argv[3] . '/' . $argv[4] . '.finished', 'yes');
        });
    $generation->attach($runtime);
    $runtime->run(singleProcess: true);
}
