<?php

declare(strict_types=1);

namespace Crab;

use Crab\Engine\FailureReporter;
use Crab\Engine\Process\Daemon;
use Crab\Engine\Process\FileWatcher;
use Crab\Engine\Process\Generation;
use Crab\Engine\Process\Keeper;
use Crab\Engine\Process\PidFile;
use Crab\Engine\Runtime;

/**
 * Runs the application's server and controls one that is running: in the
 * foreground, in the background, restarted when files change, stopped,
 * and replaced by a new one without refusing a connection. The `start`,
 * `stop`, `reload`, `restart` and `status` commands are this class with
 * words around it.
 *
 * A server in the background is two levels of process: a keeper, whose
 * process id is the service's and never changes, and under it the server
 * proper, which a reload replaces. The keeper's id is written to
 * "server.pid" and everything printed goes to "server.log"; both default
 * to the storage folder. Finding, reading and stopping a running server
 * needs only those files, and is ServerFiles.
 *
 * Servers registered with `reloads: false` run in a second server process
 * under the keeper, which a reload leaves as it is.
 */
final class Server
{
    /**
     * What a developer edits, watched when "server.watch" does not say.
     */
    public const WATCHED = ['app', 'app.php', 'config', 'routes', '.env'];

    /**
     * A watched child reports its computed stop allowance on this pipe.
     * The launcher keeps old code and configuration across file changes.
     */
    private const WATCH_REPORT = 3;

    public function __construct(
        private readonly App $app,
    ) {}

    /**
     * The files this application's server keeps of itself, where its
     * settings put them; see ServerFiles. "server.pid" and "server.log"
     * are inside the application's folder unless absolute.
     */
    public function files(): ServerFiles
    {
        $pid = (string) $this->app->config->get('server.pid');
        try {
            $stats = $this->app->path(ServerFiles::statsBeside($pid));
        } catch (\LogicException) {
            // An application with no base path has nowhere of its own to keep them.
            $stats = null;
        }
        return new ServerFiles(
            $this->path($pid),
            $this->path((string) $this->app->config->get('server.log')),
            $stats,
            $this->grace(...),
        );
    }

    /**
     * Has the runtime keep a stats file, when there is somewhere to keep it.
     *
     * @internal For App, whose runners keep one too.
     *
     * @param array<string, mixed> $about
     */
    public function keepStats(Runtime $runtime, array $about): Runtime
    {
        $directory = $this->files()->stats;
        return $directory === null ? $runtime : $runtime->stats($directory, $about);
    }

    /**
     * Seconds a server process is given to stop before it is ended from
     * outside: longer than its own workers are given, so that it is the
     * one that ends them. Ended first, it would leave a worker that is
     * stuck with no one to end it.
     */
    private function grace(): float
    {
        $drain = $this->app->drain();
        try {
            return max(10.0, $drain + 1.0, $this->app->servers->grace($this->app->with())) + 5.0;
        } catch (\LogicException) {
            // A setting that names a server there is not is reported where the server starts.
            return max(10.0, $drain + 1.0) + 5.0;
        }
    }

    /**
     * Serves in this process until it is told to stop.
     */
    public function serve(string $address, int $workers, bool $single = false): void
    {
        $runtime = $this->keepStats($this->app->runtime($address, $workers, servers: !$single), [
            'address' => $address,
            'mode' => $single ? 'single' : 'foreground',
        ]);
        if (in_array('--watch-report', $_SERVER['argv'] ?? [], true)) {
            $report = @fopen('php://fd/' . self::WATCH_REPORT, 'wb');
            if ($report === false) {
                throw new \RuntimeException(
                    'The watched server has no pipe to report its stop allowance. Start it with `dev`.',
                );
            }
            try {
                $allowance = $this->grace() . "\n";
                if (@fwrite($report, $allowance) !== strlen($allowance)) {
                    throw new \RuntimeException(
                        'The watched server could not report its stop allowance. Start it again with `dev`.',
                    );
                }
            } finally {
                fclose($report);
            }
        }
        $runtime->run(singleProcess: $single);
    }

