<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

use Crab\Engine\WorkerGroup;
use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;

final class Supervisor
{
    private array $slots = [];
    private array $children = [];
    private array $failures = [];
    private array $restartAt = [];
    private array $reloadQueue = [];
    private ?int $replacement = null;
    private bool $stopping = false;
    private bool $stopRequested = false;
    private bool $reloadRequested = false;
    private ?\Throwable $failure = null;
    private array $signalHandlers = [];

    /**
     * @param array<string, WorkerGroup> $groups
     */
    public function __construct(
        private readonly array $groups,
        private readonly array $bindings,
        private readonly \Closure $loopFactory,
        private readonly float $shutdownTimeout,
        private readonly float $startupTimeout = 10.0,
    ) {
        foreach ($groups as $name => $group) {
            for ($index = 0; $index < $group->workers; ++$index) {
                $this->slots["$name:$index"] = ['group' => $name, 'index' => $index, 'pid' => null];
            }
        }
    }

    public function run(): void
    {
        $asyncSignals = pcntl_async_signals();
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGUSR1] as $signal) {
            $this->signalHandlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, function (int $signal): void {
                if ($signal === SIGUSR1) { $this->reloadRequested = true; }
                else { $this->stopRequested = true; }
            });
        }
        try {
            foreach (array_keys($this->slots) as $slot) {
                if ($this->stopRequested) { $this->stop(); }
                if ($this->stopping) { break; }
                $this->spawn($slot);
            }
            // The parent never runs an application event loop. Forks, including
            // replacements, happen outside a running event loop or Fiber.
            while (!$this->stopping || $this->children !== []) {
                $this->tick();
            }
        } catch (\Throwable $error) {
            $this->failure ??= $error;
        } finally {
            $this->stop();
            while ($this->children !== []) { $this->tick(); }
            foreach ($this->signalHandlers as $signal => $handler) { pcntl_signal($signal, $handler); }
            pcntl_async_signals($asyncSignals);
        }
        if ($this->failure !== null) { throw $this->failure; }
    }

    public function stop(): void
    {
        if ($this->stopping) { return; }
        $this->stopping = true;
        $this->restartAt = $this->reloadQueue = [];
        foreach (array_keys($this->children) as $pid) { $this->retire($pid); }
    }

    public function reload(): void { $this->reloadRequested = true; }

    public function stats(): array
    {
        $workers = [];
        foreach ($this->children as $pid => $child) {
            $workers[] = ['pid' => $pid, 'slot' => $child['slot'], 'ready' => $child['ready'], 'retiring' => $child['retiring']];
        }
        return ['pid' => getmypid(), 'stopping' => $this->stopping, 'workers' => $workers];
    }

    private function spawn(string $slot, ?int $replaces = null): int
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) { throw new \RuntimeException('Could not create supervisor control stream.'); }
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($pair[0]); fclose($pair[1]);
            throw new \RuntimeException('Could not fork a worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            foreach ($this->children as $child) {
                if (is_resource($child['stream'])) { fclose($child['stream']); }
            }
            // The reload signal is the parent's; its default action would end this worker.
            foreach (array_keys($this->signalHandlers) as $signal) { pcntl_signal($signal, $signal === SIGUSR1 ? SIG_IGN : SIG_DFL); }
            $definition = $this->slots[$slot];
            foreach ($this->bindings as $name => $bindings) {
                if ($name === $definition['group']) { continue; }
                foreach ($bindings as $streams) { foreach ($streams as $stream) { fclose($stream); } }
            }
            try {
                $driver = EventLoop::install(($this->loopFactory)());
                $worker = new Worker(
                    $driver, $this->groups[$definition['group']], $definition['index'],
                    $this->bindings[$definition['group']], $this->shutdownTimeout, $pair[1],
                );
                $worker->run();
                exit(0);
            } catch (\Throwable $error) {
                FailureReporter::report('Worker failed', $error);
                exit(1);
            }
        }
        fclose($pair[1]);
        stream_set_blocking($pair[0], false);
        $this->children[$pid] = [
            'slot' => $slot, 'stream' => $pair[0], 'buffer' => '', 'ready' => false,
            'started' => self::now(), 'deadline' => null, 'retiring' => false, 'replaces' => $replaces,
        ];
        $this->slots[$slot]['pid'] = $pid;
        return $pid;
    }

    private function tick(): void
    {
        if ($this->stopRequested) { $this->stop(); }
        $this->readMessages();
        $now = self::now();
        foreach (array_keys($this->children) as $pid) {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid || ($waited === -1 && pcntl_get_last_error() === PCNTL_ECHILD)) {
                $this->reaped($pid);
                continue;
            }
            $child = $this->children[$pid];
            if (!$child['ready'] && !$child['retiring'] && $now - $child['started'] >= $this->startupTimeout) {
                FailureReporter::report("Worker $pid exceeded its startup deadline");
                $this->retire($pid, intentional: false);
            }
            if ($child['deadline'] !== null && $now >= $child['deadline']) {
                posix_kill($pid, SIGKILL);
            }
        }
        if ($this->stopping) { return; }
        foreach ($this->restartAt as $slot => $when) {
            if ($when <= $now) {
                unset($this->restartAt[$slot]);
                $this->spawn($slot);
            }
        }
        if ($this->reloadRequested && $this->reloadQueue === [] && $this->replacement === null) {
            $this->reloadRequested = false;
            $this->reloadQueue = array_keys($this->slots);
        }
        if ($this->replacement === null && $this->reloadQueue !== []) {
            $slot = array_shift($this->reloadQueue);
            $pid = $this->slots[$slot]['pid'];
            if ($pid !== null && ($this->children[$pid]['ready'] ?? false)) {
                $this->replacement = $this->spawn($slot, $pid);
            }
        }
    }

    private function readMessages(): void
    {
        $read = [];
        foreach ($this->children as $child) {
            if (is_resource($child['stream'])) { $read[] = $child['stream']; }
        }
        if ($read === []) { usleep(50000); return; }
        $write = $except = [];
        if (@stream_select($read, $write, $except, 0, 50000) < 1) { return; }
        foreach ($this->children as $pid => &$child) {
            if (!is_resource($child['stream']) || !in_array($child['stream'], $read, true)) { continue; }
            $bytes = @fread($child['stream'], 1024);
            if ($bytes === false || feof($child['stream'])) {
                fclose($child['stream']);
                $child['stream'] = null;
                if (!$child['retiring']) { $this->retire($pid, intentional: false); }
                continue;
            }
            $child['buffer'] .= $bytes;
            if (!$child['ready'] && !$child['retiring'] && str_contains($child['buffer'], "ready\n")) {
                $child['ready'] = true;
                $child['buffer'] = '';
                if ($child['replaces'] !== null && isset($this->children[$child['replaces']])) {
                    $this->retire($child['replaces']);
                }
                if ($this->replacement === $pid) { $this->replacement = null; }
            }
            if (strlen($child['buffer']) > 1024) { $this->retire($pid, intentional: false); }
        }
        unset($child);
    }

    private function retire(int $pid, bool $intentional = true): void
    {
        if (!isset($this->children[$pid]) || $this->children[$pid]['retiring']) { return; }
        $this->children[$pid]['retiring'] = true;
        $this->children[$pid]['intentional'] = $intentional;
        $this->children[$pid]['deadline'] = self::now() + $this->shutdownTimeout;
        $stream = $this->children[$pid]['stream'];
        if (!is_resource($stream) || @fwrite($stream, "stop\n") !== 5) {
            posix_kill($pid, SIGTERM);
        }
    }

    private function reaped(int $pid): void
    {
        $child = $this->children[$pid];
        if (is_resource($child['stream'])) { fclose($child['stream']); }
        unset($this->children[$pid]);
        $slot = $child['slot'];
        if ($this->replacement === $pid) { $this->replacement = null; }
        if ($this->slots[$slot]['pid'] !== $pid) { return; }
        $this->slots[$slot]['pid'] = null;
        if ($this->stopping) { return; }
        if ($child['replaces'] !== null && isset($this->children[$child['replaces']])
            && !$this->children[$child['replaces']]['retiring']) {
            $this->slots[$slot]['pid'] = $child['replaces'];
            FailureReporter::report("Replacement $pid failed; the previous worker remains active");
            return;
        }
        $now = self::now();
        $this->failures[$slot] = array_values(array_filter($this->failures[$slot] ?? [], static fn(float $at): bool => $now - $at < 60));
        $this->failures[$slot][] = $now;
        if (count($this->failures[$slot]) > 5) {
            $this->failure = new \RuntimeException("Worker $slot exceeded five restarts in sixty seconds.");
            $this->stop();
            return;
        }
        $this->restartAt[$slot] = $now + min(2.0, 0.1 * 2 ** (count($this->failures[$slot]) - 1));
    }

    private static function now(): float { return hrtime(true) / 1e9; }
}
