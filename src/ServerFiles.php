<?php

declare(strict_types=1);

namespace Crab;

use Crab\Engine\Process\Keeper;
use Crab\Engine\Process\PidFile;
use Crab\Engine\Process\StatsFile;

/**
 * What a server keeps on disk of itself, and what can be done from that
 * alone: find the server in the background, read how each running server
 * is doing, and stop it. The keeper's process id is in "server.pid", with
 * its state beside it; each running server rewrites a stats file in the
 * folder named after it; what a server in the background prints goes to
 * "server.log".
 *
 * None of it needs the application, so `status` and `stop` still reach a
 * running server when the application on disk cannot be loaded; see Cua.
 */
final readonly class ServerFiles
{
    /**
     * Where "server.pid" and "server.log" are by default, inside the application's folder.
     */
    public const PID = 'storage/crab.pid';
    public const LOG = 'storage/logs/server.log';

    /**
     * @param string $pid The keeper's process id file.
     * @param string $log What a server in the background prints to.
     * @param string|null $stats Where running servers keep their stats files; null for an application with nowhere of its own.
     * @param \Closure(): float|null $grace Seconds a server is given to stop when its keeper did not record them; null to require `--timeout` then.
     */
    public function __construct(
        public string $pid,
        public string $log,
        public ?string $stats,
        private ?\Closure $grace = null,
    ) {}

    /**
     * The files at their default places inside an application's folder.
     */
    public static function in(string $directory): self
    {
        return new self(
            $directory . '/' . self::PID,
            $directory . '/' . self::LOG,
            self::statsBeside($directory . '/' . self::PID),
        );
    }

    /**
     * The same, but for a server whose process id file is elsewhere: its
     * stats are beside that file, and its log stays where it was.
     */
    public function withPid(string $pid): self
    {
        return new self($pid, $this->log, self::statsBeside($pid), $this->grace);
    }

    /**
     * "storage/crab.pid" keeps its stats in "storage/crab.stats/".
     */
    public static function statsBeside(string $pid): string
    {
        return (string) preg_replace('/\.pid$/', '', $pid) . '.stats';
    }

    public function pidFile(): PidFile
    {
        return new PidFile($this->pid);
    }

    /**
     * The servers running now, in the foreground or the background, each
     * as its stats file says; see StatsFile.
     *
     * @return list<array<string, mixed>>
     */
    public function stats(): array
    {
        return $this->stats === null ? [] : StatsFile::read($this->stats);
    }

    /**
     * @return int|null The process id of the server in the background, or null when there is none.
     */
    public function running(): ?int
    {
        $pidFile = $this->pidFile();
        $pid = $pidFile->pid();
        if ($pid !== null) {
            $pidFile->verify($pid);
        }
        return $pid !== null && self::isServer($pid) ? $pid : null;
    }

    /**
     * The options the server in the background was started with on the
     * command line, or last reloaded with: a reload or restart starts it
     * the same way unless told otherwise. Empty when none is running, or
     * when it was started by a version that did not record them.
     *
     * @return array{address?: string, workers?: string}
     */
    public function given(): array
    {
        $pid = $this->running();
        $command = $pid === null ? null : $this->pidFile()->command($pid);
        $given = [];
        foreach (array_slice($command ?? [], (int) array_search('--generation', $command ?? [], true)) as $argument) {
            if (preg_match('/^--(address|workers)=(.*)$/sD', $argument, $match) !== 1) {
                continue;
            }
            $given[$match[1]] = $match[2];
        }
        return $given;
    }

    /**
     * Tells the server in the background to finish what it has in hand
     * and stop, and waits until it has.
     *
     * @param float|null $timeout Seconds to wait for a graceful stop; null reads the running keeper's allowance.
     * @param bool $force Whether to cut requests and end every server process now.
     * @return int|null The process id of the server that stopped, or null when none was running.
     */
    public function stop(?float $timeout = null, bool $force = false): ?int
    {
        $pidFile = $this->pidFile();
        $pid = $this->running();
        if ($pid === null) {
            return null;
        }
        self::requireSignals();
        $keeper = new Keeper($pidFile, $this->log);
        if ($force) {
            try {
                $keeper->force($pid);
            } finally {
                // A limited force also removes the ended keeper's numeric pid,
                // while preserving the error that its workers were not forced.
                if (!PidFile::running($pid) && (int) trim((string) @file_get_contents($pidFile->path)) === $pid) {
                    @unlink($pidFile->path);
                }
            }
        } else {
            $timeout ??= $keeper->allowance($pid) ?? ($this->grace === null ? null : ($this->grace)());
            if ($timeout === null) {
                throw new \RuntimeException(
                    "The server, pid {$pid}, did not record how long it needs to stop, and without the application that cannot be worked out. Give it with --timeout=30.",
                );
            }
            $deadline = microtime(true) + $timeout;
            $ownsGroup = $pid > 1 && posix_getsid($pid) === $pid;
            posix_kill($pid, SIGTERM);
            if (!self::waitForExit($pid, $timeout)) {
                throw new \RuntimeException(
                    "The server, pid {$pid}, has not stopped after {$timeout} seconds. It may still be finishing requests; `stop --force` ends it outright.",
                );
            }
            if ($ownsGroup) {
                $keeper->waitForGroup($pid, max(0.0, $deadline - microtime(true)));
            }
        }
        if (PidFile::zombie($pid)) {
            echo "The server, pid {$pid}, has ended and is waiting to be collected by its parent.\n";
        }
        // A server that was ended outright could not remove its own file.
        if ((int) trim((string) @file_get_contents($pidFile->path)) === $pid) {
            @unlink($pidFile->path);
        }
        return $pid;
    }

    /**
     * @internal For Server, which signals the keeper too.
     */
    public static function requireSignals(): void
    {
        if (!function_exists('posix_kill')) {
            throw new \RuntimeException('Controlling a server in the background needs PHP\'s posix extension.');
        }
    }

    private static function waitForExit(int $pid, float $timeout): bool
    {
        $deadline = microtime(true) + $timeout;
        while (PidFile::running($pid)) {
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
        foreach ([' start ', ' reload ', ' restart '] as $started) {
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
}
