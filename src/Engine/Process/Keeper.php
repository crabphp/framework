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
     * @var array{process: resource, report: resource|null, pid: int, stopTimeout: float}|null
     */
    private ?array $current = null;
    /**
     * A generation being started remains owned if a stop interrupts startup.
     *
     * @var array{process: resource, report: resource|null, pid: int, stopTimeout: float}|null
     */
    private ?array $starting = null;
    /**
     * Generations told to stop, with the time by which each must have.
     *
     * @var array<int, array{process: resource, pid: int, deadline: float, forced: bool}>
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
    private bool $forcing = false;
    private ?string $identity = null;

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
        $this->identity = PidFile::identity(getmypid());
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void { $this->stopping = true; });
        pcntl_signal(SIGINT, function (): void { $this->stopping = true; });
        pcntl_signal(SIGQUIT, function (): void {
            $this->forcing = $this->stopping = true;
        });
        pcntl_signal(SIGUSR2, function (): void { $this->replacing = true; });
        // The reload signal of a server process: passed on to the one that serves.
        pcntl_signal(SIGUSR1, function (): void {
            if ($this->current !== null) {
                posix_kill($this->current['pid'], SIGUSR1);
            }
        });
        $status = 0;
        try {
            $this->pidFile->write();
            $this->starting = $this->spawn($this->command, $this->directory, $this->socket, $this->stopTimeout);
            $this->publish();
            [$started, $message] = $this->await($this->starting);
            if (!$started) {
                if ($this->stopping) {
                    return 0;
                }
                $this->end($this->starting);
                throw new \RuntimeException($message);
            }
            $this->current = $this->starting;
            $this->starting = null;
            $this->publish();
            $this->log("Serving on {$address}, generation pid {$this->current['pid']}.");
            $ready();
            while (!$this->stopping) {
                try {
                    $this->tend();
                } catch (\Throwable $error) {
                    // The keeper has to outlive its own mistakes; the service depends on it.
                    $this->log('The keeper caught: ' . $error);
                }
                if ($this->current === null && !$this->stopping) {
                    $status = 1;
                    break;
                }
                usleep(100_000);
            }
        } finally {
            $this->shutDown();
            if ($this->state(getmypid()) !== null) {
                @unlink($this->pidFile->path . '.state');
            }
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
     * @param float|null $stopTimeout Seconds the new generation needs to stop; null keeps the current allowance.
     * @return array{ok: bool, message?: string, pid?: int, previous?: int}
     */
    public static function request(PidFile $pidFile, int $keeper, string $address, array $command, string $directory, float $timeout, ?float $stopTimeout = null): array
    {
        if ($stopTimeout !== null && (!is_finite($stopTimeout) || $stopTimeout <= 0)) {
            throw new \InvalidArgumentException('Set the generation stop timeout to a finite number of seconds greater than zero.');
        }
        $id = bin2hex(random_bytes(8));
        $request = $pidFile->path . '.reload';
        $answerFile = $pidFile->path . '.reloaded';
        @unlink($answerFile);
        self::write($request, ['id' => $id, 'address' => $address, 'command' => $command, 'directory' => $directory, 'stopTimeout' => $stopTimeout]);
        try {
            posix_kill($keeper, SIGUSR2);
            $deadline = microtime(true) + $timeout;
            while (true) {
                $answer = json_decode((string) @file_get_contents($answerFile), true);
                if (is_array($answer) && ($answer['id'] ?? null) === $id) {
                    return $answer;
                }
                if (!PidFile::running($keeper)) {
                    return ['ok' => false, 'message' => "The server, pid {$keeper}, ended before it answered; it is no longer running."];
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
     * Reads the longest remaining stop allowance of this running keeper.
     * A missing record belongs to an older server and leaves the fallback to its caller.
     */
    public function allowance(int $pid): ?float
    {
        $state = $this->state($pid);
        if ($state === null) {
            return null;
        }
        $seconds = 0.0;
        foreach ($state['generations'] as $generation) {
            $remaining = $generation['deadline'] === null
                ? $generation['stopTimeout']
                : max(0.0, $generation['deadline'] - microtime(true));
            $seconds = max($seconds, $remaining);
        }
        // The command must also allow delivery of the stop and collection of children.
        return $seconds + 1.0;
    }

    /**
     * Asks the keeper to force its generations and collect them. A stopped
     * or hung keeper gets one second to answer before its isolated session's
     * group is ended. A shell job's group is not this command's to signal.
     */
    public function force(int $pid): void
    {
        if ($pid <= 1) {
            throw new \RuntimeException("Refusing to force pid {$pid}: a keeper pid must be greater than one. Check the server pid file.");
        }
        $this->pidFile->verify($pid);
        $ownsGroup = posix_getsid($pid) === $pid;
        posix_kill($pid, SIGQUIT);
        $stopped = $this->wait($pid, 1.0);
        if ($ownsGroup) {
            // A crashed supervisor may have left workers in the group even
            // after the keeper collected every generation and exited.
            if (!$stopped || PidFile::groupMembers($pid) !== []) {
                $this->pidFile->verify($pid);
                posix_kill(-$pid, SIGKILL);
            }
        } elseif (!$stopped) {
            posix_kill($pid, SIGKILL);
        }
        $deadline = microtime(true) + 2.0;
        if (!$this->wait($pid, 2.0)) {
            throw new \RuntimeException("The keeper, pid {$pid}, has not exited after force. Check its process owner.");
        }
        if ($this->state($pid) !== null) {
            @unlink($this->pidFile->path . '.state');
        }
        if ($ownsGroup) {
            $this->waitForGroup($pid, max(0.0, $deadline - microtime(true)));
        }
        if (!$ownsGroup) {
            $collected = PidFile::zombie($pid) ? ' It has ended and is waiting to be collected by its parent.' : '';
            throw new \RuntimeException("Ended only the keeper, pid {$pid}, because it did not lead its own session.{$collected} Its workers may still be running and are not this command's to signal. Whatever started the server must end them.");
        }
    }

    /**
     * Waits within the caller's remaining allowance for executing group
     * members, including workers left by a crashed supervisor. No pid list
     * is retained or used to signal them.
     */
    public function waitForGroup(int $pid, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            $members = PidFile::groupMembers($pid);
            if ($members === [] || ($members === null && !PidFile::alive(-$pid))) {
                return;
            }
            if (microtime(true) >= $deadline) {
                $remaining = $members === null ? 'could not be listed' : 'are ' . implode(', ', $members);
                $collected = PidFile::zombie($pid) ? " The keeper, pid {$pid}, has ended and is waiting to be collected by its parent." : '';
                throw new \RuntimeException("The server's process group {$pid} still has running processes; their pids {$remaining}.{$collected} Whatever started the server must end them before another server is started.");
            }
            usleep(50_000);
        } while (true);
    }

    /**
     * A finite wait for a process to finish executing, even if its parent
     * has not yet collected the ended process.
     */
    private function wait(int $pid, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (PidFile::running($pid)) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(10_000);
        }
        return true;
    }

    /**
     * A record is useful only for the keeper whose pid the caller checked.
     *
     * @return array{pid: int, generations: list<array{pid: int, stopTimeout: float|null, deadline: float|null}>}|null
     */
    private function state(int $pid): ?array
    {
        $state = json_decode((string) @file_get_contents($this->pidFile->path . '.state'), true);
        if (!is_array($state) || ($state['pid'] ?? null) !== $pid || !is_array($state['generations'] ?? null)) {
            return null;
        }
        foreach ($state['generations'] as $generation) {
            if (!is_array($generation)) {
                return null;
            }
            $timeout = $generation['stopTimeout'] ?? null;
            $deadline = $generation['deadline'] ?? null;
            $validTimeout = is_numeric($timeout) && is_finite((float) $timeout) && $timeout > 0;
            $validDeadline = is_numeric($deadline) && is_finite((float) $deadline);
            if (!is_int($generation['pid'] ?? null) || $generation['pid'] <= 0
                || !array_key_exists('deadline', $generation) || ($deadline === null ? !$validTimeout : !$validDeadline)) {
                return null;
            }
        }
        return $state;
    }

    /**
     * Publishes allowances separately from the numeric pid file. Deadlines
     * stay absolute, so a command can calculate remaining time without polling writes.
     */
    private function publish(): void
    {
        $generations = [];
        foreach ($this->retiring as $generation) {
            $generations[$generation['pid']] = [
                'pid' => $generation['pid'], 'stopTimeout' => null,
                'deadline' => is_finite($generation['deadline']) ? $generation['deadline'] : 0.0,
            ];
        }
        foreach ([$this->current, $this->starting] as $generation) {
            if ($generation !== null) {
                $generations[$generation['pid']] = [
                    'pid' => $generation['pid'], 'stopTimeout' => $generation['stopTimeout'],
                    'deadline' => null,
                ];
            }
        }
        self::write($this->pidFile->path . '.state', ['pid' => getmypid(), 'identity' => $this->identity, 'generations' => array_values($generations)]);
    }

    /**
     * Moves an owned generation into the common deadline pass immediately.
     *
     * @param array{process: resource, pid: int} $generation
     */
    private function retire(array $generation, float $seconds): void
    {
        $this->retiring[$generation['pid']] = [
            'process' => $generation['process'], 'pid' => $generation['pid'],
            'deadline' => microtime(true) + ($this->forcing ? 1.0 : $seconds),
            'forced' => $this->forcing,
        ];
        proc_terminate($generation['process'], $this->forcing ? SIGQUIT : SIGTERM);
        $this->publish();
    }

    /**
     * The same collection pass runs during startup, failed-start cleanup,
     * restart backoff and final shutdown; none can hide a retiree's deadline.
     */
    private function collectRetiring(): void
    {
        $changed = false;
        foreach ($this->retiring as $pid => $generation) {
            if (!proc_get_status($generation['process'])['running']) {
                proc_close($generation['process']);
                unset($this->retiring[$pid]);
                $changed = true;
                continue;
            }
            if ($this->forcing && !$generation['forced']) {
                proc_terminate($generation['process'], SIGQUIT);
                posix_kill($pid, SIGCONT);
                $this->retiring[$pid]['forced'] = true;
                $this->retiring[$pid]['deadline'] = microtime(true) + 1.0;
                $changed = true;
            } elseif (microtime(true) >= $generation['deadline']) {
                if (!$this->forcing) {
                    $this->log("Generation pid {$pid} did not stop in time; ending it.");
                }
                proc_terminate($generation['process'], SIGKILL);
                $this->retiring[$pid]['deadline'] = INF;
                $changed = true;
            }
        }
        if ($changed) {
            $this->publish();
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
        $this->collectRetiring();
        if ($this->stopping) {
            return;
        }
        if ($this->current === null || proc_get_status($this->current['process'])['running']) {
            return;
        }
        $ended = $this->current;
        $this->current = null;
        $exit = proc_close($ended['process']);
        $this->publish();
        $now = microtime(true);
        $this->failures = array_values(array_filter($this->failures, static fn (float $at): bool => $now - $at < 60));
        $this->failures[] = $now;
        if (count($this->failures) > 5) {
            $this->log("Generation pid {$ended['pid']} ended with status {$exit}, the sixth to do so within a minute. Giving up.");
            return;
        }
        $this->log("Generation pid {$ended['pid']} ended by itself with status {$exit}. Starting another.");
        // Connections wait in the socket's queue meanwhile; none is refused.
        $restartAt = microtime(true) + min(2.0, 0.1 * 2 ** (count($this->failures) - 1));
        while (!$this->stopping && microtime(true) < $restartAt) {
            $this->collectRetiring();
            usleep(10_000);
        }
        if ($this->stopping) {
            return;
        }
        $this->starting = $this->spawn($this->command, $this->directory, $this->socket, $ended['stopTimeout']);
        $this->publish();
        [$started, $message] = $this->await($this->starting);
        if ($started) {
            $this->current = $this->starting;
            $this->starting = null;
            $this->publish();
            $this->log("Serving again, generation pid {$this->current['pid']}.");
            return;
        }
        if ($this->stopping) {
            return;
        }
        $this->end($this->starting);
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
        $stopTimeout = $request['stopTimeout'] ?? $this->current['stopTimeout'];
        if (!is_numeric($stopTimeout) || !is_finite((float) $stopTimeout) || (float) $stopTimeout <= 0) {
            $this->answer($id, ['ok' => false, 'message' => 'Set the generation stop timeout to a finite number of seconds greater than zero.']);
            return;
        }
        $stopTimeout = (float) $stopTimeout;
        $socket = $this->socket;
        if ($address !== $this->address) {
            try {
                $socket = Listener::bind($address);
            } catch (\Throwable $error) {
                $this->answer($id, ['ok' => false, 'message' => $error->getMessage()]);
                return;
            }
        }
        $this->starting = $this->spawn($command, $directory, $socket, $stopTimeout);
        $this->publish();
        [$started, $message] = $this->await($this->starting);
        if (!$started) {
            if (!$this->stopping) {
                $this->end($this->starting);
            }
            if ($socket !== $this->socket) {
                fclose($socket);
            }
            if ($this->stopping) {
                $this->answer($id, ['ok' => false, 'message' => $message]);
                return;
            }
            $this->log("A new generation did not start, and pid {$this->current['pid']} goes on serving: {$message}");
            $this->answer($id, ['ok' => false, 'message' => $message]);
            return;
        }
        $old = $this->current;
        $new = $this->starting;
        $this->current = $new;
        $this->starting = null;
        $this->command = $command;
        $this->directory = $directory;
        if ($socket !== $this->socket) {
            fclose($this->socket);
            $this->socket = $socket;
            $this->address = $address;
        }
        // It has served until now; it finishes what it has in hand and goes.
        $this->retire($old, $old['stopTimeout']);
        $this->log("Serving on {$address}, generation pid {$new['pid']}, taking over from {$old['pid']}.");
        $this->answer($id, ['ok' => true, 'pid' => $new['pid'], 'previous' => $old['pid']]);
    }

    /**
     * Starts a generation on the socket, with a pipe to hear from it.
     *
     * @param list<string> $command
     * @param resource $socket
     * @return array{process: resource, report: resource|null, pid: int, stopTimeout: float}
     */
    private function spawn(array $command, string $directory, mixed $socket, float $stopTimeout): array
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
        return ['process' => $process, 'report' => $pipes[self::REPORT], 'pid' => proc_get_status($process)['pid'], 'stopTimeout' => $stopTimeout];
    }

    /**
     * Waits for a generation to say it accepts connections, or to fail.
     *
     * @param array{process: resource, report: resource|null, pid: int, stopTimeout: float} $generation
     * @return array{bool, string} Whether it started, and what it said.
     */
    private function await(array &$generation): array
    {
        $deadline = microtime(true) + $this->startTimeout;
        $buffer = '';
        $result = null;
        while ($result === null) {
            $this->collectRetiring();
            if ($this->stopping) {
                $result = [false, 'The server was told to stop while it was starting.'];
                break;
            }
            $buffer .= (string) @fread($generation['report'], 8192);
            $end = strpos($buffer, "\n");
            if ($end !== false) {
                $report = json_decode(substr($buffer, 0, $end), true);
                $result = is_array($report) ? [(bool) $report[0], (string) $report[1]] : [false, 'It sent a report that could not be read.'];
            } elseif (!proc_get_status($generation['process'])['running']) {
                $result = [false, 'The server process ended before it accepted connections.'];
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
     * @param array{process: resource, report: resource|null, pid: int, stopTimeout: float} $generation
     */
    private function end(array $generation): void
    {
        if (is_resource($generation['report'])) {
            fclose($generation['report']);
        }
        $this->starting = null;
        $this->retire($generation, min(3.0, $generation['stopTimeout']));
        while (!$this->stopping && isset($this->retiring[$generation['pid']])) {
            $this->collectRetiring();
            usleep(10_000);
        }
    }

    /**
     * Tells every generation to stop and waits for them, ending outright
     * any that takes longer than a graceful stop may.
     */
    private function shutDown(): void
    {
        foreach ([$this->current, $this->starting] as $generation) {
            if ($generation !== null) {
                if (is_resource($generation['report'])) {
                    fclose($generation['report']);
                }
                $this->retire($generation, $generation['stopTimeout']);
            }
        }
        $this->current = null;
        $this->starting = null;
        $this->publish();
        while ($this->retiring !== []) {
            $this->collectRetiring();
            usleep(10_000);
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
