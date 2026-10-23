<?php

declare(strict_types=1);

namespace Crab\Engine;

use Crab\Engine\EventLoop\StreamSelectEventLoop;
use Crab\Engine\Network\Listener;
use Crab\Engine\Network\UdpEndpoint;
use Crab\Engine\Process\Supervisor;
use Crab\Engine\Process\Worker;

final class Runtime
{
    /**
     * @var array<string, Crab>
     */
    private array $groups = [];
    private \Closure $loopFactory;
    private Worker|Supervisor|null $runner = null;
    private bool $running = false;
    private ?\Closure $onReady = null;
    /**
     * @var array<int, \Closure>
     */
    private array $onSignal = [];

    /**
     * The factory makes each process's EventLoop; without one it is a
     * StreamSelectEventLoop. `fn () => RevoltEventLoop::create('uv')` chooses
     * a native backend.
     */
    public function __construct(
        ?callable $loopFactory = null,
        public readonly float $shutdownTimeout = 5.0,
    ) {
        if (!is_finite($shutdownTimeout) || $shutdownTimeout <= 0) {
            throw new \InvalidArgumentException('Shutdown timeout must be finite and positive.');
        }
        $this->loopFactory = $loopFactory === null
            ? static fn(): EventLoop => new StreamSelectEventLoop()
            : $loopFactory(...);
    }

    /**
     * A group may have a shorter drain deadline than the runtime's other
     * workers; its supervisor still allows time for cleanup and exit.
     */
    public function group(
        string $name = 'default',
        int $workers = 1,
        int $maxConnections = 512,
        int $maxTasks = 1024,
        ?float $shutdownTimeout = null,
    ): Crab {
        if ($this->running || isset($this->groups[$name])) {
            throw new \LogicException('Groups must be unique and configured before startup.');
        }
        return $this->groups[$name] = new Crab($name, $workers, $maxConnections, $maxTasks, $shutdownTimeout);
    }

    /**
     * Calls back once, in the process that called run(), when every
     * worker has started and is accepting connections. A process that
     * starts the server on someone's behalf waits for this to say that it
     * is up.
     */
    public function onReady(callable $callback): self
    {
        if ($this->running) {
            throw new \LogicException('Say what to do when ready before startup.');
        }
        $this->onReady = $callback(...);
        return $this;
    }

    /**
     * Calls back, in the process that called run(), when that process
     * receives the signal. Workers ignore it. The callback runs in the
     * parent's own loop, not inside the signal, so it may do real work.
     */
    public function onSignal(int $signal, callable $callback): self
    {
        if ($this->running) {
            throw new \LogicException('Say what a signal does before startup.');
        }
        $this->onSignal[$signal] = $callback(...);
        return $this;
    }

    public function run(bool $singleProcess = false): void
    {
        if ($this->running || $this->groups === []) {
            throw new \LogicException('Configure a group before running; a runtime cannot run recursively.');
        }
        if ($singleProcess && (count($this->groups) !== 1 || reset($this->groups)->workers !== 1)) {
            throw new \InvalidArgumentException('Single-process mode requires one group with one worker.');
        }
        if (!$singleProcess && (!extension_loaded('pcntl') || !extension_loaded('posix'))) {
            throw new \RuntimeException('Supervised mode requires pcntl and posix; use singleProcess: true otherwise.');
        }
        $bindings = [];
        $this->running = true;
        try {
            foreach ($this->groups as $name => $group) {
                $group->freeze();
                $bindings[$name] = ['tcp' => [], 'udp' => []];
                foreach ($group->listeners() as $definition) {
                    $socket = $definition['socket'] ?? Listener::bind($definition['address'], $definition['options']);
                    stream_set_blocking($socket, false);
                    $bindings[$name]['tcp'][] = $socket;
                }
                foreach ($group->datagrams() as $definition) {
                    $bindings[$name]['udp'][] = UdpEndpoint::bind(
                        $definition['address'],
                        $definition['options']['socket'] ?? [],
                    );
                }
            }
            if ($singleProcess) {
                $group = reset($this->groups);
                $driver = EventLoop::install(($this->loopFactory)());
                $this->runner = new Worker(
                    $driver,
                    $group,
                    0,
                    $bindings[$group->name],
                    $group->shutdownTimeout ?? $this->shutdownTimeout,
                );
                if ($this->onReady !== null) {
                    // The first turn of the loop comes after the worker has started.
                    $onReady = $this->onReady;
                    $driver->defer(static function () use ($onReady): void {
                        $onReady();
                    });
                }
                if (extension_loaded('pcntl')) {
                    foreach ($this->onSignal as $signal => $callback) {
                        $driver->onSignal($signal, static function () use ($callback): void {
                            $callback();
                        });
                    }
                }
            } else {
                $this->runner = new Supervisor(
                    $this->groups,
                    $bindings,
                    $this->loopFactory,
                    $this->shutdownTimeout,
                    onReady: $this->onReady,
                    onSignal: $this->onSignal,
                );
            }
            $this->runner->run();
        } finally {
            foreach ($bindings as $groupBindings) {
                foreach ($groupBindings as $streams) {
                    foreach ($streams as $stream) {
                        if (!is_resource($stream)) {
                            continue;
                        }

                        fclose($stream);
                    }
                }
            }
            $this->runner = null;
            $this->running = false;
        }
    }

    public function stop(): void
    {
        $this->runner?->stop();
    }

    /**
     * Ends workers without waiting for their drain and collects them in
     * their supervisor. In one process, normal finalization closes its sockets.
     */
    public function force(): void
    {
        if ($this->runner instanceof Supervisor) {
            $this->runner->force();
        } elseif ($this->runner instanceof Worker) {
            $this->runner->stop();
            $this->runner->loop->stop();
        }
    }
}
