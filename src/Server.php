<?php

declare(strict_types=1);

namespace Crab;

use Crab\Engine\Process\Daemon;
use Crab\Engine\Process\FileWatcher;
use Crab\Engine\Process\Generation;
use Crab\Engine\Process\Keeper;
use Crab\Engine\Process\PidFile;

/**
 * Runs the application's server and controls one that is running: in the
 * foreground, in the background, restarted when files change, stopped,
 * and replaced by a new one without refusing a connection. The `serve`,
 * `stop`, `reload`, `restart` and `status` commands are this class with
 * words around it.
 *
 * A server in the background is two levels of process: a keeper, whose
 * process id is the service's and never changes, and under it the server
 * proper, which a reload replaces. The keeper's id is written to
 * "server.pid" and everything printed goes to "server.log"; both default
 * to the storage folder.
 */
final class Server
{
    /**
     * What a developer edits, watched when "server.watch" does not say.
     */
    public const WATCHED = ['app', 'config', 'routes', '.env'];

    public function __construct(private readonly App $app)
    {
    }

    public function pidFile(): PidFile
    {
        return new PidFile($this->path((string) $this->app->config->get('server.pid', 'storage/crab.pid')));
    }

    /**
     * The file a server in the background prints to.
     */
    public function logFile(): string
    {
        return $this->path((string) $this->app->config->get('server.log', 'storage/logs/server.log'));
    }

    /**
     * @return int|null The process id of the server in the background, or null when there is none.
     */
    public function running(): ?int
    {
        $pid = $this->pidFile()->pid();
        return $pid !== null && self::isServer($pid) ? $pid : null;
    }

    /**
     * Serves in this process until it is told to stop.
     */
    public function serve(string $address, int $workers, bool $single = false): void
    {
        $this->app->runtime($address, $workers)->run(singleProcess: $single);
    }

    /**
     * Starts the server in the background and returns, in the process that
     * called it, once it accepts connections. The process that goes into
     * the background becomes the server's keeper and never returns from
     * here: it holds the listening socket and runs the server proper as a
     * child, which a reload replaces. See Engine\Process\Keeper.
     *
     * @param list<string> $invocation How this application's command line is run, less the command; see invocation().
     * @param float $timeout Seconds to wait for it to be ready.
     * @return int The server's process id, which stays the same across reloads.
     */
    public function start(string $address, int $workers, array $invocation, float $timeout = 20.0): int
    {
        $pidFile = $this->pidFile();
        $log = $this->logFile();
        $lock = $this->lock($pidFile);
        $logged = (int) @filesize($log);
        $directory = (string) getcwd();
        $daemon = Daemon::start($log);
        if (!$daemon->background) {
            [$started, $message] = $daemon->await($timeout + 5.0);
            fclose($lock);
            if (!$started) {
                throw new \RuntimeException("The server did not start in the background: {$message}" . self::firstLines($log, $logged) . "\nIts output is in {$log}.");
            }
            return (int) $message;
        }
        // From here on this process is the keeper. The lock is the launcher's to hold.
        fclose($lock);
        $status = 1;
        try {
            $keeper = new Keeper($pidFile, $log, $timeout);
            $status = $keeper->run(
                $address,
                self::generationCommand($invocation, $address, $workers),
                $directory,
                static fn () => $daemon->ready((string) getmypid()),
            );
        } catch (\Throwable $error) {
            // Before it was ready this reaches the launcher; afterwards the log is all there is.
            $daemon->fail($error->getMessage());
            echo date('c') . ' The server failed: ' . $error . "\n";
        }
        exit($status);
    }

    /**
     * Serves as the child of a keeper, on the socket it passed on. This is
     * what `serve --generation` runs; a keeper starts it and nothing else
     * does.
     */
    public function generation(string $address, int $workers): never
    {
        $status = 0;
        $generation = null;
        try {
            $generation = Generation::inherit();
            $runtime = $this->app->runtime($address, $workers, $generation->socket);
            $generation->attach($runtime);
            $runtime->run();
        } catch (\Throwable $error) {
            $generation?->fail($error->getMessage());
            echo date('c') . ' The server failed: ' . $error . "\n";
            $status = 1;
        }
        exit($status);
    }

