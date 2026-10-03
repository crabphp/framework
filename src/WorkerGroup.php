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
    private bool $frozen = false;

    public function __construct(
        public readonly string $name,
        public readonly int $workers = 1,
        public readonly int $maxConnections = 512,
        public readonly int $maxTasks = 1024,
    ) {
        if ($name === '' || $workers < 1 || $maxConnections < 1 || $maxTasks < 1) {
            throw new \InvalidArgumentException('Group name, worker count, connection limit and task limit must be valid.');
        }
    }

    public function listen(string $address, callable $handler, array $options = []): self
    {
        $this->assertMutable();
        $this->listeners[] = ['address' => $address, 'handler' => $handler(...), 'options' => $options];
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

    public function listeners(): array { return $this->listeners; }
    public function datagrams(): array { return $this->datagrams; }
    public function startCallbacks(): array { return $this->startCallbacks; }
    public function freeze(): void { $this->frozen = true; }

    private function assertMutable(): void
    {
        if ($this->frozen) {
            throw new \LogicException('Worker group configuration is frozen after startup.');
        }
    }
}
