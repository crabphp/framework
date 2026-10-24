<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

/**
 * The file a server in the background writes its process id to, so that
 * a later command can find it. It holds the number and nothing else.
 */
final readonly class PidFile
{
    public function __construct(
        public string $path,
    ) {}

    /**
     * @return int|null The id in the file when a process of that id is alive; null when there is no file or what it names has gone.
     */
    public function pid(): ?int
    {
        $content = @file_get_contents($this->path);
        if ($content === false) {
            return null;
        }
        $pid = (int) trim($content);
        return $pid > 0 && self::alive($pid) ? $pid : null;
    }

    /**
     * Writes this process's id, replacing the file in one step so that a
     * reader never sees it half written.
     */
    public function write(): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException("The directory for the process id file, {$directory}, cannot be created.");
        }
        $temporary = $this->path . '.' . getmypid();
        if (@file_put_contents($temporary, getmypid() . "\n") === false || !@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new \RuntimeException("The process id file {$this->path} cannot be written.");
        }
    }

    /**
     * Removes the file when it still names this process. A server that
     * was replaced leaves the file to the one that replaced it.
     */
    public function remove(): void
    {
        $content = @file_get_contents($this->path);
        if ($content !== false && (int) trim($content) === getmypid()) {
            @unlink($this->path);
        }
    }

    /**
     * Whether a process of this id exists, including one that belongs to
     * another user.
     */
    public static function alive(int $pid): bool
    {
        if ($pid === 0 || $pid === -1) {
            return false;
        }
        if (!function_exists('posix_kill')) {
            return true;
        }
        // Signal 0 is not sent; it only asks whether it could be.
        return posix_kill($pid, 0) || posix_get_last_error() === 1;
    }

    /**
     * Checks the keeper's recorded birth identity before a stale pid can
     * select another process. Older state, or an unavailable OS identity,
     * leaves the caller's existing command check in charge.
     */
    public function verify(int $pid): void
    {
        $state = json_decode((string) @file_get_contents($this->path . '.state'), true);
        $expected = is_array($state) && ($state['pid'] ?? null) === $pid ? $state['identity'] ?? null : null;
        if (!is_string($expected) || $expected === '') {
            return;
        }
        $actual = self::identity($pid);
        if ($actual !== null && $actual !== $expected) {
            throw new \RuntimeException(
                "The server pid file {$this->path} is stale: pid {$pid} belongs to a process started at another time. Remove the stale pid file before starting the server.",
            );
        }
    }

    /**
     * When the keeper of this pid started, as it recorded; null when the
     * state it keeps beside the file is missing or names another process.
     */
    public function started(int $pid): ?float
    {
        $state = json_decode((string) @file_get_contents($this->path . '.state'), true);
        $started = is_array($state) && ($state['pid'] ?? null) === $pid ? $state['started'] ?? null : null;
        return is_float($started) || is_int($started) ? (float) $started : null;
    }

    /**
     * The command the keeper of this pid starts its server processes with,
     * after the PHP binary; null when its state is missing or names another process.
     *
     * @return list<string>|null
     */
    public function command(int $pid): ?array
    {
        $state = json_decode((string) @file_get_contents($this->path . '.state'), true);
        $command = is_array($state) && ($state['pid'] ?? null) === $pid ? $state['command'] ?? null : null;
        return is_array($command) && array_is_list($command) ? array_map(strval(...), $command) : null;
    }

    /**
     * The OS start identity, not elapsed time: Linux's start ticks or
     * ps's fixed start date on systems without /proc.
     */
    public static function identity(int $pid): ?string
    {
        if (is_dir('/proc/self')) {
            return self::linuxStat($pid)[19] ?? null;
        }
        if (!function_exists('exec')) {
            return null;
        }
        @exec('LC_ALL=C ps -o lstart= -p ' . $pid . ' 2>/dev/null', $lines, $status);
        $identity = trim(implode(' ', $lines));
        return $status === 0 && $identity !== '' ? $identity : null;
    }

    /**
     * A zombie has finished executing; its parent still has to collect it.
     */
    public static function zombie(int $pid): bool
    {
        if (is_dir('/proc/self')) {
            return (self::linuxStat($pid)[0] ?? null) === 'Z';
        }
        if (!function_exists('exec')) {
            return false;
        }
        @exec('ps -o stat= -p ' . $pid . ' 2>/dev/null', $lines, $status);
        return $status === 0 && str_starts_with(trim(implode(' ', $lines)), 'Z');
    }

    /**
     * Whether this process still executes, rather than awaiting collection.
     */
    public static function running(int $pid): bool
    {
        return self::alive($pid) && !self::zombie($pid);
    }

    /**
     * Live members of an isolated keeper's group, sampled from the OS.
     * Zombies cannot serve or hold sockets and do not prevent completion.
     * Null means the system could not supply a process table.
     *
     * @return list<int>|null
     */
    public static function groupMembers(int $leader): ?array
    {
        if ($leader <= 1) {
            throw new \InvalidArgumentException('A server process group must have a leader pid greater than one.');
        }
        $members = [];
        if (is_dir('/proc/self')) {
            foreach (glob('/proc/[0-9]*/stat') ?: [] as $file) {
                $pid = (int) basename(dirname($file));
                $stat = self::linuxStat($pid);
                if ($stat !== null && (int) $stat[2] === $leader && $stat[0] !== 'Z') {
                    $members[] = $pid;
                }
            }
        } else {
            if (!function_exists('exec')) {
                return null;
            }
            @exec('ps -axo pid=,pgid=,stat= 2>/dev/null', $lines, $status);
            if ($status !== 0) {
                return null;
            }
            foreach ($lines as $line) {
                $fields = preg_split('/\s+/', trim($line));
                if (count($fields) === 3 && (int) $fields[1] === $leader && !str_starts_with($fields[2], 'Z')) {
                    $members[] = (int) $fields[0];
                }
            }
        }
        sort($members);
        return $members;
    }

    /**
     * Fields after the command name in Linux stat. The name may contain
     * spaces or parentheses, so splitting the whole line is incorrect.
     *
     * @return list<string>|null
     */
    private static function linuxStat(int $pid): ?array
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        $end = $stat === false ? false : strrpos($stat, ')');
        if ($end === false) {
            return null;
        }
        $fields = explode(' ', trim(substr($stat, $end + 2)));
        return count($fields) >= 20 ? $fields : null;
    }
}
