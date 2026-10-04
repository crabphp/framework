<?php

declare(strict_types=1);

namespace Crab\Engine;

use Crab\Engine\Network\Listener;
use Crab\Engine\Network\UdpEndpoint;
use Crab\Engine\Process\Supervisor;
use Crab\Engine\Process\Worker;
use Crab\Engine\EventLoop\RevoltEventLoop;

final class Runtime
{
    /**
     * @var array<string, WorkerGroup>
     */
    private array $groups = [];
    private \Closure $loopFactory;
    private Worker|Supervisor|null $runner = null;
    private bool $running = false;

    /**
     * The factory makes each process's EventLoop; without one it is a
     * stream-select loop. `fn () => RevoltEventLoop::create('uv')` chooses a
     * native backend.
     */
    public function __construct(?callable $loopFactory = null, public readonly float $shutdownTimeout = 5.0)
    {
        if (!is_finite($shutdownTimeout) || $shutdownTimeout <= 0) {
            throw new \InvalidArgumentException('Shutdown timeout must be finite and positive.');
        }
        $this->loopFactory = $loopFactory === null
            ? static fn(): EventLoop => RevoltEventLoop::create()
            : $loopFactory(...);
    }

    public function group(string $name = 'default', int $workers = 1, int $maxConnections = 512, int $maxTasks = 1024): WorkerGroup
    {
        if ($this->running || isset($this->groups[$name])) {
            throw new \LogicException('Groups must be unique and configured before startup.');
        }
        return $this->groups[$name] = new WorkerGroup($name, $workers, $maxConnections, $maxTasks);
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
                    $bindings[$name]['tcp'][] = Listener::bind($definition['address'], $definition['options']);
                }
                foreach ($group->datagrams() as $definition) {
                    $bindings[$name]['udp'][] = UdpEndpoint::bind($definition['address'], $definition['options']['socket'] ?? []);
                }
            }
            if ($singleProcess) {
                $group = reset($this->groups);
                $driver = EventLoop::install(($this->loopFactory)());
                $this->runner = new Worker($driver, $group, 0, $bindings[$group->name], $this->shutdownTimeout);
            } else {
                $this->runner = new Supervisor($this->groups, $bindings, $this->loopFactory, $this->shutdownTimeout);
            }
            $this->runner->run();
        } finally {
            foreach ($bindings as $groupBindings) {
                foreach ($groupBindings as $streams) {
                    foreach ($streams as $stream) {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
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
}