    /**
     * Starts the server in the background and returns, in the process that
     * called it, once it accepts connections. The process that goes into
     * the background becomes the server's keeper and never returns from
     * here: it holds the listening socket and runs the server proper as a
     * child, which a reload replaces. See Engine\Process\Keeper.
     *
     * @param list<string> $invocation How this application's command line is run, less the command; see invocation().
     * @param array{address?: string, workers?: int} $given The options typed on the command line, which a reload or restart keeps; see given().
     * @param float $timeout Seconds to wait for it to be ready.
     * @return int The server's process id, which stays the same across reloads.
     */
    public function start(string $address, array $given, array $invocation, float $timeout = 20.0): int
    {
        $grace = $this->grace();
        $files = $this->files();
        $pidFile = $files->pidFile();
        $log = $files->log;
        $lock = $this->lock($pidFile);
        $logged = (int) @filesize($log);
        $directory = (string) getcwd();
        $daemon = Daemon::start($log);
        if (!$daemon->background) {
            [$started, $message] = $daemon->await($timeout + 5.0);
            fclose($lock);
            if (!$started) {
                throw new \RuntimeException(
                    "The server did not start in the background: {$message}"
                    . self::firstLines($log, $logged)
                    . "\nIts output is in {$log}.",
                );
            }
            return (int) $message;
        }
        // From here on this process is the keeper. The lock is the launcher's to hold.
        fclose($lock);
        $status = 1;
        try {
            // The daemon's second fork is detached but no longer leads its
            // group. Give only this background keeper its own session/group.
            if (posix_setsid() === -1) {
                throw new \RuntimeException(
                    'Could not give the background keeper its own process group. Start the server again.',
                );
            }
            $keeper = new Keeper($pidFile, $log, $timeout, $grace);
            $held = $this->app->held();
            $status = $keeper->run(
                $address,
                self::generationCommand($invocation, $given),
                $directory,
                static fn() => $daemon->ready((string) getmypid()),
                $held === []
                    ? null
                    : ['command' => self::generationCommand($invocation, $given, held: true), 'names' => $held],
            );
        } catch (\Throwable $error) {
            // Before it was ready this reaches the launcher; afterwards the log is all there is.
            $daemon->fail($error->getMessage());
            echo date('c') . ' The server failed: ' . FailureReporter::describe($error) . "\n";
        }
        exit($status);
    }

