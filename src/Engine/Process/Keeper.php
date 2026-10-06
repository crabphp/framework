<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

use Crab\Engine\Network\Listener;

/**
 * Holds a listening socket for as long as a service runs and keeps one
 * server process, a generation, serving on it. New code needs a new PHP
 * process, so the keeper replaces the generation: it starts a new one on
 * the same socket, and only when that one accepts connections tells the
 * old one to stop. Both accept from the one queue meanwhile, so no
 * connection is refused; and if the new one cannot start, the old one is
 * left as it was.
 *
 * The keeper itself serves nothing and loads nothing new, which is why it
 * can outlive every deploy: its process id is the service's. A generation
 * that ends by itself is started again, a few times.
 *
 * A replacement is asked for by another process: it writes a request
 * beside the process id file and sends the keeper SIGUSR2; request() does
 * both and waits for the answer.
 */
final class Keeper
{
    /**
     * The descriptor a generation finds the listening socket at.
     */
    public const SOCKET = 3;
    /**
     * The descriptor a generation reports to the keeper on.
     */
    public const REPORT = 4;

    /**
     * @var resource|null
     */
    private mixed $socket = null;
    private string $address = '';
    /**
     * The command and working directory of the generation that serves.
     *
     * @var list<string>
     */
    private array $command = [];
    private string $directory = '';
    /**
     * @var array{process: resource, report: resource|null, pid: int}|null
     */
    private ?array $current = null;
    /**
     * Generations told to stop, with the time by which each must have.
     *
     * @var list<array{process: resource, pid: int, deadline: float}>
     */
    private array $retiring = [];
    /**
     * When the serving generation ended by itself, lately.
     *
     * @var list<float>
     */
    private array $failures = [];
    private bool $stopping = false;
    private bool $replacing = false;

    /**
     * @param string $log The file generations print to.
     * @param float $startTimeout Seconds a generation has to accept connections.
     * @param float $stopTimeout Seconds a generation has to finish its requests before it is ended outright.
     */
    public function __construct(
        private readonly PidFile $pidFile,
        private readonly string $log,
        private readonly float $startTimeout = 20.0,
        private readonly float $stopTimeout = 15.0,
    ) {
    }

