<?php

declare(strict_types=1);

/**
 * A service for KeeperTest, without the framework:
 *
 *     keeper.php keep <autoload> <pid file> <log> <address> <text>     the keeper, in the foreground
 *     keeper.php serve <autoload> <text> [fail]                        a generation, as the keeper starts it
 *     keeper.php replace <autoload> <pid file> <address> <text> [fail] asks the keeper for a new generation
 */

use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Process\Generation;
use Crab\Engine\Process\Keeper;
use Crab\Engine\Process\PidFile;
use Crab\Engine\Runtime;
use Crab\Engine\Server\HttpServer;

require $argv[2];

if ($argv[1] === 'keep') {
    [, , , $pid, $log, $address, $text] = $argv;
    $keeper = new Keeper(new PidFile($pid), $log, startTimeout: 5.0, stopTimeout: 3.0);
    try {
        exit($keeper->run($address, [__FILE__, 'serve', $argv[2], $text], getcwd(), static fn() => fwrite(
            STDOUT,
            "ready\n",
        )));
    } catch (Throwable $error) {
        fwrite(STDOUT, 'failed: ' . $error->getMessage() . "\n");
        exit(1);
    }
}

if ($argv[1] === 'replace') {
    [, , , $pid, $address, $text] = $argv;
    $file = new PidFile($pid);
    $command = [__FILE__, 'serve', $argv[2], $text, ...array_slice($argv, 6)];
    echo json_encode(Keeper::request($file, (int) $file->pid(), $address, $command, getcwd(), 10.0)), "\n";
    exit(0);
}

$text = $argv[3];
$generation = Generation::inherit();
try {
    if (($argv[4] ?? '') === 'fail') {
        throw new RuntimeException('this generation was told to fail');
    }
    $runtime = new Runtime(shutdownTimeout: 2.0);
    $runtime->group('web', workers: 2)->listen(
        'tcp://inherited',
        new HttpServer(static fn(Request $request) => Response::text($text . ' from ' . posix_getppid())),
        socket: $generation->socket,
    );
    $generation->attach($runtime);
    $runtime->run();
} catch (Throwable $error) {
    $generation->fail($error->getMessage());
    exit(1);
}
