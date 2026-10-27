<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Crab\Engine\Network\Connection;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;

if (function_exists('posix_setsid') && posix_setsid() === -1) {
    throw new RuntimeException('Could not isolate the runtime fixture process group.');
}

$mode = $argv[1];
$port = (int) $argv[2];
$runtime = new Runtime(shutdownTimeout: 0.3);
$current = null;
$runtime
    ->group('echo', workers: $mode === 'single' ? 1 : 2, maxConnections: 8)
    ->listen("tcp://127.0.0.1:{$port}", static function (Connection $connection) use (
        $mode,
        $runtime,
        &$current,
    ): void {
        $connection->onData(static function (Connection $connection, string $bytes) use (
            $mode,
            $runtime,
            &$current,
        ): void {
            $connection->tryWrite(getmypid() . ':' . $bytes);
            if ($mode === 'single') {
                $connection->loop()->delay(0.0, $runtime->stop(...));
            }
            if ($bytes === "deadline\n") {
                // Set, then blocked past it: only the supervisor can end this.
                $current->deadline(0.2);
            }
            if ($bytes === "block\n" || $bytes === "deadline\n") {
                while (true) {
                    usleep(100_000);
                }
            }
        });
    })
    ->onStart(static function (Worker $worker) use ($mode, &$current): void {
        $current = $worker;
        if ($mode === 'fail') {
            throw new RuntimeException('Deliberate startup failure');
        }
        if ($mode === 'startup-stop' && $worker->index === 0) {
            posix_kill(posix_getppid(), SIGTERM);
        }
        fwrite(STDOUT, json_encode(['pid' => getmypid(), 'index' => $worker->index]) . "\n");
        fflush(STDOUT);
        if ($mode === 'single') {
            $worker->loop->delay(
                5.0,
                static fn() => throw new RuntimeException('The single-process fixture received no request.'),
            );
        }
    });
$runtime->run(singleProcess: $mode === 'single');