    /**
     * Tells the server in the background to finish what it has in hand
     * and stop, and waits until it has.
     *
     * @param float $timeout Seconds to wait for it.
     * @param bool $force Whether to end it outright when it has not stopped in that time.
     * @return int|null The process id of the server that stopped, or null when none was running.
     */
    public function stop(float $timeout = 15.0, bool $force = false): ?int
    {
        $pidFile = $this->pidFile();
        $pid = $this->running();
        if ($pid === null) {
            return null;
        }
        self::requireSignals();
        posix_kill($pid, SIGTERM);
        if (!self::waitForExit($pid, $timeout)) {
            if (!$force) {
                throw new \RuntimeException("The server, pid {$pid}, has not stopped after {$timeout} seconds. It may still be finishing requests; `stop --force` ends it outright.");
            }
            posix_kill($pid, SIGKILL);
            self::waitForExit($pid, 5.0);
        }
        // A server that was ended outright could not remove its own file.
        if ((int) trim((string) @file_get_contents($pidFile->path)) === $pid) {
            @unlink($pidFile->path);
        }
        return $pid;
    }

    /**
     * Gives the server in the background the code and configuration as
     * they are now, without a connection being refused. Its keeper starts
     * a new server process on the socket it holds, so both accept from one
     * queue; when the new one accepts connections the old one is told to
     * stop, and finishes the requests it has. If the new one cannot
     * start, the old one is left serving.
     *
     * @param list<string> $invocation How this application's command line is run, less the command; see invocation().
     * @return array{int, int, int} The server's process id, and those of the new and the previous server process under it.
     */
    public function reload(string $address, int $workers, array $invocation, float $timeout = 20.0): array
    {
        $server = $this->running();
        if ($server === null) {
            throw new \RuntimeException('No server is running in the background to reload. Start one with `serve --daemon`.');
        }
        self::requireSignals();
        $pidFile = $this->pidFile();
        $lock = $this->lock($pidFile);
        $log = $this->logFile();
        $logged = (int) @filesize($log);
        try {
            $answer = Keeper::request($pidFile, $server, $address, self::generationCommand($invocation, $address, $workers), (string) getcwd(), $timeout + 5.0);
        } finally {
            fclose($lock);
        }
        if ($answer['ok'] !== true) {
            $why = (string) ($answer['message'] ?? 'It did not say why.');
            throw new \RuntimeException("The server was not reloaded, and goes on serving as it was: {$why}" . self::firstLines($log, $logged) . "\nIts output is in {$log}.");
        }
        return [$server, (int) $answer['pid'], (int) $answer['previous']];
    }

