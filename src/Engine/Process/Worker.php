<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\Connector;
use Crab\Engine\Network\Listener;
use Crab\Engine\Network\UdpEndpoint;
use Crab\Engine\WorkerGroup;
use Revolt\EventLoop\Driver;

final class Worker
{
    /**
     * @var list<Listener>
     */
    private array $listeners = [];
    /**
     * @var array<int, Connection>
     */
    private array $connections = [];
    /**
     * @var array<int, UdpEndpoint>
     */
    private array $datagrams = [];
    /**
     * @var array<int, Task>
     */
    private array $tasks = [];
    private array $pendingConnects = [];
    private int $nextConnect = 0;
    private bool $draining = false;
    private bool $starting = true;
    private array $keepAlive = [];

    /**
     * @param array{tcp:list<resource>, udp:list<resource>} $bindings
     */
    public function __construct(
        public readonly Driver $loop,
        public readonly WorkerGroup $group,
        public readonly int $index,
        private array $bindings,
        private readonly float $shutdownTimeout,
        private mixed $control = null,
    ) {}

    public function run(): void
    {
        try {
            if ($this->group->failureCallbacks() !== []) {
                // Otherwise an uncaught failure in a timer or watcher ends the worker.
                $this->loop->setErrorHandler(fn(\Throwable $error) => $this->report($error, 'Callback failed'));
            }
            $this->watchControl();
            if (extension_loaded('pcntl')) {
                $this->loop->onSignal(SIGTERM, fn() => $this->stop());
                $this->loop->onSignal(SIGINT, fn() => $this->stop());
            }
            foreach ($this->group->listeners() as $i => $definition) {
                $listener = new Listener($this->loop, $this->bindings['tcp'][$i], function (Connection $connection) use ($definition): void {
                    if ($this->draining || count($this->connections) + count($this->pendingConnects) >= $this->group->maxConnections) {
                        $connection->close();
                        return;
                    }
                    $this->track($connection);
                    ($definition['handler'])($connection);
                });
                $this->listeners[] = $listener;
                $listener->attach();
            }
            foreach ($this->group->datagrams() as $i => $definition) {
                $options = $definition['options'];
                $endpoint = new UdpEndpoint(
                    $this->loop,
                    $this->bindings['udp'][$i],
                    $definition['handler'],
                    $options['maxDatagramSize'] ?? 65507,
                    $options['maxQueueBytes'] ?? 1048576,
                    $options['maxQueuePackets'] ?? 1024,
                );
                $id = spl_object_id($endpoint);
                $this->datagrams[$id] = $endpoint;
                $endpoint->onClose(function () use ($id): void {
                    unset($this->datagrams[$id]);
                    $this->finishDrain();
                });
            }
            foreach ($this->group->startCallbacks() as $callback) {
                $callback($this);
            }
            $this->starting = false;
            if (is_resource($this->control)) {
                if (@fwrite($this->control, "ready\n") !== 6) {
                    throw new \RuntimeException('Could not notify the supervisor that this worker is ready.');
                }
            }
            $this->finishDrain();
            if (!$this->draining || $this->hasWork()) {
                $this->loop->run();
            }
        } finally {
            $this->draining = true;
            if (!$this->starting) {
                foreach (array_reverse($this->group->stopCallbacks()) as $callback) {
                    try {
                        $callback($this);
                    } catch (\Throwable $error) {
                        $this->report($error, 'Stop callback failed');
                    }
                }
            }
            foreach ($this->listeners as $listener) { $listener->close(); }
            foreach ($this->pendingConnects as $cancel) { $cancel(); }
            foreach ($this->tasks as $task) { $task->cancel(); }
            foreach ($this->connections as $connection) { $connection->close(); }
            foreach ($this->datagrams as $endpoint) { $endpoint->close(); }
            foreach ($this->loop->getIdentifiers() as $id) { $this->loop->cancel($id); }
            foreach ($this->keepAlive as $stream) {
                if (is_resource($stream)) { fclose($stream); }
            }
            if (is_resource($this->control)) { fclose($this->control); }
        }
    }

    public function stop(): void
    {
        if ($this->draining) { return; }
        $this->draining = true;
        foreach ($this->listeners as $listener) { $listener->close(); }
        foreach ($this->pendingConnects as $cancel) { $cancel(); }
        foreach ($this->tasks as $task) { $task->cancel(); }
        foreach ($this->connections as $connection) { $connection->drain(); }
        foreach ($this->datagrams as $endpoint) { $endpoint->drain(); }
        $this->loop->delay($this->shutdownTimeout, function (): void {
            foreach ($this->connections as $connection) { $connection->close(); }
            foreach ($this->datagrams as $endpoint) { $endpoint->close(); }
            $this->loop->stop();
        });
        $this->finishDrain();
    }

