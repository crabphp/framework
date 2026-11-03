<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

/**
 * What another process, such as `stop`, `status` or `reload`, asks of a
 * running Keeper through its pid file and the files beside it: to replace
 * its generation, how long it needs to stop, and to be forced.
 */
final readonly class KeeperClient
{
    public function __construct(
        private PidFile $pidFile,
    ) {}

    /**
     * Asks the keeper to replace the generation that
     * serves, and waits to hear how it went.
     *
     * @param int $keeper The keeper's process id.
     * @param list<string> $command What starts the new generation, after the PHP binary.
     * @param float|null $stopTimeout Seconds the new generation needs to stop; null keeps the current allowance.
     * @param list<string> $held The names of what the held generation holds as the new code has them; a reload is refused when they differ from what runs.
     * @return array{ok: bool, message?: string, pid?: int, previous?: int}
     */
    public function request(
        int $keeper,
        string $address,
        array $command,
        string $directory,
        float $timeout,
        ?float $stopTimeout = null,
        array $held = [],
    ): array {
        if ($stopTimeout !== null && (!is_finite($stopTimeout) || $stopTimeout <= 0)) {
            throw new \InvalidArgumentException(
                'Set the generation stop timeout to a finite number of seconds greater than zero.',
            );
        }
        $id = bin2hex(random_bytes(8));
        $request = $this->pidFile->path . '.reload';
        $answerFile = $this->pidFile->path . '.reloaded';
        @unlink($answerFile);
        Keeper::write($request, [
            'id' => $id,
            'address' => $address,
            'command' => $command,
            'directory' => $directory,
            'stopTimeout' => $stopTimeout,
            'held' => $held,
        ]);
        try {
            posix_kill($keeper, SIGUSR2);
            $deadline = microtime(true) + $timeout;
            while (true) {
                $answer = json_decode((string) @file_get_contents($answerFile), true);
                if (is_array($answer) && ($answer['id'] ?? null) === $id) {
                    return $answer;
                }
                if (!PidFile::running($keeper)) {
                    return [
                        'ok' => false,
                        'message' => "The server, pid {$keeper}, ended before it answered; it is no longer running.",
                    ];
                }
                if (microtime(true) > $deadline) {
                    return [
                        'ok' => false,
                        'message' => "The server, pid {$keeper}, did not answer within {$timeout} seconds.",
                    ];
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
            throw new \RuntimeException(
                "Refusing to force pid {$pid}: a keeper pid must be greater than one. Check the server pid file.",
            );
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
            throw new \RuntimeException(
                "Ended only the keeper, pid {$pid}, because it did not lead its own session.{$collected} Its workers may still be running and are not this command's to signal. Whatever started the server must end them.",
            );
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
            if ($members === [] || $members === null && !PidFile::alive(-$pid)) {
                return;
            }
            if (microtime(true) >= $deadline) {
                $remaining = $members === null ? 'could not be listed' : 'are ' . implode(', ', $members);
                $collected = PidFile::zombie($pid)
                    ? " The keeper, pid {$pid}, has ended and is waiting to be collected by its parent."
                    : '';
                throw new \RuntimeException(
                    "The server's process group {$pid} still has running processes; their pids {$remaining}.{$collected} Whatever started the server must end them before another server is started.",
                );
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
            if (
                !is_int($generation['pid'] ?? null)
                || $generation['pid'] <= 0
                || !array_key_exists('deadline', $generation)
                || ($deadline === null ? !$validTimeout : !$validDeadline)
            ) {
                return null;
            }
        }
        return $state;
    }
}
