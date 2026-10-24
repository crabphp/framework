<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Crab\Engine\Network\Connection;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;

if (function_exists('posix_setsid') && posix_setsid() === -1) {
    throw new RuntimeException('Could not isolate the stats fixture process group.');
}

// A runtime that keeps a stats file: each line a worker is sent is counted as a "message".
[, $mode, $port, $directory] = $argv;
$single = $mode === 'single';
$runtime = new Runtime(shutdownTimeout: 0.3);
$current = null;
$runtime
    ->group('echo', workers: $single ? 1 : 2, maxConnections: 8)
    ->listen("tcp://127.0.0.1:{$port}", static function (Connection $connection) use (&$current): void {
        $connection->onData(static function (Connection $connection, string $bytes) use (&$current): void {
            $current?->count('messages', substr_count($bytes, "\n"));
            $connection->tryWrite(getmypid() . "\n");
        });
    })
    ->onStart(static function (Worker $worker) use (&$current): void {
        $current = $worker;
    });
$runtime->stats($directory, ['address' => "tcp://127.0.0.1:{$port}", 'mode' => $mode]);
$runtime->run(singleProcess: $single);
