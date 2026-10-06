<?php

declare(strict_types=1);

namespace Crab\Tests;

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

    protected function setUp(): void
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            self::markTestSkipped('Serving in the background needs pcntl and posix.');
        }
        // The real path: where the temporary folder is a link, __DIR__ in the application names its target.
        $this->directory = realpath(sys_get_temp_dir()) . '/crab-server-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/app', recursive: true);
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        file_put_contents($this->directory . '/cua', "<?php\nrequire '{$autoload}';\nexit((require __DIR__ . '/app.php')->console->run(array_slice(\$argv, 1)));\n");
        file_put_contents($this->directory . '/app.php', "<?php\nreturn Crab\\App::configure(__DIR__)->withRouting(routes: 'app/routes.php')->create();\n");
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

        [$status, $output] = $this->cua('serve', '--daemon', "--address={$this->address}", '--workers=2');
        self::assertSame(0, $status, $output);
        $pid = $this->pid();
        self::assertSame("Crab server running on {$this->url}\n", $output);
        self::assertSame('one', $this->get(), 'It accepts connections by the time the command returns.');
        self::assertSame(1, posix_getpgid($pid) === posix_getpgid(getmypid()) ? 0 : 1, 'It is not in the group of the terminal that started it.');
        self::assertSame([0, "The server is running in the background, pid {$pid}.\nIts output goes to {$this->directory}/storage/logs/server.log.\n"], $this->cua('status'));
        self::assertMatchesRegularExpression("~\\[keeper {$pid}\\] Serving on {$this->address}, generation pid \\d+\\.~", (string) file_get_contents($this->directory . '/storage/logs/server.log'));

        [$status, $output] = $this->cua('serve', '-d', "--address={$this->address}");
        self::assertSame([1, "A server is already running in the background, pid {$pid}. Use `reload` to give it new code, or `stop` to end it.\n"], [$status, $output]);
        [$status, $output] = $this->cua('serve', "--address={$this->address}", '--single', '--no-watch');
        self::assertSame(1, $status, 'A second server in the foreground cannot take the address by accident.');
        self::assertStringContainsString('Address already in use', $output);

        self::assertSame([0, "Stopped the server, pid {$pid}.\n"], $this->cua('stop'));
        self::assertFalse(posix_kill($pid, 0), 'The process has gone.');
        self::assertNull($this->get());
        self::assertFileDoesNotExist($this->directory . '/storage/crab.pid');
        self::assertSame(1, $this->cua('status')[0]);
        self::assertStringContainsString("[keeper {$pid}] Stopped.", (string) file_get_contents($this->directory . '/storage/logs/server.log'));

        // A file left behind by a crash, whose number now belongs to something else: this test's own process.
        file_put_contents($this->directory . '/storage/crab.pid', getmypid() . "\n");
        self::assertSame([1, "No server is running in the background.\n"], $this->cua('status'), 'A live process that is not the server is not taken for it.');
        self::assertSame([0, "No server is running in the background.\n"], $this->cua('stop'), 'And is not stopped.');
        self::assertTrue(posix_kill(getmypid(), 0));
        self::assertSame(0, $this->cua('serve', '--daemon', "--address={$this->address}")[0], 'Nor does it stand in the way of starting.');
        self::assertNotSame(getmypid(), $this->pid());
        self::assertSame(0, $this->cua('stop')[0]);

        self::assertSame(1, $this->cua('serve', '--daemon', '--single')[0]);
        [$status, $output] = $this->cua('serve', '--help');
        self::assertSame(0, $status, 'Asking a command for help describes it and does not run it.');
        self::assertStringStartsWith("Serve the application over HTTP\n\nOptions:\n", $output);
        self::assertSame($output, $this->cua('serve', '-h', "--address={$this->address}")[1]);
        self::assertNull($this->get());
        self::assertSame(1, $this->cua('serve', '--daemon', '--watch')[0]);
    }

    public function testAReloadBringsNewCodeWithoutRefusingAConnection(): void
    {
        self::assertSame(0, $this->cua('serve', '--daemon', "--address={$this->address}", '--workers=2')[0]);
        $pid = $this->pid();

        // Requests keep arriving, each on a new connection, while the server is replaced.
        $load = proc_open([PHP_BINARY, '-r', <<<'PHP'
            $end = microtime(true) + 2.5; $seen = []; $failed = 0;
            while (microtime(true) < $end) {
                $c = @stream_socket_client($argv[1], $e, $m, 2);
                if ($c === false) { $failed++; continue; }
                stream_set_timeout($c, 5);
                fwrite($c, "GET / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
                $r = (string) stream_get_contents($c);
                fclose($c);
                if (!str_contains($r, '200 OK')) { $failed++; continue; }
                $body = substr($r, strrpos($r, "\r\n\r\n") + 4);
                $seen[$body] = ($seen[$body] ?? 0) + 1;
            }
            echo json_encode(['failed' => $failed, 'seen' => $seen]);
            PHP, $this->address], [1 => ['pipe', 'w']], $pipes);
        usleep(400_000);
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

        $result = json_decode((string) stream_get_contents($pipes[1]), true);
        proc_close($load);
        self::assertSame(0, $result['failed'], 'No request was refused or cut short during the reload.');
        self::assertGreaterThan(0, $result['seen']['one'] ?? 0, 'The old process served until the new one was ready.');
        self::assertGreaterThan(0, $result['seen']['two'] ?? 0, 'The new process took over.');
        for ($waited = 0; $waited < 200 && posix_kill($old, 0); ++$waited) {
            usleep(50_000);
        }
        self::assertFalse(posix_kill($old, 0), 'The old process has gone.');
        self::assertTrue(posix_kill($new, 0));
        self::assertStringContainsString("generation pid {$new}, taking over from {$old}.", (string) file_get_contents($this->directory . '/storage/logs/server.log'));
        self::assertSame([], glob($this->directory . '/storage/crab.pid.reload*'), 'Nothing is left of the request.');

        // A server process that dies is started again, on the socket that never closed.
        posix_kill($new, SIGKILL);
        $log = $this->directory . '/storage/logs/server.log';
        for ($waited = 0; $waited < 200 && !str_contains((string) file_get_contents($log), 'Serving again, generation pid '); ++$waited) {
            usleep(50_000);
        }
        self::assertStringContainsString("Generation pid {$new} ended by itself", (string) file_get_contents($log));
        self::assertStringContainsString('Serving again, generation pid ', (string) file_get_contents($log));
        self::assertSame('two', $this->get(retry: true));
        self::assertSame([0, $pid], [$this->cua('status')[0], $this->pid()]);
    }

    public function testAReloadThatCannotStartLeavesTheOldServerServing(): void
    {
        self::assertSame(0, $this->cua('serve', '--daemon', "--address={$this->address}")[0]);
        $pid = $this->pid();

        // Wrong where the application is described: the command itself cannot load it.
        file_put_contents($this->directory . '/app/routes.php', '<?php this is not PHP');
        [$status] = $this->cua('reload', "--address={$this->address}");
        self::assertNotSame(0, $status);
        self::assertSame([$pid, 'one'], [$this->pid(), $this->get()]);

        // Wrong only once a worker starts: the new server is made, fails, and reports why.
        file_put_contents($this->directory . '/app.php', "<?php\nreturn Crab\\App::configure(__DIR__)->withMiddleware('App\\\\Missing\\\\Middleware')->withRouting(routes: 'app/routes.php')->create();\n");
        $this->version('two');
        [$status, $output] = $this->cua('reload', "--address={$this->address}");
        self::assertSame(1, $status);
        self::assertStringStartsWith('The server was not reloaded, and goes on serving as it was: ', $output);
        self::assertStringContainsString('App\Missing\Middleware has no binding', $output, 'The first thing that went wrong is shown, not only where the log is.');
        self::assertStringContainsString("Its output is in {$this->directory}/storage/logs/server.log.", $output);
        self::assertSame([$pid, 'one'], [$this->pid(), $this->get()], 'The server that was running was not touched.');
        self::assertSame(0, $this->cua('status')[0]);
    }

    public function testARestartStopsAndStartsAndForceEndsOneThatWillNotStop(): void
    {
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
        posix_kill($second, SIGSTOP);
        [$status, $output] = $this->cua('stop', '--timeout=0.3');
        self::assertSame(1, $status);
        self::assertStringContainsString("pid {$second}, has not stopped after 0.3 seconds", $output);
        self::assertStringContainsString('`stop --force` ends it outright', $output);
        self::assertSame([0, "Stopped the server, pid {$second}.\n"], $this->cua('stop', '--timeout=0.3', '--force'));
        self::assertFalse(posix_kill($second, 0));
        self::assertFileDoesNotExist($this->directory . '/storage/crab.pid', 'Its file is removed for it.');
        // The server process under it notices that its keeper has gone and stops by itself.
        for ($waited = 0; $waited < 200 && $this->get() !== null; ++$waited) {
            usleep(50_000);
        }
        self::assertNull($this->get());
    }

    public function testTheWatcherRestartsTheServerWhenAFileChanges(): void
    {
        $this->watcher = proc_open(
            // No option asks for it: in the foreground the server is watched unless told otherwise.
            [PHP_BINARY, $this->directory . '/cua', 'serve', "--address={$this->address}", '--single'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
        );
        stream_set_blocking($pipes[1], false);
        $output = '';
        $everything = '';
        $until = function (string $text) use (&$output, &$everything, $pipes): void {
            $deadline = microtime(true) + 10;
            while (!str_contains($output, $text)) {
                self::assertLessThan($deadline, microtime(true), "The watcher never said \"{$text}\". It said:\n{$output}");
                $read = (string) fread($pipes[1], 8192);
                $output .= $read;
                $everything .= $read;
                usleep(20_000);
            }
            $output = substr($output, strpos($output, $text) + strlen($text));
        };

        $banner = "Crab server running on {$this->url}\nPress Ctrl+C to stop\n";
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
        self::assertStringStartsWith($said, $everything, 'It says where it serves once, and after that only what happened.');
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
    private function version(string $text): void
    {
        file_put_contents($this->directory . '/app/routes.php', "<?php\nCrab\\Route::get('/', static fn (): string => '{$text}');\n");
    }

    /**
     * Runs a command of the application.
     *
     * @return array{int, string} Its exit status, and what it wrote.
     */
    private function cua(string ...$arguments): array
    {
        $process = proc_open([PHP_BINARY, $this->directory . '/cua', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->directory);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($process), $output];
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
        self::assertSame('http://localhost:9000', \Crab\Server::describe('tcp://0.0.0.0:9000'), 'Every interface is not an address to open.');
        self::assertSame('http://localhost:9000 with 2 workers', \Crab\Server::describe('tcp://[::]:9000', 2));
        self::assertSame('http://api.test:80', \Crab\Server::describe('tcp://api.test:80'));
    }
}
