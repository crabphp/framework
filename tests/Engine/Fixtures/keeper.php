<?php

declare(strict_types=1);

/**
 * A service for KeeperTest, without the framework:
 *
 *     keeper.php keep <autoload> <pid file> <log> <address> <text> [held]  the keeper, in the foreground; held names what it keeps across reloads, with commas
 *     keeper.php serve <autoload> <text> [fail]                             a generation, as the keeper starts it
 *     keeper.php hold <autoload>                                            the held generation, which serves nothing
 *     keeper.php replace <autoload> <pid file> <address> <text> [fail]      asks the keeper for a new generation; KEEPER_HELD names what the new code keeps
 */

use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Process\Generation;
use Crab\Engine\Process\Keeper;
use Crab\Engine\Process\PidFile;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;
use Crab\Engine\Server\HttpServer;

require $argv[2];

if ($argv[1] === 'keep') {
    [, , , $pid, $log, $address, $text] = $argv;
    $held = isset($argv[7]) ? ['command' => [__FILE__, 'hold', $argv[2]], 'names' => explode(',', $argv[7])] : null;
    $keeper = new Keeper(new PidFile($pid), $log, startTimeout: 5.0, stopTimeout: 3.0);
    try {
        exit($keeper->run(
            $address,
            [__FILE__, 'serve', $argv[2], $text],
            getcwd(),
            static fn() => fwrite(STDOUT, "ready\n"),
            $held,
        ));
    } catch (Throwable $error) {
        fwrite(STDOUT, 'failed: ' . $error->getMessage() . "\n");
        exit(1);
    }
}

if ($argv[1] === 'replace') {
    [, , , $pid, $address, $text] = $argv;
    $file = new PidFile($pid);
    $command = [__FILE__, 'serve', $argv[2], $text, ...array_slice($argv, 6)];
    $held = (string) getenv('KEEPER_HELD');
    echo
        json_encode(Keeper::request(
            $file,
            (int) $file->pid(),
            $address,
            $command,
            getcwd(),
            10.0,
            held: $held === '' ? [] : explode(',', $held),
        )),
        "\n";
    exit(0);
}

if ($argv[1] === 'hold') {
    $generation = Generation::inherit();
    $runtime = new Runtime(shutdownTimeout: 2.0);
    $runtime
        ->group('held', reloads: false)
        ->onStart(static function (Worker $worker): void {
            file_put_contents('held', posix_getppid() . ' ' . getmypid() . "\n", FILE_APPEND | LOCK_EX);
            // Something to wait for, as an agent waits for its work.
            $worker->loop->repeat(1.0, static fn() => null);
        });
    $generation->attach($runtime);
    $runtime->run();
    exit(0);
}

$text = $argv[3];
$generation = Generation::inherit();
try {
    if (($argv[4] ?? '') === 'fail') {
        throw new RuntimeException('this generation was told to fail');
    }
    $runtime = new Runtime(shutdownTimeout: 2.0);
    $runtime
        ->group('web', workers: 2)
        ->listen(
            'tcp://inherited',
            new HttpServer(static fn(Request $request) => Response::text($text . ' from ' . posix_getppid())),
            socket: $generation->socket,
        )
        ->onStart(static function (): void {
            file_put_contents('workers', posix_getppid() . ' ' . getmypid() . "\n", FILE_APPEND | LOCK_EX);
        });
    $generation->attach($runtime);
    $runtime->run();
} catch (Throwable $error) {
    $generation->fail($error->getMessage());
    exit(1);
}