    /**
     * Serves in a child process and starts it again whenever a watched
     * file changes, until this process is told to stop. For development.
     *
     * @param list<string> $command The command that serves in the foreground, starting with the script.
     * @param list<string> $paths Directories and files to watch, inside the application.
     * @param \Closure(string): void $say Tells the developer what is happening.
     */
    public function watch(array $command, array $paths, \Closure $say): int
    {
        $watcher = new FileWatcher(array_map($this->path(...), $paths));
        $say('Watching ' . implode(', ', $paths) . " ({$watcher->count()} files). The server restarts when one changes.");

        $stopping = false;
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            $stop = static function () use (&$stopping): void {
                $stopping = true;
            };
            pcntl_signal(SIGINT, $stop);
            pcntl_signal(SIGTERM, $stop);
        }
        $process = self::spawn($command);
        while (!$stopping) {
            usleep(300_000);
            if ($process !== null) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    proc_close($process);
                    $process = null;
                    if (!$stopping) {
                        $say("The server stopped with status {$status['exitcode']}. It starts again when a file changes.");
                    }
                }
            }
            $changed = $watcher->changes();
            if ($changed === [] || $stopping) {
                continue;
            }
            // Saving, or switching branch, touches several files; wait until it is over.
            do {
                usleep(150_000);
                $more = $watcher->changes();
                $changed = [...$changed, ...$more];
            } while ($more !== []);
            $changed = array_values(array_unique($changed));
            $others = count($changed) - 1;
            $say('Changed: ' . $this->shortened($changed[0]) . ($others > 0 ? " and {$others} more" : '') . '. Restarting.');
            self::terminate($process);
            $process = self::spawn($command);
        }
        self::terminate($process);
        return 0;
    }

    /**
     * How this process was started, up to the command's name: the script,
     * and for `bin/crab app.php` the application file, with their full
     * paths, since the server that runs them again may be elsewhere.
     *
     * @return list<string>
     */
    public static function invocation(string $command): array
    {
        $arguments = array_values((array) ($_SERVER['argv'] ?? []));
        $position = array_search($command, $arguments, true);
        $before = array_slice($arguments, 0, $position === false ? 1 : (int) $position);
        $invocation = [];
        foreach ($before as $argument) {
            $path = realpath((string) $argument);
            $invocation[] = $path !== false && is_file($path) ? $path : (string) $argument;
        }
        return $invocation;
    }

    /**
     * The command a keeper starts a server process with, after the PHP binary.
     *
     * @param list<string> $invocation
     * @return list<string>
     */
    private static function generationCommand(array $invocation, string $address, int $workers): array
    {
        return [...$invocation, 'serve', '--generation', "--address={$address}", "--workers={$workers}"];
    }

    /**
     * A path as it is when absolute, otherwise inside the application's
     * base path, or the working directory when it has none.
     */
    private function path(string $path): string
    {
        try {
            return $this->app->path($path);
        } catch (\LogicException) {
            return getcwd() . '/' . $path;
        }
    }

    /**
     * A path as a developer would name it: from the application's folder.
     */
    private function shortened(string $path): string
    {
        $base = ($this->app->basePath ?? getcwd()) . '/';
        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /**
     * Held while a server is being started, so that two starts or reloads
     * at once cannot both go ahead.
     *
     * @return resource
     */
    private function lock(PidFile $pidFile): mixed
    {
        $directory = dirname($pidFile->path);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException("The directory for the process id file, {$directory}, cannot be created.");
        }
        $lock = @fopen($pidFile->path . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('Another start or reload of this server is under way.');
        }
        return $lock;
    }

    /**
     * @param list<string> $command
     * @return resource
     */
    private static function spawn(array $command): mixed
    {
        // The child reads and writes the terminal as this process does.
        $process = proc_open([PHP_BINARY, ...$command], [STDIN, STDOUT, STDERR], $pipes);
        if ($process === false) {
            throw new \RuntimeException('Could not start the server process.');
        }
        return $process;
    }

    /**
     * Asks a child server to stop, and ends it outright when it takes
     * longer than a graceful stop may.
     *
     * @param resource|null $process
     */
    private static function terminate(mixed $process): void
    {
        if ($process === null) {
            return;
        }
        proc_terminate($process, 15);
        $deadline = microtime(true) + 8.0;
        while (proc_get_status($process)['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                break;
            }
            usleep(20_000);
        }
        proc_close($process);
    }

    /**
     * What the engine reported first while a server failed to start, which
     * is usually why: a worker that could not be made, for example.
     */
    private static function firstLines(string $log, int $from): string
    {
        clearstatcache();
        $written = (string) @file_get_contents($log, false, null, $from, 8192);
        foreach (explode("\n", $written) as $line) {
            if (str_starts_with($line, '[Crab] ')) {
                return "\n  " . (strlen($line) > 400 ? substr($line, 0, 400) . '...' : $line);
            }
        }
        return '';
    }

    private static function waitForExit(int $pid, float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (PidFile::alive($pid)) {
            if (microtime(true) > $deadline) {
                return false;
            }
            usleep(50_000);
        }
        return true;
    }

    /**
     * Whether a live process is one of these servers, by the command it
     * was started with. A process id file outlives a crash or a restart
     * of the machine, and by then its number may belong to something
     * else, which must be neither reported as the server nor stopped.
     */
    private static function isServer(int $pid): bool
    {
        $command = self::commandOf($pid);
        if ($command === null) {
            // There is no way to ask here, so the file is taken at its word.
            return true;
        }
        foreach ([' serve ', ' reload ', ' restart '] as $started) {
            if (str_contains(" {$command} ", $started)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The command line a process was started with: from /proc where the
     * system has it, as Linux does, otherwise from ps. Empty when the
     * process has gone, null when neither can tell.
     */
    private static function commandOf(int $pid): ?string
    {
        if (is_dir('/proc/self')) {
            $arguments = @file_get_contents("/proc/{$pid}/cmdline");
            return $arguments === false ? '' : trim(str_replace("\0", ' ', $arguments));
        }
        if (!function_exists('exec')) {
            return null;
        }
        @exec('ps -o command= -p ' . $pid . ' 2>/dev/null', $lines, $status);
        if ($status === 126 || $status === 127) {
            return null;
        }
        return $status === 0 ? implode(' ', $lines) : '';
    }

    private static function requireSignals(): void
    {
        if (!function_exists('posix_kill')) {
            throw new \RuntimeException('Controlling a server in the background needs PHP\'s posix extension.');
        }
    }
}
