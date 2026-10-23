<?php

declare(strict_types=1);

namespace Crab\Contracts\Events;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * PSR-14's dispatcher, and the way listeners are added to it. An event is
 * any object; dispatch() hands it to each listener of its class, in the
 * order they were added, and returns it.
 */
interface Dispatcher extends EventDispatcherInterface
{
    /**
     * Adds a listener for an event class; it also hears every class that
     * extends or implements it. A listener is a closure taking the event,
     * or the name of a class with handle(), which is made only when its
     * first event is dispatched. Listeners are added while the application
     * is defined, in a provider's register().
     *
     * @param class-string $event
     * @param \Closure(object): void|class-string $listener
     */
    public function listen(string $event, \Closure|string $listener): void;

    /**
     * Every event class with its listeners, in the order added: a class
     * by its name, a closure as "Closure".
     *
     * @return array<class-string, list<string>>
     */
    public function listeners(): array;
}
