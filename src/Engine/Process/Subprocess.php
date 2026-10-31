<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\EventLoop;

/**
 * Runs a command to its end without blocking the event loop: other tasks
 * keep running while it works, and it can be cancelled or timed out.
 *
 *     $result = Subprocess::run(['git', 'status', '--short'], directory: $root, timeout: 10.0);
 *     if ($result->ok()) {
 *         echo $result->stdout;
 *     }
 *
 * The command is a list of arguments and never passes through a shell; a
 * caller that wants one names it (`['/bin/sh', '-c', $script]`). Where the
 * `setsid` program and PHP's posix extension are present the command runs
 * in a process group of its own, so nothing it starts outlives it: when it
 * ends, times out, writes too much or is cancelled, the whole group is
 * killed. Without them only the command itself is.
 */
final class Subprocess
{
    private static string|false|null $setsid = null;

    /**
     * @param list<string> $command The program and its arguments.
     * @param string|null $directory Where it runs; null for this process's working directory.
     * @param array<string, string>|null $environment Its whole environment; null to inherit this process's.
     * @param string|null $input What it reads on standard input; null gives it none.
     * @param float|null $timeout Seconds it may run before it is killed; null for no limit.
     * @param int $maxBytes How much it may write, standard output and error together, before it is killed.
     * @throws CancelledException When the cancellation fires first; the command is killed.
     */
    public static function run(
        array $command,
        ?string $directory = null,
        ?array $environment = null,
        ?string $input = null,
        ?float $timeout = 60.0,
        int $maxBytes = 1_048_576,
        ?Cancellation $cancellation = null,
        ?EventLoop $loop = null,
    ): SubprocessResult {
        if ($command === [] || !array_is_list($command)) {
            throw new \InvalidArgumentException('A subprocess needs a command given as a list of arguments.');
        }
        if ($timeout !== null && (!is_finite($timeout) || $timeout <= 0)) {
            throw new \InvalidArgumentException('A subprocess timeout is a number of seconds above zero, or null.');
        }
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException('A subprocess needs room for at least one byte of output.');
        }
        $cancellation?->throwIfCancelled();
        $loop ??= EventLoop::current();

        $setsid = self::setsid();
        $argv = $setsid === false ? $command : [$setsid, '--', ...$command];
        $pipes = [];
        $process = @proc_open($argv, [
            0 => $input === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $directory, $environment);
        if (!is_resource($process)) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException("Could not start {$command[0]}: {$error}");
        }
        $pid = (int) proc_get_status($process)['pid'];
        $group = $setsid !== false;

        $stdout = '';
        $stderr = '';
        $output = '';
        $exitCode = null;
        $exited = false;
        $timedOut = false;
        $truncated = false;
        $failure = null;
        $settled = false;
        $open = [1 => true, 2 => true];
        $watchers = [];
        $suspension = $loop->getSuspension();
        $unsubscribe = null;

        $kill = static function () use ($process, $pid, $group, &$exited): void {
            if ($group) {
                // The group outlives its leader: what the command started in the background goes too.
                @posix_kill(-$pid, 9);
            }
            if (!$exited) {
                @proc_terminate($process, 9);
            }
        };
        $finish = static function (?\Throwable $error = null) use (
            $loop,
            $suspension,
            &$settled,
            &$watchers,
            &$failure,
            &$unsubscribe,
        ): void {
            if ($settled) {
                return;
            }
            $settled = true;
            foreach ($watchers as $id) {
                $loop->cancel($id);
            }
            $watchers = [];
            $unsubscribe?->__invoke();
            $failure = $error;
            $suspension->resume();
        };
        $poll = static function () use ($process, &$exited, &$exitCode): bool {
            if ($exited) {
                return true;
            }
            $status = proc_get_status($process);
            if ($status['running']) {
                return false;
            }
            $exited = true;
            // proc_get_status() reports the code only once, on the call that sees the exit.
            $exitCode = $status['signaled'] ? null : (int) $status['exitcode'];
            return true;
        };
        $settle = static function () use ($loop, $poll, $finish, &$watchers, &$open, &$settled): void {
            // Output is closed; wait the moment it takes the command to be reaped.
            $wait = 0.0005;
            $check = static function () use (&$check, &$wait, $loop, $poll, $finish, &$watchers, &$settled): void {
                if ($settled) {
                    return;
                }
                if ($poll()) {
                    $finish();
                    return;
                }
                $wait = min($wait * 2, 0.02);
                $watchers['reap'] = $loop->delay($wait, $check);
            };
            $check();
        };

