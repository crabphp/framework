<?php

declare(strict_types=1);

namespace Crab\Tests\WebSocket;

use PHPUnit\Framework\TestCase;

/**
 * A real background application, using the same keeper and command line
 * as ServerTest. Every process and temporary file belongs to this fixture.
 */
final class ServerTest extends TestCase
{
    private string $directory;
    private string $address;
    private array $clients = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('The background server needs pcntl and posix.');
        }
        $this->directory = realpath(sys_get_temp_dir()) . '/crab-ws-route-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        file_put_contents($this->directory . '/cua', "<?php\nrequire " . var_export($autoload, true) . ";\n\$app = require __DIR__ . '/app.php';\nexit(\$app->console->run(array_slice(\$argv, 1)));\n");
        $this->version('one');
        for ($port = 8200; $port <= 8299; ++$port) {
            $probe = @stream_socket_server("tcp://127.0.0.1:{$port}");
            if ($probe !== false) {
                $this->address = "tcp://127.0.0.1:{$port}";
                fclose($probe);
                return;
            }
        }
        self::fail('No test port is available on 127.0.0.1 between 8200 and 8299.');
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory)) { return; }
        foreach ($this->clients as $client) {
            if (is_resource($client)) { fclose($client); }
        }
        $this->command('stop', '--force', '--timeout=3');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testReloadClosesOldSocketsWith1001AdmitsTheNewGenerationAndStopLeavesNothing(): void
    {
        [$status, $output] = $this->command('serve', '--daemon', '--workers=1', '--address=' . $this->address);
        self::assertSame(0, $status, $output);
        $failed = $this->connect('/failure');
        [$opcode, $payload] = $this->readFrame($failed);
        self::assertSame([8, 1011], [$opcode, unpack('n', $payload)[1]]);
        $this->replyClose($failed, $payload);
        $this->waitFor(fn (): bool => is_file($this->directory . '/app.log'));
        $log = file_get_contents($this->directory . '/app.log');
        self::assertSame(1, substr_count($log, 'ERROR Unhandled failure: The action of WS /failure failed:'));
        self::assertStringContainsString('Crab\\Http\\HttpException', $log);
        $old = $this->connect();
        [$opcode, $greeting] = $this->readFrame($old);
        self::assertSame(1, $opcode);
        self::assertStringStartsWith('one ', $greeting);
        $oldPid = (int) substr($greeting, 4);

        $this->version('two');
        [$status, $output] = $this->command('reload', '--address=' . $this->address);
        self::assertSame(0, $status, $output);
        [$opcode, $payload] = $this->readFrame($old);
        self::assertSame(8, $opcode);
        self::assertSame(1001, unpack('n', $payload)[1]);
        $this->replyClose($old, $payload);

        $new = $this->connect();
        [$opcode, $greeting] = $this->readFrame($new);
        self::assertSame(1, $opcode);
        self::assertStringStartsWith('two ', $greeting);
        $newPid = (int) substr($greeting, 4);
        self::assertNotSame($oldPid, $newPid);
        $this->waitFor(fn (): bool => is_file($this->directory . '/finished-' . $oldPid));

        [$process, $pipes] = $this->startCommand('stop');
        try {
            [$opcode, $payload] = $this->readFrame($new);
            self::assertSame(8, $opcode);
            self::assertSame(1001, unpack('n', $payload)[1]);
            $this->replyClose($new, $payload);
        } finally {
            [$status, $output] = $this->finishCommand($process, $pipes);
        }
        self::assertSame(0, $status, $output);
        self::assertFileExists($this->directory . '/finished-' . $newPid, 'Stop waited for asynchronous code after receive().');
        self::assertFileDoesNotExist($this->directory . '/storage/crab.pid');
        $rows = array_filter(explode("\n", file_get_contents($this->directory . '/stopped')));
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            [$pid, $cut] = explode(' ', $row);
            self::assertSame('0', $cut, 'An upgraded socket is not a cut HTTP request.');
            self::assertFalse(@posix_kill((int) $pid, 0), 'The worker is gone.');
        }
        self::assertFalse(@posix_kill($oldPid, 0));
        self::assertFalse(@posix_kill($newPid, 0));
        [$status] = $this->command('status');
        self::assertNotSame(0, $status);
        $log = is_file($this->directory . '/app.log') ? file_get_contents($this->directory . '/app.log') : '';
        self::assertStringNotContainsString('cut ', $log);
    }

    private function version(string $generation): void
    {
        $source = <<<'PHP'
<?php
final class SocketObserver implements Crab\Contracts\WorkerProvider
{
    public function register(Crab\Contracts\Container\Container $container): void {}
    public function start(Crab\Engine\Process\Worker $worker, Crab\Contracts\Container\Container $container): void {}
    public function stop(Crab\Engine\Process\Worker $worker, Crab\Contracts\Container\Container $container): void
    {
        file_put_contents(__DIR__ . '/stopped', getmypid() . ' ' . $worker->stats()['cutConnections'] . "\n", FILE_APPEND | LOCK_EX);
    }
}
$app = new Crab\App(new Crab\Config\Config([
    'server' => ['drain' => 2.0],
    'websocket' => ['ping' => 0],
    'log' => ['path' => __DIR__ . '/app.log'],
]), basePath: __DIR__);
$app->register(new SocketObserver());
Crab\Route::websocket('/live', static function (Crab\Contracts\WebSocket\Socket $socket): void {
    $socket->send('GENERATION ' . getmypid());
    foreach ($socket as $message) {}
    Crab\Engine\Async\Async::sleep(Crab\Engine\EventLoop::current(), 0.05);
    file_put_contents(__DIR__ . '/finished-' . getmypid(), 'yes');
});
Crab\Route::websocket('/failure', static function (Crab\Contracts\WebSocket\Socket $socket): void {
    $socket->accept();
    throw new Crab\Http\HttpException(404, 'After upgrading');
});
return $app;
PHP;
        file_put_contents($this->directory . '/app.php', str_replace('GENERATION', $generation, $source));
    }

    private function connect(string $path = '/live'): mixed
    {
        $client = stream_socket_client($this->address, $errno, $error, 2);
        self::assertIsResource($client, $error);
        $this->clients[] = $client;
        stream_set_timeout($client, 3);
        fwrite($client, "GET {$path} HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\n\r\n");
        $head = '';
        while (!str_ends_with($head, "\r\n\r\n")) { $head .= $this->readBytes($client, 1); }
        self::assertStringStartsWith('HTTP/1.1 101', $head);
        return $client;
    }

    private function readFrame(mixed $client): array
    {
        $head = $this->readBytes($client, 2);
        $length = ord($head[1]) & 127;
        if ($length === 126) { $length = unpack('n', $this->readBytes($client, 2))[1]; }
        return [ord($head[0]) & 15, $this->readBytes($client, $length)];
    }

    private function readBytes(mixed $client, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $part = fread($client, $length - strlen($bytes));
            if ($part === false || $part === '') {
                self::fail('The server did not send the expected frame.');
            }
            $bytes .= $part;
        }
        return $bytes;
    }

    private function replyClose(mixed $client, string $payload): void
    {
        $mask = random_bytes(4);
        fwrite($client, chr(136) . chr(128 | strlen($payload)) . $mask . ($payload ^ str_repeat($mask, (int) ceil(strlen($payload) / 4))));
    }

    private function startCommand(string ...$arguments): array
    {
        $process = proc_open([PHP_BINARY, $this->directory . '/cua', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory);
        self::assertIsResource($process);
        return [$process, $pipes];
    }

    private function finishCommand(mixed $process, array $pipes): array
    {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }

    private function command(string ...$arguments): array
    {
        [$process, $pipes] = $this->startCommand(...$arguments);
        return $this->finishCommand($process, $pipes);
    }

    private function waitFor(\Closure $ready): void
    {
        $deadline = microtime(true) + 3;
        while (!$ready() && microtime(true) < $deadline) { usleep(5000); }
        self::assertTrue($ready());
    }
}