    /**
     * Serves as the child of a keeper, on the socket it passed on. This is
     * what `start --generation` runs; a keeper starts it and nothing else
     * does. With `--held` it is the keeper's other process, which runs the
     * servers kept across reloads and no HTTP.
     */
    public function generation(?string $address, int $workers, bool $held = false): never
    {
        $status = 0;
        $generation = null;
        try {
            $generation = Generation::inherit();
            // Without an address of its own on the command line, it serves where the keeper's socket listens.
            $address ??= 'tcp://' . stream_socket_get_name($generation->socket, false);
            $about = $held
                ? ['mode' => 'background', 'held' => true, 'keeper' => posix_getppid()]
                : ['address' => $address, 'mode' => 'background', 'keeper' => posix_getppid()];
            $runtime = $this->keepStats(
                $this->app->runtime($address, $workers, $generation->socket, held: $held),
                $about,
            );
            $generation->attach($runtime);
            $runtime->run();
        } catch (\Throwable $error) {
            $generation?->fail($error->getMessage());
            echo date('c') . ' The server failed: ' . FailureReporter::describe($error) . "\n";
            $status = 1;
        }
        exit($status);
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
    public function reload(string $address, array $given, array $invocation, float $timeout = 20.0): array
    {
        $files = $this->files();
        $server = $files->running();
        if ($server === null) {
            throw new \RuntimeException(
                'No server is running in the background to reload. Start one with `start --daemon`.',
            );
        }
        ServerFiles::requireSignals();
        $pidFile = $files->pidFile();
        $lock = $this->lock($pidFile);
        $log = $files->log;
        $logged = (int) @filesize($log);
        try {
            $answer = Keeper::request(
                $pidFile,
                $server,
                $address,
                self::generationCommand($invocation, $given),
                (string) getcwd(),
                $timeout + 5.0,
                $this->grace(),
                $this->app->held(),
            );
        } finally {
            fclose($lock);
        }
        if ($answer['ok'] !== true) {
            $why = (string) ($answer['message'] ?? 'It did not say why.');
            $context = PidFile::running($server) ? 'The server was not reloaded, and goes on serving as it was: ' : '';
            throw new \RuntimeException(
                $context . $why . self::firstLines($log, $logged) . "\nIts output is in {$log}.",
            );
        }
        return [$server, (int) $answer['pid'], (int) $answer['previous']];
    }

    /**
     * Serves in a child process and starts it again whenever a watched
     * file changes, until this process is told to stop. For development.
     *
     * @param list<string> $command The command that serves in the foreground, starting with the script.
     * @param list<string> $paths Directories and files to watch, inside the application.
     * @param \Closure(string): void $say Tells the developer what happened: a restart, or the server stopping by itself.
     */
    public function watch(array $command, array $paths, \Closure $say): int
    {
        $grace = $this->grace();
        $watcher = new FileWatcher(array_map($this->path(...), $paths));

        $stopping = false;
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            $stop = static function () use (&$stopping): void {
                $stopping = true;
            };
            pcntl_signal(SIGINT, $stop);
            pcntl_signal(SIGTERM, $stop);
        }
        [$process, $report] = self::spawn($command);
        while (!$stopping) {
            usleep(300_000);
            $grace = self::reportedGrace($report, $grace);
            if ($process !== null) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    proc_close($process);
                    $process = null;
                    if (!$stopping) {
                        $say("The server stopped (status {$status['exitcode']}). Save a file to start it again.");
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
            $say(
                'Restarted: '
                . $this->shortened($changed[0])
                . ($others > 0 ? " and {$others} more" : '')
                . ' changed.',
            );
            $grace = self::reportedGrace($report, $grace);
            self::terminate($process, $grace);
            if (is_resource($report)) {
                fclose($report);
            }
            // It said where it serves the first time; once is enough.
            [$process, $report] = self::spawn([...$command, '--quiet']);
        }
        $grace = self::reportedGrace($report, $grace);
        self::terminate($process, $grace);
        if (is_resource($report)) {
            fclose($report);
        }
        return 0;
    }

    /**
     * Where the server is reached, as an address to open, and with how
     * many workers when there are several: "http://127.0.0.1:8080 with 4
     * workers". The setting is the socket's address, "tcp://...", since
     * that is what is listened on; what is spoken over it is HTTP.
     */
    public static function describe(string $address, int $workers = 1): string
    {
        $url = (string) preg_replace('~^tcp://~', 'http://', $address);
        // Listening on every interface is not an address to open; this machine's own is.
        $url = str_replace(['://0.0.0.0:', '://[::]:'], '://localhost:', $url);
        return $workers > 1 ? "{$url} with {$workers} workers" : $url;
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
     * The command a keeper starts a server process with, after the PHP
     * binary. It carries only the options that were typed: what was left to
     * the configuration is read from it again by each new server process.
     *
     * @param list<string> $invocation
     * @param array{address?: string, workers?: int} $given
     * @param bool $held True for the process that runs the servers kept across reloads.
     * @return list<string>
     */
    private static function generationCommand(array $invocation, array $given, bool $held = false): array
    {
        $command = [...$invocation, 'start', '--generation'];
        if ($held) {
            $command[] = '--held';
        }
        foreach ($given as $option => $value) {
            $command[] = "--{$option}={$value}";
        }
        return $command;
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
     * @return array{resource, resource} The child process and its stop-allowance pipe.
     */
    private static function spawn(array $command): array
    {
        // The child inherits the terminal. Handing it STDERR instead would make PHP set the
        // descriptor to that stream's own position, rewinding a log that `2>&1` shares with stdout.
        $process = proc_open(
            [PHP_BINARY, ...$command, '--watch-report'],
            [self::WATCH_REPORT => ['pipe', 'w']],
            $pipes,
        );
        if ($process === false) {
            throw new \RuntimeException('Could not start the server process.');
        }
        stream_set_blocking($pipes[self::WATCH_REPORT], false);
        return [$process, $pipes[self::WATCH_REPORT]];
    }

    /**
     * Takes the allowance computed by the child's current application,
     * instead of keeping the launcher's older drain setting after a save.
     *
     * @param resource|null $report
     */
    private static function reportedGrace(mixed &$report, float $fallback): float
    {
        if (!is_resource($report)) {
            return $fallback;
        }
        $seconds = trim((string) @fread($report, 64));
        if ($seconds === '' && !feof($report)) {
            return $fallback;
        }
        fclose($report);
        $report = null;
        return is_numeric($seconds) && is_finite((float) $seconds) && (float) $seconds > 0
            ? (float) $seconds
            : $fallback;
    }

    /**
     * Asks a child server to stop, and ends it outright when it takes
     * longer than a graceful stop may.
     *
     * @param resource|null $process
     */
    private static function terminate(mixed $process, float $grace): void
    {
        if ($process === null) {
            return;
        }
        proc_terminate($process, 15);
        $deadline = microtime(true) + $grace;
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
}
