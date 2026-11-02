<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

use Crab\Engine\EventLoop;

/**
 * A command that keeps running beside the program, talked to while it runs:
 * what is written goes to its standard input, and what it writes comes back
 * as it arrives, all on the event loop.
 *
 *     $server = Process::start(['my-server', '--stdio']);
 *     $server->onOutput(static fn (string $chunk) => print($chunk));
 *     $server->onExit(static fn (?int $code) => print("ended: {$code}\n"));
 *     $server->write("hello\n");
 *     // …
 *     $server->stop();
 *
 * For a command that is run to its end, Subprocess is simpler. As there, the
 * command never passes through a shell, and where the `setsid` program and
 * PHP's posix extension are present it runs in a process group of its own,
 * so stopping it stops what it started too.
 */
final class Process
{
    /** @var array<string, string> Event loop watchers by purpose. */
    private array $watchers = [];
    private string $outbox = '';
    private bool $exited = false;
    private ?int $exitCode = null;
    private bool $closed = false;
    /** @var array<int, string> Output that arrived before anyone listened, by descriptor. */
    private array $held = [1 => '', 2 => ''];
    /** @var array<int, \Closure(string): void> */
    private array $listeners = [];
    /** @var list<\Closure(?int): void> */
    private array $exitListeners = [];
    /** @var array<int, self> Processes still running, by pid: killed when PHP ends, so none outlives it. */
    private static array $live = [];
    private static bool $hooked = false;

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function __construct(
        private readonly mixed $process,
        private array $pipes,
        private readonly int $pid,
        private readonly bool $group,
        private readonly EventLoop $loop,
    ) {}

