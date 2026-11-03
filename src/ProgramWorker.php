<?php

declare(strict_types=1);

namespace Crab;

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Process\Interpreter;
use Crab\Engine\Process\Worker;

/**
 * A server beside HTTP that is a program of its own, such as a command of
 * the application or `npm run dev`: what Servers registers as a process or
 * a command line, and what App::alongside() runs in a worker.
 */
final class ProgramWorker
{
    /**
     * Seconds a program run beside the server is given to finish when the server stops.
     */
    private const GRACE = 30.0;

    /**
     * Work that keeps a program running in a process of its own: started
     * with the worker, told to stop (SIGTERM) when the worker stops, and
     * given time to finish. What it prints is passed on line by line
     * with its id in front, so that several can be told apart. The
     * worker ends when the program does, and is then started again, as
     * any worker is.
     *
     * Under an isolated daemon keeper the program stays in the server's
     * group, so force reaches it even when its worker cannot clean up.
     * Foreground programs retain their own group for ordinary cleanup.
     *
     * @param list<string> $command The program and its arguments.
     * @return \Closure(Worker, Cancellation): void
     */
    public static function for(string $id, array $command): \Closure
    {
        $keeper = function_exists('posix_getppid') ? posix_getppid() : 0;
        $sharedGroup =
            in_array('--generation', $_SERVER['argv'] ?? [], true)
            && $keeper > 1
            && posix_getsid($keeper) === $keeper
            && posix_getpgid(0) === $keeper;
        return static function (Worker $worker, Cancellation $cancellation) use ($id, $command, $sharedGroup): void {
            $detach = $sharedGroup ? '' : 'posix_setsid(); ';
            $launcher = Interpreter::selfContained()
                // No -r in a self-contained executable: it launches through itself; see Cua::launch().
                ? [$command[0], Cua::LAUNCH, $sharedGroup ? 'keep' : 'detach', ...$command]
                : [
                    PHP_BINARY,
                    '-r',
                    $detach
                        . 'pcntl_exec($argv[1], array_slice($argv, 2)); fwrite(STDERR, "could not run {$argv[1]}\n"); exit(127);',
                    '--',
                    ...$command,
                ];
            $process = proc_open($launcher, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            if ($process === false) {
                throw new \RuntimeException("Could not start the server \"{$id}\".");
            }
            $group = (int) proc_get_status($process)['pid'];
            // Each line it prints, as it comes, under its id.
            $watchers = [];
            $pending = ['', ''];
            $pass = static function (int $which, bool $all = false) use (
                $id,
                $pipes,
                $worker,
                &$pending,
                &$watchers,
            ): void {
                $pending[$which] .= (string) stream_get_contents($pipes[$which + 1]);
                while (($break = strpos($pending[$which], "\n")) !== false || $all && $pending[$which] !== '') {
                    $line = $break === false ? $pending[$which] : substr($pending[$which], 0, $break);
                    $pending[$which] = $break === false ? '' : substr($pending[$which], $break + 1);
                    fwrite($which === 0 ? STDOUT : STDERR, "[{$id}] " . rtrim($line, "\r") . "\n");
                }
                // Closed at the other end: it stays "readable" for ever, so it is no longer watched.
                if (feof($pipes[$which + 1]) && isset($watchers[$which])) {
                    $worker->loop->cancel($watchers[$which]);
                    unset($watchers[$which]);
                }
            };
            foreach ([0, 1] as $which) {
                stream_set_blocking($pipes[$which + 1], false);
                $watchers[$which] = $worker->loop->onReadable($pipes[$which + 1], static function () use (
                    $pass,
                    $which,
                ): void {
                    $pass($which);
                });
            }
            $signal = static function (int $number) use ($group, $sharedGroup): void {
                // An isolated foreground program owns a group; a daemon
                // program shares the keeper's and must be signalled by pid.
                if ($sharedGroup || $group <= 1 || !function_exists('posix_kill') || !@posix_kill(-$group, $number)) {
                    @posix_kill($group, $number);
                }
            };
            $started = microtime(true);
            try {
                while (proc_get_status($process)['running']) {
                    Async::sleep($worker->loop, 0.2, $cancellation);
                }
                // It ended by itself. Not at once again, if it cannot stay up.
                if ((microtime(true) - $started) < 2.0) {
                    Async::sleep($worker->loop, 2.0, $cancellation);
                }
            } catch (CancelledException) {
                // The loop is stopping, so this waits the plain way, still passing on what the
                // program says: one that cannot finish writing cannot finish.
                // A shell does not forward TERM. Signal its current tree,
                // children before parents, then the shell itself.
                foreach (self::descendants($group) as $pid) {
                    @posix_kill($pid, SIGTERM);
                }
                @posix_kill($group, SIGTERM);
                $until = microtime(true) + self::GRACE;
                // A compound shell can finish before its children's TERM cleanup.
                // Their inherited output stays open until that cleanup finishes.
                while (
                    (proc_get_status($process)['running'] || !feof($pipes[1]) || !feof($pipes[2]))
                    && microtime(true) < $until
                ) {
                    $pass(0);
                    $pass(1);
                    usleep(20_000);
                }
            } finally {
                // Whatever of the group is left, the program or what it started.
                $signal(9);
                foreach ($watchers as $watcher) {
                    $worker->loop->cancel($watcher);
                }
                foreach ([0, 1] as $which) {
                    $pass($which, true);
                    fclose($pipes[$which + 1]);
                }
                proc_close($process);
            }
        };
    }

    /**
     * A command line as its words, as a shell would split it: quotes
     * keep what is inside them together, and a backslash the character
     * after it.
     *
     * @return list<string>
     */
    public static function words(string $line): array
    {
        $words = [];
        $word = null;
        $quote = null;
        $length = strlen($line);
        for ($i = 0; $i < $length; ++$i) {
            $character = $line[$i];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                } elseif ($character === '\\' && $quote === '"' && ($i + 1) < $length) {
                    $word .= $line[++$i];
                } else {
                    $word .= $character;
                }
            } elseif ($character === '"' || $character === "'") {
                $quote = $character;
                $word ??= '';
            } elseif ($character === '\\' && ($i + 1) < $length) {
                $word = ($word ?? '') . $line[++$i];
            } elseif (ctype_space($character)) {
                if ($word !== null) {
                    $words[] = $word;
                    $word = null;
                }
            } else {
                $word = ($word ?? '') . $character;
            }
        }
        if ($word !== null) {
            $words[] = $word;
        }
        return $words;
    }

    /**
     * Descendants sampled at shutdown, deepest first so their parents
     * cannot exit before they receive TERM. Nothing is kept for later.
     *
     * @return list<int>
     */
    private static function descendants(int $pid): array
    {
        $children = [];
        if (PHP_OS_FAMILY === 'Linux') {
            foreach (glob('/proc/[0-9]*/stat') ?: [] as $file) {
                $stat = @file_get_contents($file);
                $end = $stat === false ? false : strrpos($stat, ')');
                if ($end === false) {
                    continue;
                }
                // The command name may itself contain spaces or parentheses.
                $fields = explode(' ', trim(substr($stat, $end + 2)));
                if (isset($fields[1])) {
                    $children[(int) $fields[1]][] = (int) basename(dirname($file));
                }
            }
        } elseif (function_exists('exec')) {
            @exec('ps -axo pid=,ppid= 2>/dev/null', $lines, $status);
            if ($status === 0) {
                foreach ($lines as $line) {
                    $fields = preg_split('/\s+/', trim($line));
                    if (count($fields) === 2) {
                        $children[(int) $fields[1]][] = (int) $fields[0];
                    }
                }
            }
        }
        $descendants = $children[$pid] ?? [];
        // Walking breadth first and reversing puts every child before its parent.
        for ($index = 0; $index < count($descendants); ++$index) {
            array_push($descendants, ...$children[$descendants[$index]] ?? []);
        }
        return array_reverse($descendants);
    }
}
