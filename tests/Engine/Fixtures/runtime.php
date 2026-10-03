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
$runtime->group('echo', workers: $mode === 'single' ? 1 : 2, maxConnections: 8)
    ->listen("tcp://127.0.0.1:$port", static function (Connection $connection): void {
        $connection->onData(static function (Connection $connection, string $bytes): void {
            $connection->tryWrite(getmypid() . ':' . $bytes);
            if ($bytes === "block\n") {
                while (true) {
                    usleep(100000);
                }
            }
        });
    })
    ->onStart(static function (Worker $worker) use ($mode): void {
        if ($mode === 'fail') {
            throw new RuntimeException('Deliberate startup failure');
        }
        if ($mode === 'startup-stop' && $worker->index === 0) {
            posix_kill(posix_getppid(), SIGTERM);
        }
        fwrite(STDOUT, json_encode(['pid' => getmypid(), 'index' => $worker->index]) . "\n");
        fflush(STDOUT);
        if ($mode === 'single') {
            $worker->loop->delay(0.15, $worker->stop(...));
        }
    });
$runtime->run(singleProcess: $mode === 'single');
