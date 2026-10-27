<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\Crab;
use Crab\Engine\EventLoop;
use Crab\Engine\FailureReporter;
use Crab\Engine\Network\Connection;
use Crab\Engine\Network\Connector;
use Crab\Engine\Network\Listener;
use Crab\Engine\Network\UdpEndpoint;

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
     * Connections with unfinished work at the drain deadline, counted before close
     * callbacks remove them so the stop callback can report the cut once.
     */
    private int $cutConnections = 0;
    /**
     * What the application counts as it works, such as requests served, by name.
     *
     * @var array<string, int>
     */
    private array $counts = [];
    private float $started;
    /**
     * A report the control stream has not yet taken all of.
     */
    private string $unsent = '';
    /**
     * The deadlines set and not withdrawn, as hrtime seconds, by id.
     *
     * @var array<int, float>
     */
    private array $deadlines = [];
    private int $nextDeadline = 0;

    /**
     * Under a supervisor, the worker reports its stats() over the control
     * stream about once a second; alone, it hands them to $report.
     *
     * @param array{tcp:list<resource>, udp:list<resource>} $bindings
     * @param (\Closure(array<string, mixed>): void)|null $report
     */
    public function __construct(
        public readonly EventLoop $loop,
        public readonly Crab $group,
        public readonly int $index,
        private array $bindings,
        private readonly float $shutdownTimeout,
        private mixed $control = null,
        private readonly ?\Closure $report = null,
    ) {
        $this->started = microtime(true);
    }

    public function run(): void
    {
        $handlesFailures = $this->group->failureCallbacks() !== [];
        $previousHandler = null;
        try {
            if ($handlesFailures) {
                // Everything the engine catches in this worker goes to the group's callbacks.
                $previousHandler = FailureReporter::handleWith($this->notify(...));
                // Otherwise an uncaught failure in a timer or watcher ends the worker.
                $this->loop->setErrorHandler(
                    static fn(\Throwable $error) => FailureReporter::report('Callback failed', $error),
                );
            }
            $this->watchControl();
            if (extension_loaded('pcntl')) {
                $this->loop->onSignal(SIGTERM, fn() => $this->stop());
                $this->loop->onSignal(SIGINT, fn() => $this->stop());
            }
            foreach ($this->group->listeners() as $i => $definition) {
                $listener = new Listener(
                    $this->loop,
                    $this->bindings['tcp'][$i],
                    function (Connection $connection) use ($definition): void {
                        if (
                            $this->draining
                            || (count($this->connections) + count($this->pendingConnects))
                                >= $this->group->maxConnections
                        ) {
                            $connection->close();
                            return;
                        }
                        $this->track($connection);
                        $definition['handler']($connection);
                    },
                );
                $this->listeners[] = $listener;
                $listener->attach();
            }
            foreach ($this->group->datagrams() as $i => $definition) {
                $options = $definition['options'];
                $endpoint = new UdpEndpoint(
                    $this->loop,
                    $this->bindings['udp'][$i],
                    $definition['handler'],
                    $options['maxDatagramSize'] ?? 65_507,
                    $options['maxQueueBytes'] ?? 1_048_576,
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
            $this->reportStats();
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
                        FailureReporter::report('Stop callback failed', $error);
                    }
                }
            }
            foreach ($this->listeners as $listener) {
                $listener->close();
            }
            foreach ($this->pendingConnects as $cancel) {
                $cancel();
            }
            foreach ($this->tasks as $task) {
                $task->cancel();
            }
            foreach ($this->connections as $connection) {
                $connection->close();
            }
            foreach ($this->datagrams as $endpoint) {
                $endpoint->close();
            }
            foreach ($this->loop->getIdentifiers() as $id) {
                $this->loop->cancel($id);
            }
            foreach ($this->keepAlive as $stream) {
                if (!is_resource($stream)) {
                    continue;
                }

                fclose($stream);
            }
            if (is_resource($this->control)) {
                fclose($this->control);
            }
            if ($handlesFailures) {
                FailureReporter::handleWith($previousHandler);
            }
        }
    }

    public function stop(): void
    {
        if ($this->draining) {
            return;
        }
        $this->draining = true;
        foreach ($this->listeners as $listener) {
            $listener->close();
        }
        foreach ($this->pendingConnects as $cancel) {
            $cancel();
        }
        foreach ($this->tasks as $task) {
            $task->cancel();
        }
        // Count and close before the transports' matching deadlines can fire.
        $this->loop->delay($this->shutdownTimeout, function (): void {
            foreach ($this->connections as $connection) {
                if (!$connection->hasPendingWork()) {
                    continue;
                }

                ++$this->cutConnections;
            }
            foreach ($this->connections as $connection) {
                $connection->close();
            }
            foreach ($this->datagrams as $endpoint) {
                $endpoint->close();
            }
            $this->loop->stop();
        });
        foreach ($this->connections as $connection) {
            $connection->drain($this->shutdownTimeout);
        }
        foreach ($this->datagrams as $endpoint) {
            $endpoint->drain();
        }
        $this->finishDrain();
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    public function spawn(callable $operation): Task
    {
        if ($this->draining) {
            throw new \LogicException('Cannot start a task while draining.');
        }
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
                FailureReporter::report('Task failed', $error);
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
        if (
            $this->draining
            || (count($this->connections) + count($this->pendingConnects)) >= $this->group->maxConnections
        ) {
            throw new \OverflowException('Worker is draining or its connection limit has been reached.');
        }
        $id = ++$this->nextConnect;
        $completed = false;
        $cancel = Connector::connect(
            $this->loop,
            $address,
            function (?Connection $connection, ?\Throwable $error) use ($id, $completion, &$completed): void {
                $completed = true;
                unset($this->pendingConnects[$id]);
                if ($connection !== null) {
                    $this->track($connection);
                } else {
                    $this->updateAdmission();
                }
                try {
                    $completion($connection, $error);
                } finally {
                    $this->finishDrain();
                }
            },
            $timeout,
        );
        if (!$completed) {
            $this->pendingConnects[$id] = $cancel;
            $this->updateAdmission();
        }
        return $cancel(...);
    }

    /**
     * Adds to one of the worker's counts, which its stats() carry to
     * whoever watches the server: `$worker->count('requests')`.
     */
    public function count(string $name, int $by = 1): void
    {
        $this->counts[$name] = ($this->counts[$name] ?? 0) + $by;
    }

    /**
     * Asks the supervisor to end this worker's process if it still runs
     * $seconds from now and the closure returned has not been called by
     * then. It is the one limit that holds when code blocks the process,
     * so that no timer of the worker's own can fire; everything else the
     * process is doing ends with it, and the supervisor starts another in
     * its place. A worker run alone, without a supervisor, is never ended
     * this way.
     *
     * @return \Closure(): void Withdraws the deadline.
     */
    public function deadline(float $seconds): \Closure
    {
        if (!is_resource($this->control)) {
            return static function (): void {};
        }
        $id = $this->nextDeadline++;
        $this->deadlines[$id] = (hrtime(true) / 1e9) + max(0.0, $seconds);
        $this->sendDeadline();
        return function () use ($id): void {
            if (!isset($this->deadlines[$id])) {
                return;
            }
            unset($this->deadlines[$id]);
            $this->sendDeadline();
        };
    }

    public function stats(): array
    {
        return [
            'pid' => getmypid(),
            'group' => $this->group->name,
            'index' => $this->index,
            'started' => $this->started,
            'connections' => count($this->connections),
            'pendingConnects' => count($this->pendingConnects),
            'tasks' => count($this->tasks),
            'datagrams' => count($this->datagrams),
            'queuedBytes' => array_sum(array_map(
                static fn(Connection $c): int => $c->bufferedBytes(),
                $this->connections,
            )),
            'memoryBytes' => memory_get_usage(true),
            'draining' => $this->draining,
            'cutConnections' => $this->cutConnections,
            'counts' => $this->counts,
        ];
    }

    /**
     * Reports now and then about once a second, on a timer that does not
     * keep the loop running. A report the control stream could not take
     * whole is finished before the next is written.
     */
    private function reportStats(): void
    {
        if (!is_resource($this->control) && $this->report === null) {
            return;
        }
        $send = function (): void {
            if ($this->report !== null) {
                ($this->report)($this->stats());
                return;
            }
            if ($this->unsent === '') {
                $this->unsent = 'stats ' . json_encode($this->stats(), JSON_PRESERVE_ZERO_FRACTION) . "\n";
            }
            $this->flush();
        };
        $send();
        $this->loop->unreference($this->loop->repeat(1.0, $send));
    }

    /**
     * Writes what the control stream will take of the lines not yet sent.
     */
    private function flush(): void
    {
        if (!is_resource($this->control)) {
            $this->unsent = '';
            return;
        }
        $written = @fwrite($this->control, $this->unsent);
        if (is_int($written) && $written > 0) {
            $this->unsent = (string) substr($this->unsent, $written);
        }
    }

    /**
     * Tells the supervisor the earliest deadline still set, in seconds from now.
     */
    private function sendDeadline(): void
    {
        $seconds = $this->deadlines === []
            ? 'none'
            : sprintf('%.3F', max(0.0, min($this->deadlines) - (hrtime(true) / 1e9)));
        $this->unsent .= "deadline {$seconds}\n";
        $this->flush();
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
        $atCapacity =
            $this->draining
            || (count($this->connections) + count($this->pendingConnects)) >= $this->group->maxConnections;
        foreach ($this->listeners as $listener) {
            if ($atCapacity) {
                $listener->pause();
            } else {
                $listener->resume();
            }
        }
    }

    private function watchControl(): void
    {
        if (!is_resource($this->control)) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($pair === false) {
                throw new \RuntimeException('Could not create worker wakeup stream.');
            }
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
     * The worker's failure handler: hands a failure to the group's
     * callbacks. A notice without an error is left to standard error, as
     * is anything a callback itself throws.
     */
    private function notify(string $what, ?\Throwable $error): bool
    {
        if ($error === null) {
            return false;
        }
        foreach ($this->group->failureCallbacks() as $callback) {
            $callback($error, $this);
        }
        return true;
    }

    private function hasWork(): bool
    {
        return (
            $this->connections !== []
            || $this->datagrams !== []
            || $this->tasks !== []
            || $this->pendingConnects !== []
        );
    }

    private function finishDrain(): void
    {
        if ($this->draining && !$this->starting && !$this->hasWork()) {
            $this->loop->stop();
        }
    }
}