    /**
     * @param list<string> $command The program and its arguments.
     * @param string|null $directory Where it runs; null for this process's working directory.
     * @param array<string, string>|null $environment Its whole environment; null to inherit this process's.
     */
    public static function start(array $command, ?string $directory = null, ?array $environment = null, ?EventLoop $loop = null): self
    {
        if ($command === [] || !array_is_list($command)) {
            throw new \InvalidArgumentException('A process needs a command given as a list of arguments.');
        }
        $loop ??= EventLoop::current();
        $setsid = self::setsid();
        $argv = $setsid === false ? $command : [$setsid, '--', ...$command];
        $pipes = [];
        $process = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, $environment);
        if (!is_resource($process)) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new \RuntimeException("Could not start {$command[0]}: {$error}");
        }
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $self = new self($process, $pipes, (int) proc_get_status($process)['pid'], $setsid !== false, $loop);
        $self->watch();
        self::$live[$self->pid] = $self;
        if (!self::$hooked) {
            self::$hooked = true;
            // However PHP ends, a crash or an exit before stop() had its grace, nothing started here lives on.
            register_shutdown_function(static function (): void {
                foreach (self::$live as $process) {
                    $process->kill();
                }
            });
        }
        return $self;
    }

    /**
     * Called with each piece of standard output as it arrives; what came
     * before is given at once.
     *
     * @param \Closure(string): void $listener
     */
    public function onOutput(\Closure $listener): void
    {
        $this->listen(1, $listener);
    }

    /**
     * Called with each piece of standard error as it arrives.
     *
     * @param \Closure(string): void $listener
     */
    public function onError(\Closure $listener): void
    {
        $this->listen(2, $listener);
    }

    /**
     * Called once when the command ends, with its exit code; null when a
     * signal ended it. Called at once if it already has.
     *
     * @param \Closure(?int): void $listener
     */
    public function onExit(\Closure $listener): void
    {
        if ($this->exited) {
            $listener($this->exitCode);
            return;
        }
        $this->exitListeners[] = $listener;
    }

    /**
     * Sends bytes to its standard input, as fast as it reads them, without
     * waiting; nothing once it has ended or its input is closed.
     */
    public function write(string $bytes): void
    {
        if ($bytes === '' || $this->closed || !is_resource($this->pipes[0] ?? null)) {
            return;
        }
        $this->outbox .= $bytes;
        $this->watchers['write'] ??= $this->loop->onWritable($this->pipes[0], function (): void {
            $written = @fwrite($this->pipes[0], substr($this->outbox, 0, 65_536));
            if ($written === false) {
                $this->outbox = ''; // it stopped reading: what is left has nowhere to go
            } else {
                $this->outbox = substr($this->outbox, $written);
            }
            if ($this->outbox === '') {
                $this->loop->cancel($this->watchers['write']);
                unset($this->watchers['write']);
            }
        });
    }

    public function running(): bool
    {
        return !$this->exited && !$this->poll();
    }

    public function pid(): int
    {
        return $this->pid;
    }

    /**
     * Ends it: its input is closed, which a well-behaved command takes to
     * mean it is done; if it is still running after the grace given, it and
     * what it started are killed.
     */
    public function stop(float $grace = 0.5): void
    {
        $this->closeInput();
        if ($this->exited) {
            return;
        }
        if ($grace <= 0) {
            $this->kill();
            return;
        }
        $this->watchers['stop'] = $this->loop->delay($grace, function (): void {
            unset($this->watchers['stop']);
            if (!$this->poll()) {
                $this->kill();
            }
        });
    }

    /**
     * Ends it now, and what it started.
     */
    public function kill(): void
    {
        if ($this->group) {
            @posix_kill(-$this->pid, 9);
        }
        if (!$this->exited) {
            @proc_terminate($this->process, 9);
        }
        $this->closeInput();
    }

    private function closeInput(): void
    {
        $this->closed = true;
        $this->outbox = '';
        if (isset($this->watchers['write'])) {
            $this->loop->cancel($this->watchers['write']);
            unset($this->watchers['write']);
        }
        if (is_resource($this->pipes[0] ?? null)) {
            @fclose($this->pipes[0]);
        }
    }

    /**
     * @param \Closure(string): void $listener
     */
    private function listen(int $fd, \Closure $listener): void
    {
        $this->listeners[$fd] = $listener;
        if ($this->held[$fd] !== '') {
            $held = $this->held[$fd];
            $this->held[$fd] = '';
            $listener($held);
        }
    }

    private function watch(): void
    {
        foreach ([1, 2] as $fd) {
            $this->watchers["read{$fd}"] = $this->loop->onReadable($this->pipes[$fd], function () use ($fd): void {
                $chunk = @fread($this->pipes[$fd], 65_536);
                if ($chunk === false || ($chunk === '' && feof($this->pipes[$fd]))) {
                    $this->loop->cancel($this->watchers["read{$fd}"]);
                    unset($this->watchers["read{$fd}"]);
                    return;
                }
                if ($chunk === '') {
                    return;
                }
                if (isset($this->listeners[$fd])) {
                    ($this->listeners[$fd])($chunk);
                } else {
                    $this->held[$fd] .= $chunk;
                }
            });
        }
        // Its exit is noticed by polling: a command can end while what it started holds its output open.
        $this->watchers['exit'] = $this->loop->repeat(0.05, function (): void {
            if ($this->poll()) {
                $this->finish();
            }
        });
    }

    private function poll(): bool
    {
        if ($this->exited) {
            return true;
        }
        $status = proc_get_status($this->process);
        if ($status['running']) {
            return false;
        }
        $this->exited = true;
        // proc_get_status() reports the code only once, on the call that sees the exit.
        $this->exitCode = $status['signaled'] ? null : (int) $status['exitcode'];
        return true;
    }

    /**
     * After it has ended: what it last wrote is read, the watchers go, the
     * pipes close, and whoever waits is told.
     */
    private function finish(): void
    {
        foreach ([1, 2] as $fd) {
            if (is_resource($this->pipes[$fd] ?? null)) {
                $rest = (string) @stream_get_contents($this->pipes[$fd]);
                if ($rest !== '') {
                    isset($this->listeners[$fd]) ? ($this->listeners[$fd])($rest) : $this->held[$fd] .= $rest;
                }
            }
        }
        foreach ($this->watchers as $id) {
            $this->loop->cancel($id);
        }
        $this->watchers = [];
        if ($this->group) {
            @posix_kill(-$this->pid, 9); // what it started goes with it
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        $this->pipes = [];
        $this->closed = true;
        unset(self::$live[$this->pid]);
        @proc_close($this->process);
        $listeners = $this->exitListeners;
        $this->exitListeners = [];
        foreach ($listeners as $listener) {
            $listener($this->exitCode);
        }
    }

    /**
     * The `setsid` program, when it and the posix extension to signal a group are both here.
     */
    private static function setsid(): string|false
    {
        if (!function_exists('posix_kill')) {
            return false;
        }
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }
        return false;
    }
}
