<?php

declare(strict_types=1);

namespace Crab\Engine;

final class WorkerGroup
{
    /**
     * @var list<array{address:string, handler:\Closure, options:array}>
     */
    private array $listeners = [];
    /**
     * @var list<array{address:string, handler:\Closure, options:array}>
     */
    private array $datagrams = [];
    /**
     * @var list<\Closure>
     */
    private array $startCallbacks = [];
    /**
     * @var list<\Closure>
     */
    private array $stopCallbacks = [];
    /**
     * @var list<\Closure>
     */
    private array $failureCallbacks = [];
    private bool $frozen = false;

    /**
     * @param float|null $shutdownTimeout Seconds this group's workers have to drain; null uses the runtime's deadline.
     */
    public function __construct(
        public readonly string $name,
        public readonly int $workers = 1,
        public readonly int $maxConnections = 512,
        public readonly int $maxTasks = 1024,
        public readonly ?float $shutdownTimeout = null,
    ) {
        if ($name === '' || $workers < 1 || $maxConnections < 1 || $maxTasks < 1) {
            throw new \InvalidArgumentException('Group name, worker count, connection limit and task limit must be valid.');
        }
        if ($shutdownTimeout !== null && (!is_finite($shutdownTimeout) || $shutdownTimeout <= 0)) {
            throw new \InvalidArgumentException('Set the worker shutdown timeout to a finite number of seconds greater than zero.');
        }
    }

    /**
     * @param resource|null $socket A socket that already listens on the address, inherited from the server this one takes over from; it is used instead of binding the address.
     */
    public function listen(string $address, callable $handler, array $options = [], mixed $socket = null): self
    {
        $this->assertMutable();
        if ($socket !== null && !is_resource($socket)) {
            throw new \InvalidArgumentException('An inherited socket must be an open stream.');
        }
        $this->listeners[] = ['address' => $address, 'handler' => $handler(...), 'options' => $options, 'socket' => $socket];
        return $this;
    }

    public function udp(string $address, callable $handler, array $options = []): self
    {
        $this->assertMutable();
        $this->datagrams[] = ['address' => $address, 'handler' => $handler(...), 'options' => $options];
        return $this;
    }

    public function onStart(callable $callback): self
    {
        $this->assertMutable();
        $this->startCallbacks[] = $callback(...);
        return $this;
    }

    /**
     * Runs in each worker once it has drained or reached its shutdown
     * deadline, before it releases what it still holds; the callback
     * receives the Worker. Callbacks run in reverse order of registration,
     * and only in a worker whose start callbacks all completed. The event
     * loop has stopped, so a callback must not suspend.
     */
    public function onStop(callable $callback): self
    {
        $this->assertMutable();
        $this->stopCallbacks[] = $callback(...);
        return $this;
    }

    /**
     * Receives (Throwable, Worker) when a task spawned on the worker fails,
     * a stop callback throws, or a timer or watcher callback lets a failure
     * escape. Without one, the first two go to PHP's error log and the
     * last ends the worker.
     */
    public function onFailure(callable $callback): self
    {
        $this->assertMutable();
        $this->failureCallbacks[] = $callback(...);
        return $this;
    }

    public function listeners(): array { return $this->listeners; }
    public function datagrams(): array { return $this->datagrams; }
    public function startCallbacks(): array { return $this->startCallbacks; }
    public function stopCallbacks(): array { return $this->stopCallbacks; }
    public function failureCallbacks(): array { return $this->failureCallbacks; }
    public function freeze(): void { $this->frozen = true; }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new \LogicException('Worker group configuration is frozen after startup.');
        }
    }
}
