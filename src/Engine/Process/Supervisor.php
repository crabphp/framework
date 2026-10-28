<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

use Crab\Engine\Crab;
use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;

final class Supervisor
{
    /**
     * The longest line a worker may send; a report of its stats is far shorter.
     */
    private const LINE = 65_536;

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
    private bool $announced = false;
    /**
     * Signals received and not yet acted on.
     *
     * @var array<int, true>
     */
    private array $pendingSignals = [];
    /**
     * How many times each slot's worker has been started again after it died.
     *
     * @var array<string, int>
     */
    private array $restarts = [];
    private float $written = -INF;
    private bool $changed = true;

    /**
     * @param array<string, Crab> $groups
     */
    public function __construct(
        private readonly array $groups,
        private readonly array $bindings,
        private readonly \Closure $loopFactory,
        private readonly float $shutdownTimeout,
        private readonly float $startupTimeout = 10.0,
        private readonly ?\Closure $onReady = null,
        private readonly array $onSignal = [],
        private readonly ?StatsFile $statsFile = null,
    ) {
        foreach ($groups as $name => $group) {
            for ($index = 0; $index < $group->workers; ++$index) {
                $this->slots["{$name}:{$index}"] = ['group' => $name, 'index' => $index, 'pid' => null];
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
                if ($signal === SIGUSR1) {
                    $this->reloadRequested = true;
                } else {
                    $this->stopRequested = true;
                }
            });
        }
        foreach (array_keys($this->onSignal) as $signal) {
            $this->signalHandlers[$signal] = pcntl_signal_get_handler($signal);
            // Only noted here; acted on in the loop, where real work is safe.
            pcntl_signal($signal, function (int $signal): void {
                $this->pendingSignals[$signal] = true;
            });
        }
        try {
            foreach (array_keys($this->slots) as $slot) {
                if ($this->stopRequested) {
                    $this->stop();
                }
                if ($this->stopping) {
                    break;
                }
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
            while ($this->children !== []) {
                $this->tick();
            }
            foreach ($this->signalHandlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            pcntl_async_signals($asyncSignals);
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    public function stop(): void
    {
        if ($this->stopping) {
            return;
        }
        $this->stopping = true;
        $this->restartAt = $this->reloadQueue = [];
        foreach (array_keys($this->children) as $pid) {
            $this->retire($pid);
        }
        // No worker will be started again, so the parent lets go of the listening
        // sockets: once the workers have closed theirs, the address is free, and a
        // server taking over on the same address stops sharing new connections with this one.
        foreach ($this->bindings as $bindings) {
            foreach ($bindings as $streams) {
                foreach ($streams as $stream) {
                    if (!is_resource($stream)) {
                        continue;
                    }

                    fclose($stream);
                }
            }
        }
    }

    public function reload(): void
    {
        $this->reloadRequested = true;
    }

    /**
     * Ends every owned worker now. The run loop reaps each one before
     * returning, so the generation cannot leave its workers behind.
     */
    public function force(): void
    {
        $this->stop();
        foreach (array_keys($this->children) as $pid) {
            posix_kill($pid, SIGKILL);
            $this->children[$pid]['deadline'] = INF;
        }
    }

    /**
     * Each worker with the stats it last reported, under 'stats', and
     * how many times its slot has been started again.
     */
    public function stats(): array
    {
        $workers = [];
        foreach ($this->children as $pid => $child) {
            $workers[] = [
                'pid' => $pid,
                'slot' => $child['slot'],
                'ready' => $child['ready'],
                'retiring' => $child['retiring'],
                'restarts' => $this->restarts[$child['slot']] ?? 0,
                'stats' => $child['stats'],
            ];
        }
        return ['pid' => getmypid(), 'stopping' => $this->stopping, 'workers' => $workers];
    }

    private function spawn(string $slot, ?int $replaces = null): int
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new \RuntimeException('Could not create supervisor control stream.');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($pair[0]);
            fclose($pair[1]);
            throw new \RuntimeException('Could not fork a worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            foreach ($this->children as $child) {
                if (!is_resource($child['stream'])) {
                    continue;
                }

                fclose($child['stream']);
            }
            // The reload signal and the application's own are the parent's; their default action would end this worker.
            foreach (array_keys($this->signalHandlers) as $signal) {
                pcntl_signal($signal, $signal === SIGUSR1 || isset($this->onSignal[$signal]) ? SIG_IGN : SIG_DFL);
            }
            $definition = $this->slots[$slot];
            foreach ($this->bindings as $name => $bindings) {
                if ($name === $definition['group']) {
                    continue;
                }
                foreach ($bindings as $streams) {
                    foreach ($streams as $stream) {
                        fclose($stream);
                    }
                }
            }
            try {
                $driver = EventLoop::install(($this->loopFactory)());
                $worker = new Worker(
                    $driver,
                    $this->groups[$definition['group']],
                    $definition['index'],
                    $this->bindings[$definition['group']],
                    $this->groups[$definition['group']]->shutdownTimeout ?? $this->shutdownTimeout,
                    $pair[1],
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
            'slot' => $slot,
            'stream' => $pair[0],
            'buffer' => '',
            'ready' => false,
            'started' => self::now(),
            'deadline' => null,
            // The time the worker asked to be ended at if it still runs then; see Worker::deadline().
            'limit' => null,
            'retiring' => false,
            'replaces' => $replaces,
            'stats' => [],
            'reported' => null,
        ];
        $this->slots[$slot]['pid'] = $pid;
        $this->changed = true;
        return $pid;
    }

    private function tick(): void
    {
        if ($this->stopRequested) {
            $this->stop();
        }
        $this->readMessages();
        foreach (array_keys($this->pendingSignals) as $signal) {
            unset($this->pendingSignals[$signal]);
            try {
                $this->onSignal[$signal]();
            } catch (\Throwable $error) {
                FailureReporter::report('Signal callback failed', $error);
            }
        }
        if (!$this->announced && !$this->stopping && $this->onReady !== null && $this->allReady()) {
            $this->announced = true;
            ($this->onReady)();
        }
        $now = self::now();
        foreach (array_keys($this->children) as $pid) {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === $pid || $waited === -1 && pcntl_get_last_error() === PCNTL_ECHILD) {
                $this->reaped($pid);
                continue;
            }
            $child = $this->children[$pid];
            if (!$child['ready'] && !$child['retiring'] && ($now - $child['started']) >= $this->startupTimeout) {
                FailureReporter::report("Worker {$pid} exceeded its startup deadline");
                $this->retire($pid, intentional: false);
            }
            if ($child['limit'] !== null && $now >= $child['limit'] && $child['deadline'] !== INF) {
                $group = $this->slots[$child['slot']]['group'];
                FailureReporter::report(
                    "Worker {$pid} in group \"{$group}\" ran past a deadline it set and is taken to be stuck; ending it.",
                );
                posix_kill($pid, SIGKILL);
                $this->children[$pid]['limit'] = null;
                $this->children[$pid]['deadline'] = INF;
                continue;
            }
            if ($child['deadline'] !== null && $now >= $child['deadline']) {
                $group = $this->slots[$child['slot']]['group'];
                $seconds = $this->grace($child['slot']);
                FailureReporter::report(
                    "Worker {$pid} in group \"{$group}\" did not stop within {$seconds} seconds; ending it.",
                );
                posix_kill($pid, SIGKILL);
                $this->children[$pid]['deadline'] = INF;
            }
        }
        $this->writeStats($now);
        if ($this->stopping) {
            return;
        }
        foreach ($this->restartAt as $slot => $when) {
            if ($when > $now) {
                continue;
            }

            unset($this->restartAt[$slot]);
            $this->spawn($slot);
        }
        if ($this->reloadRequested && $this->reloadQueue === [] && $this->replacement === null) {
            $this->reloadRequested = false;
            // A group that does not reload keeps its workers until the runtime stops.
            $this->reloadQueue = array_keys(array_filter(
                $this->slots,
                fn(array $slot): bool => $this->groups[$slot['group']]->reloads,
            ));
        }
        if ($this->replacement === null && $this->reloadQueue !== []) {
            $slot = array_shift($this->reloadQueue);
            $pid = $this->slots[$slot]['pid'];
            if ($pid !== null && ($this->children[$pid]['ready'] ?? false)) {
                $this->replacement = $this->spawn($slot, $pid);
            }
        }
    }

    /**
     * Whether every worker of every group has started and reported itself ready.
     */
    private function allReady(): bool
    {
        foreach ($this->slots as $slot) {
            if ($slot['pid'] === null || !($this->children[$slot['pid']]['ready'] ?? false)) {
                return false;
            }
        }
        return true;
    }

    private function readMessages(): void
    {
        $read = [];
        foreach ($this->children as $child) {
            if (!is_resource($child['stream'])) {
                continue;
            }

            $read[] = $child['stream'];
        }
        if ($read === []) {
            usleep(50_000);
            return;
        }
        $write = $except = [];
        if (@stream_select($read, $write, $except, 0, 50_000) < 1) {
            return;
        }
        foreach ($this->children as $pid => &$child) {
            if (!is_resource($child['stream']) || !in_array($child['stream'], $read, true)) {
                continue;
            }
            $bytes = @fread($child['stream'], 1024);
            if ($bytes === false || feof($child['stream'])) {
                fclose($child['stream']);
                $child['stream'] = null;
                if (!$child['retiring']) {
                    $this->retire($pid, intentional: false);
                }
                continue;
            }
            $child['buffer'] .= $bytes;
            while (($end = strpos($child['buffer'], "\n")) !== false) {
                $line = substr($child['buffer'], 0, $end);
                $child['buffer'] = substr($child['buffer'], $end + 1);
                if ($line === 'ready') {
                    if ($child['ready'] || $child['retiring']) {
                        continue;
                    }
                    $child['ready'] = true;
                    $this->changed = true;
                    if ($child['replaces'] !== null && isset($this->children[$child['replaces']])) {
                        $this->retire($child['replaces']);
                    }
                    if ($this->replacement === $pid) {
                        $this->replacement = null;
                    }
                } elseif (str_starts_with($line, 'deadline ')) {
                    $seconds = substr($line, 9);
                    $child['limit'] = is_numeric($seconds) ? self::now() + max(0.0, (float) $seconds) : null;
                } elseif (str_starts_with($line, 'stats ')) {
                    $stats = json_decode(substr($line, 6), true);
                    if (is_array($stats)) {
                        $child['stats'] = $stats;
                        $child['reported'] = microtime(true);
                    }
                }
            }
            if (strlen($child['buffer']) > self::LINE) {
                $this->retire($pid, intentional: false);
            }
        }
        unset($child);
    }

    /**
     * Writes the stats file about once a second, and at once when a worker
     * has started, become ready, begun to stop or gone.
     */
    private function writeStats(float $now): void
    {
        if ($this->statsFile === null || !$this->changed && ($now - $this->written) < 1.0) {
            return;
        }
        $this->changed = false;
        $this->written = $now;
        $workers = [];
        $add = function (int $pid, array $child) use (&$workers): void {
            $definition = $this->slots[$child['slot']];
            $workers[] = StatsFile::worker(
                $definition['group'],
                $definition['index'],
                $pid,
                match (true) {
                    $child['retiring'] => 'retiring',
                    $child['ready'] => 'ready',
                    default => 'starting',
                },
                $child['stats'],
                $this->restarts[$child['slot']] ?? 0,
                $child['reported'],
            );
        };
        foreach ($this->slots as $slot => $definition) {
            $pid = $definition['pid'];
            if ($pid !== null && isset($this->children[$pid])) {
                $add($pid, $this->children[$pid]);
                continue;
            }
            $workers[] = StatsFile::worker(
                $definition['group'],
                $definition['index'],
                null,
                $this->stopping ? 'stopped' : 'restarting',
                restarts: $this->restarts[$slot] ?? 0,
            );
        }
        // A worker being replaced, after the one that replaces it.
        foreach ($this->children as $pid => $child) {
            if ($this->slots[$child['slot']]['pid'] === $pid) {
                continue;
            }
            $add($pid, $child);
        }
        $this->statsFile->write(match (true) {
            $this->stopping => 'stopping',
            $this->replacement !== null || $this->reloadQueue !== [] => 'reloading',
            $this->allReady() => 'running',
            default => 'starting',
        }, $workers);
    }

    private function retire(int $pid, bool $intentional = true): void
    {
        if (!isset($this->children[$pid]) || $this->children[$pid]['retiring']) {
            return;
        }
        $this->children[$pid]['retiring'] = true;
        $this->changed = true;
        $this->children[$pid]['intentional'] = $intentional;
        $this->children[$pid]['deadline'] = self::now() + $this->grace($this->children[$pid]['slot']);
        $stream = $this->children[$pid]['stream'];
        if (!is_resource($stream) || @fwrite($stream, "stop\n") !== 5) {
            posix_kill($pid, SIGTERM);
        }
    }

    /**
     * A worker with its own drain deadline must reach it before the
     * supervisor ends the process, leaving time to report and clean up.
     * Groups without an override keep the runtime's existing deadline.
     */
    private function grace(string $slot): float
    {
        $group = $this->groups[$this->slots[$slot]['group']];
        return $group->shutdownTimeout === null ? $this->shutdownTimeout : $group->shutdownTimeout + 1.0;
    }

    private function reaped(int $pid): void
    {
        $child = $this->children[$pid];
        if (is_resource($child['stream'])) {
            fclose($child['stream']);
        }
        unset($this->children[$pid]);
        $this->changed = true;
        $slot = $child['slot'];
        if ($this->replacement === $pid) {
            $this->replacement = null;
        }
        if ($this->slots[$slot]['pid'] !== $pid) {
            return;
        }
        $this->slots[$slot]['pid'] = null;
        if ($this->stopping) {
            return;
        }
        if (
            $child['replaces'] !== null
            && isset($this->children[$child['replaces']])
            && !$this->children[$child['replaces']]['retiring']
        ) {
            $this->slots[$slot]['pid'] = $child['replaces'];
            FailureReporter::report("Replacement {$pid} failed; the previous worker remains active");
            return;
        }
        $now = self::now();
        $this->failures[$slot] = array_values(array_filter(
            $this->failures[$slot] ?? [],
            static fn(float $at): bool => ($now - $at) < 60,
        ));
        $this->failures[$slot][] = $now;
        $this->restarts[$slot] = ($this->restarts[$slot] ?? 0) + 1;
        if (count($this->failures[$slot]) > 5) {
            $this->failure = new \RuntimeException("Worker {$slot} exceeded five restarts in sixty seconds.");
            $this->stop();
            return;
        }
        $this->restartAt[$slot] = $now + min(2.0, 0.1 * (2 ** (count($this->failures[$slot]) - 1)));
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