    public function isDraining(): bool { return $this->draining; }

    public function spawn(callable $operation): Task
    {
        if ($this->draining) { throw new \LogicException('Cannot start a task while draining.'); }
        if (count($this->tasks) >= $this->group->maxTasks) {
            throw new \OverflowException('Worker task limit has been reached.');
        }
        $task = Task::spawn($this->loop, $operation);
        $id = spl_object_id($task);
        $this->tasks[$id] = $task;
        $task->onComplete(function (Task $task) use ($id): void {
            unset($this->tasks[$id]);
            try {
                $task->result();
            } catch (CancelledException) {
                // Cooperative shutdown is an expected task outcome.
            } catch (\Throwable $error) {
                $this->report($error, 'Task failed');
            }
            $this->finishDrain();
        });
        return $task;
    }

    /**
     * Completion receives (?Connection, ?Throwable); cancelling also completes once.
     */
    public function connect(string $address, callable $completion, float $timeout = 5.0): \Closure
    {
        if ($this->draining || count($this->connections) + count($this->pendingConnects) >= $this->group->maxConnections) {
            throw new \OverflowException('Worker is draining or its connection limit has been reached.');
        }
        $id = ++$this->nextConnect;
        $completed = false;
        $cancel = Connector::connect($this->loop, $address, function (?Connection $connection, ?\Throwable $error) use ($id, $completion, &$completed): void {
            $completed = true;
            unset($this->pendingConnects[$id]);
            if ($connection !== null) { $this->track($connection); }
            else { $this->updateAdmission(); }
            try { $completion($connection, $error); } finally { $this->finishDrain(); }
        }, $timeout);
        if (!$completed) {
            $this->pendingConnects[$id] = $cancel;
            $this->updateAdmission();
        }
        return $cancel(...);
    }

    public function stats(): array
    {
        return [
            'pid' => getmypid(), 'group' => $this->group->name, 'index' => $this->index,
            'connections' => count($this->connections), 'pendingConnects' => count($this->pendingConnects),
            'tasks' => count($this->tasks), 'datagrams' => count($this->datagrams),
            'queuedBytes' => array_sum(array_map(static fn(Connection $c): int => $c->bufferedBytes(), $this->connections)),
            'memoryBytes' => memory_get_usage(true), 'draining' => $this->draining,
        ];
    }

    private function track(Connection $connection): void
    {
        $id = spl_object_id($connection);
        $this->connections[$id] = $connection;
        $connection->onSettled(function () use ($id): void {
            unset($this->connections[$id]);
            $this->updateAdmission();
            $this->finishDrain();
        });
        $this->updateAdmission();
    }

    private function updateAdmission(): void
    {
        $atCapacity = $this->draining
            || count($this->connections) + count($this->pendingConnects) >= $this->group->maxConnections;
        foreach ($this->listeners as $listener) {
            if ($atCapacity) { $listener->pause(); }
            else { $listener->resume(); }
        }
    }

    private function watchControl(): void
    {
        if (!is_resource($this->control)) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) { throw new \RuntimeException('Could not create worker wakeup stream.'); }
            $this->keepAlive = $pair;
            // Keep an otherwise idle worker alive without a polling timer.
            $this->loop->onReadable($pair[0], static fn() => null);
            return;
        }
        stream_set_blocking($this->control, false);
        $buffer = '';
        $this->loop->onReadable($this->control, function (string $watcher) use (&$buffer): void {
            $bytes = @fread($this->control, 1024);
            if ($bytes === false || feof($this->control)) {
                $this->loop->cancel($watcher);
                $this->stop();
                return;
            }
            $buffer .= $bytes;
            if (str_contains($buffer, "stop\n") || strlen($buffer) > 1024) {
                $this->loop->cancel($watcher);
                $this->stop();
            }
        });
    }

    /**
     * Hands a failure to the group's failure callbacks, or to PHP's error
     * log when there are none or one of them fails.
     */
    private function report(\Throwable $error, string $what): void
    {
        $callbacks = $this->group->failureCallbacks();
        try {
            foreach ($callbacks as $callback) {
                $callback($error, $this);
            }
        } catch (\Throwable $reportingError) {
            error_log('[Crab] Failure callback failed: '.$reportingError->getMessage());
            $callbacks = [];
        }
        if ($callbacks === []) {
            error_log('[Crab] '.$what.': '.$error);
        }
    }

    private function hasWork(): bool
    {
        return $this->connections !== [] || $this->datagrams !== [] || $this->tasks !== [] || $this->pendingConnects !== [];
    }

    private function finishDrain(): void
    {
        if ($this->draining && !$this->starting && !$this->hasWork()) { $this->loop->stop(); }
    }
}