        foreach ([1, 2] as $fd) {
            stream_set_blocking($pipes[$fd], false);
            $watchers["read{$fd}"] = $loop->onReadable($pipes[$fd], static function () use (
                $fd,
                $pipes,
                $maxBytes,
                $loop,
                $kill,
                $finish,
                $settle,
                &$watchers,
                &$open,
                &$stdout,
                &$stderr,
                &$output,
                &$truncated,
            ): void {
                $chunk = @fread($pipes[$fd], 65_536);
                if ($chunk === false || ($chunk === '' && feof($pipes[$fd]))) {
                    $loop->cancel($watchers["read{$fd}"]);
                    unset($watchers["read{$fd}"]);
                    $open[$fd] = false;
                    if (!$open[1] && !$open[2]) {
                        $settle();
                    }
                    return;
                }
                $room = $maxBytes - strlen($output);
                if (strlen($chunk) > $room) {
                    $chunk = substr($chunk, 0, $room);
                    $truncated = true;
                }
                $output .= $chunk;
                if ($fd === 1) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
                if ($truncated) {
                    $kill();
                    $finish();
                }
            });
        }

        if ($input !== null) {
            stream_set_blocking($pipes[0], false);
            $offset = 0;
            if ($input === '') {
                fclose($pipes[0]);
            } else {
                $watchers['write'] = $loop->onWritable($pipes[0], static function () use (
                    $pipes,
                    $input,
                    $loop,
                    &$offset,
                    &$watchers,
                ): void {
                    $written = @fwrite($pipes[0], substr($input, $offset, 65_536));
                    if ($written !== false) {
                        $offset += $written;
                    }
                    // A command that stops reading early is not an error: it simply had enough.
                    if ($written === false || $offset >= strlen($input)) {
                        $loop->cancel($watchers['write']);
                        unset($watchers['write']);
                        @fclose($pipes[0]);
                    }
                });
            }
        }

        if ($timeout !== null) {
            $watchers['timeout'] = $loop->delay($timeout, static function () use ($kill, $finish, &$timedOut): void {
                $timedOut = true;
                $kill();
                $finish();
            });
        }
        // A command that exits while something it started still holds its output open.
        $watchers['exit'] = $loop->repeat(0.05, static function () use ($loop, $poll, $kill, $finish, &$watchers): void {
            if (!$poll()) {
                return;
            }
            $loop->cancel($watchers['exit']);
            unset($watchers['exit']);
            $watchers['drain'] = $loop->delay(0.05, static function () use ($kill, $finish): void {
                $kill();
                $finish();
            });
        });
        $unsubscribe = $cancellation?->subscribe(static function (CancelledException $error) use ($kill, $finish): void {
            $kill();
            $finish($error);
        });

        try {
            $suspension->suspend();
        } finally {
            if (!$settled) {
                $settled = true;
                foreach ($watchers as $id) {
                    $loop->cancel($id);
                }
                $unsubscribe?->__invoke();
            }
            $poll();
            if (!$exited || $group) {
                $kill();
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            proc_close($process);
        }
        if ($failure !== null) {
            throw $failure;
        }
        return new SubprocessResult(
            $timedOut || $truncated ? null : $exitCode,
            $stdout,
            $stderr,
            $output,
            $timedOut,
            $truncated,
        );
    }

    /**
     * The `setsid` program, when it and the posix extension to signal a group are both here.
     */
    private static function setsid(): string|false
    {
        if (self::$setsid !== null) {
            return self::$setsid;
        }
        if (!function_exists('posix_kill')) {
            return self::$setsid = false;
        }
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $path) {
            if (is_executable($path)) {
                return self::$setsid = $path;
            }
        }
        return self::$setsid = false;
    }
}
