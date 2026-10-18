<?php

declare(strict_types=1);

namespace Crab\Events;

use Crab\Contracts\Events\Dispatcher as DispatcherContract;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * A PSR-14 dispatcher that keeps its own listeners. Listeners run one
 * after another in the fiber that dispatched, so a listener that waits
 * holds its caller; hand slow work to the queue instead. What a listener
 * throws reaches whoever dispatched, and the listeners after it do not
 * run.
 */
final class Dispatcher implements DispatcherContract, ListenerProviderInterface
{
    /**
     * Every listener with the event class it was added for, in the order added.
     *
     * @var list<array{class-string, \Closure|string}>
     */
    private array $listeners = [];
    /**
     * Listener classes already made, by name.
     *
     * @var array<string, \Closure>
     */
    private array $made = [];

    /**
     * @param ContainerInterface|null $container Makes a listener given by class name; without one it is made with `new`.
     */
    public function __construct(
        private readonly ?ContainerInterface $container = null,
    ) {}

    public function listen(string $event, \Closure|string $listener): void
    {
        if (!class_exists($event) && !interface_exists($event)) {
            throw new \LogicException(
                "A listener was added for {$event}, which is not a class or an interface. An event is an object; listen for its class.",
            );
        }
        if (is_string($listener) && !method_exists($listener, 'handle')) {
            throw new \LogicException(
                "The listener {$listener} for {$event} is not a class with a handle() method. Give a closure, or a class whose handle() takes the event.",
            );
        }
        $this->listeners[] = [$event, $listener];
    }

    public function dispatch(object $event): object
    {
        $stoppable = $event instanceof StoppableEventInterface;
        foreach ($this->getListenersForEvent($event) as $listener) {
            if ($stoppable && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }
        return $event;
    }

    /**
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listeners as [$class, $listener]) {
            if (!$event instanceof $class) {
                continue;
            }

            yield $listener instanceof \Closure ? $listener : ($this->made[$listener] ??= $this->make($listener));
        }
    }

    private function make(string $class): \Closure
    {
        $listener = $this->container?->has($class) ? $this->container->get($class) : new $class();
        return $listener->handle(...);
    }
}