    /**
     * Binds the address, starts the first generation and keeps the service
     * until told to stop with SIGTERM or SIGINT. Fails when the address
     * cannot be bound or the first generation cannot start.
     *
     * @param list<string> $command What starts a generation, after the PHP binary: the script and its arguments.
     * @param string $directory The directory to start it in.
     * @param \Closure(): void $ready Called once the first generation accepts connections.
     * @return int The exit status: 0 after a stop that was asked for.
     */
    public function run(string $address, array $command, string $directory, \Closure $ready): int
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            throw new \RuntimeException('Keeping a server in the background needs PHP\'s pcntl and posix extensions.');
        }
        $this->socket = Listener::bind($address);
        $this->address = $address;
        $this->command = $command;
        $this->directory = $directory;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void { $this->stopping = true; });
        pcntl_signal(SIGINT, function (): void { $this->stopping = true; });
        pcntl_signal(SIGUSR2, function (): void { $this->replacing = true; });
        // The reload signal of a server process: passed on to the one that serves.
        pcntl_signal(SIGUSR1, function (): void {
            if ($this->current !== null) {
                posix_kill($this->current['pid'], SIGUSR1);
            }
        });
        $status = 0;
        try {
            $first = $this->spawn($this->command, $this->directory, $this->socket);
            [$started, $message] = $this->await($first);
            if (!$started) {
                $this->end($first);
                throw new \RuntimeException($message);
            }
            $this->current = $first;
            $this->pidFile->write();
            $this->log("Serving on {$address}, generation pid {$first['pid']}.");
            $ready();
            while (!$this->stopping) {
                try {
                    $this->tend();
                } catch (\Throwable $error) {
                    // The keeper has to outlive its own mistakes; the service depends on it.
                    $this->log('The keeper caught: ' . $error);
                }
                if ($this->current === null) {
                    $status = 1;
                    break;
                }
                usleep(100_000);
            }
        } finally {
            $this->shutDown();
            $this->pidFile->remove();
            $this->log('Stopped.');
        }
        return $status;
    }

    /**
     * From another process: asks the keeper to replace the generation that
     * serves, and waits to hear how it went.
     *
     * @param int $keeper The keeper's process id.
     * @param list<string> $command What starts the new generation, after the PHP binary.
     * @return array{ok: bool, message?: string, pid?: int, previous?: int}
     */
    public static function request(PidFile $pidFile, int $keeper, string $address, array $command, string $directory, float $timeout): array
    {
        $id = bin2hex(random_bytes(8));
        $request = $pidFile->path . '.reload';
        $answerFile = $pidFile->path . '.reloaded';
        @unlink($answerFile);
        self::write($request, ['id' => $id, 'address' => $address, 'command' => $command, 'directory' => $directory]);
        try {
            posix_kill($keeper, SIGUSR2);
            $deadline = microtime(true) + $timeout;
            while (true) {
                $answer = json_decode((string) @file_get_contents($answerFile), true);
                if (is_array($answer) && ($answer['id'] ?? null) === $id) {
                    return $answer;
                }
                if (!PidFile::alive($keeper)) {
                    return ['ok' => false, 'message' => "The server, pid {$keeper}, ended before it answered."];
                }
                if (microtime(true) > $deadline) {
                    return ['ok' => false, 'message' => "The server, pid {$keeper}, did not answer within {$timeout} seconds."];
                }
                usleep(20_000);
            }
        } finally {
            @unlink($request);
            @unlink($answerFile);
        }
    }

    /**
     * One turn of the keeper's loop: act on a request, collect what has
     * ended, and start the generation again if it ended by itself.
     */
    private function tend(): void
    {
        if ($this->replacing) {
            $this->replacing = false;
            $this->replace();
        }
        foreach ($this->retiring as $key => $old) {
            if (!proc_get_status($old['process'])['running']) {
                proc_close($old['process']);
                unset($this->retiring[$key]);
            } elseif (microtime(true) > $old['deadline']) {
                $this->log("Generation pid {$old['pid']} did not stop in time; ending it.");
                proc_terminate($old['process'], SIGKILL);
                $this->retiring[$key]['deadline'] = INF;
            }
        }
        if ($this->current === null || proc_get_status($this->current['process'])['running']) {
            return;
        }
        $ended = $this->current;
        $this->current = null;
        $exit = proc_close($ended['process']);
        $now = microtime(true);
        $this->failures = array_values(array_filter($this->failures, static fn (float $at): bool => $now - $at < 60));
        $this->failures[] = $now;
        if (count($this->failures) > 5) {
            $this->log("Generation pid {$ended['pid']} ended with status {$exit}, the sixth to do so within a minute. Giving up.");
            return;
        }
        $this->log("Generation pid {$ended['pid']} ended by itself with status {$exit}. Starting another.");
        // Connections wait in the socket's queue meanwhile; none is refused.
        usleep((int) (min(2.0, 0.1 * 2 ** (count($this->failures) - 1)) * 1e6));
        $next = $this->spawn($this->command, $this->directory, $this->socket);
        [$started, $message] = $this->await($next);
        if ($started) {
            $this->current = $next;
            $this->log("Serving again, generation pid {$next['pid']}.");
            return;
        }
        $this->end($next);
        $this->log("The new generation did not start: {$message} Giving up.");
    }

    /**
     * Replaces the serving generation as the request beside the process
     * id file says, and writes the answer there.
     */
    private function replace(): void
    {
        $request = json_decode((string) @file_get_contents($this->pidFile->path . '.reload'), true);
        if (!is_array($request) || !is_string($request['id'] ?? null) || !is_array($request['command'] ?? null)) {
            $this->log('A replacement was asked for, but its request could not be read.');
            return;
        }
        $id = $request['id'];
        $command = array_map(strval(...), array_values($request['command']));
        $directory = is_string($request['directory'] ?? null) && is_dir($request['directory']) ? $request['directory'] : $this->directory;
        $address = is_string($request['address'] ?? null) ? $request['address'] : $this->address;
        $socket = $this->socket;
        if ($address !== $this->address) {
            try {
                $socket = Listener::bind($address);
            } catch (\Throwable $error) {
                $this->answer($id, ['ok' => false, 'message' => $error->getMessage()]);
                return;
            }
        }
        $new = $this->spawn($command, $directory, $socket);
        [$started, $message] = $this->await($new);
        if (!$started) {
            $this->end($new);
            if ($socket !== $this->socket) {
                fclose($socket);
            }
            $this->log("A new generation did not start, and pid {$this->current['pid']} goes on serving: {$message}");
            $this->answer($id, ['ok' => false, 'message' => $message]);
            return;
        }
        $old = $this->current;
        $this->current = $new;
        $this->command = $command;
        $this->directory = $directory;
        if ($socket !== $this->socket) {
            fclose($this->socket);
            $this->socket = $socket;
            $this->address = $address;
        }
        // It has served until now; it finishes what it has in hand and goes.
        proc_terminate($old['process'], SIGTERM);
        $this->retiring[] = ['process' => $old['process'], 'pid' => $old['pid'], 'deadline' => microtime(true) + $this->stopTimeout];
        $this->log("Serving on {$address}, generation pid {$new['pid']}, taking over from {$old['pid']}.");
        $this->answer($id, ['ok' => true, 'pid' => $new['pid'], 'previous' => $old['pid']]);
    }

    /**
     * Starts a generation on the socket, with a pipe to hear from it.
     *
     * @param list<string> $command
     * @param resource $socket
     * @return array{process: resource, report: resource|null, pid: int}
     */
    private function spawn(array $command, string $directory, mixed $socket): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->log, 'a'],
            2 => ['file', $this->log, 'a'],
            self::SOCKET => $socket,
            self::REPORT => ['pipe', 'w'],
        ];
        $process = @proc_open([PHP_BINARY, ...$command], $descriptors, $pipes, $directory);
        if ($process === false) {
            throw new \RuntimeException('Could not start a PHP process for the server.');
        }
        stream_set_blocking($pipes[self::REPORT], false);
        return ['process' => $process, 'report' => $pipes[self::REPORT], 'pid' => proc_get_status($process)['pid']];
    }

    /**
     * Waits for a generation to say it accepts connections, or to fail.
     *
     * @param array{process: resource, report: resource|null, pid: int} $generation
     * @return array{bool, string} Whether it started, and what it said.
     */
    private function await(array &$generation): array
    {
        $deadline = microtime(true) + $this->startTimeout;
        $buffer = '';
        $result = null;
        while ($result === null) {
            $buffer .= (string) @fread($generation['report'], 8192);
            $end = strpos($buffer, "\n");
            if ($end !== false) {
                $report = json_decode(substr($buffer, 0, $end), true);
                $result = is_array($report) ? [(bool) $report[0], (string) $report[1]] : [false, 'It sent a report that could not be read.'];
            } elseif (!proc_get_status($generation['process'])['running']) {
                $result = [false, 'The server process ended before it accepted connections.'];
            } elseif ($this->stopping) {
                $result = [false, 'The server was told to stop while it was starting.'];
            } elseif (microtime(true) > $deadline) {
                $result = [false, "The server did not accept connections within {$this->startTimeout} seconds."];
            } else {
                usleep(10_000);
            }
        }
        fclose($generation['report']);
        $generation['report'] = null;
        return $result;
    }

    /**
     * Ends a generation that did not start, and collects it.
     *
     * @param array{process: resource, report: resource|null, pid: int} $generation
     */
    private function end(array $generation): void
    {
        if (is_resource($generation['report'])) {
            fclose($generation['report']);
        }
        if (proc_get_status($generation['process'])['running']) {
            proc_terminate($generation['process'], SIGTERM);
            $deadline = microtime(true) + 3.0;
            while (proc_get_status($generation['process'])['running']) {
                if (microtime(true) > $deadline) {
                    proc_terminate($generation['process'], SIGKILL);
                    break;
                }
                usleep(20_000);
            }
        }
        proc_close($generation['process']);
    }

    /**
     * Tells every generation to stop and waits for them, ending outright
     * any that takes longer than a graceful stop may.
     */
    private function shutDown(): void
    {
        $processes = array_column($this->retiring, 'process');
        if ($this->current !== null) {
            $processes[] = $this->current['process'];
        }
        $this->current = null;
        $this->retiring = [];
        foreach ($processes as $process) {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, SIGTERM);
            }
        }
        $deadline = microtime(true) + $this->stopTimeout;
        foreach ($processes as $process) {
            while (proc_get_status($process)['running']) {
                if (microtime(true) > $deadline) {
                    proc_terminate($process, SIGKILL);
                    break;
                }
                usleep(20_000);
            }
            proc_close($process);
        }
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function answer(string $id, array $answer): void
    {
        try {
            self::write($this->pidFile->path . '.reloaded', ['id' => $id] + $answer);
        } catch (\Throwable $error) {
            $this->log('The answer to a request could not be written: ' . $error->getMessage());
        }
    }

    /**
     * Writes a small file in one step, so that a reader never sees half of it.
     *
     * @param array<string, mixed> $content
     */
    private static function write(string $file, array $content): void
    {
        $temporary = $file . '.' . getmypid();
        if (@file_put_contents($temporary, json_encode($content, JSON_INVALID_UTF8_SUBSTITUTE)) === false || !@rename($temporary, $file)) {
            @unlink($temporary);
            throw new \RuntimeException("Could not write {$file}.");
        }
    }

    private function log(string $line): void
    {
        @file_put_contents($this->log, date('c') . ' [keeper ' . getmypid() . '] ' . $line . "\n", FILE_APPEND);
    }
}
