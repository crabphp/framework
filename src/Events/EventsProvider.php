<?php

declare(strict_types=1);

namespace Crab\Events;

use Crab\Contracts\Container\Container;
use Crab\Contracts\Events\Dispatcher as DispatcherContract;
use Crab\Contracts\Provider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Binds the event dispatcher. Register it before the providers that add
 * listeners, which they do in register():
 *
 *     $container->get(Dispatcher::class)->listen(UserRegistered::class, SendWelcome::class);
 */
final class EventsProvider implements Provider
{
    public function register(Container $container): void
    {
        // Made now, in the parent: listeners are part of what the application is defined as.
        $dispatcher = new Dispatcher($container);
        $container->instance(DispatcherContract::class, $dispatcher);
        $container->singleton(Dispatcher::class, DispatcherContract::class);
        $container->singleton(EventDispatcherInterface::class, DispatcherContract::class);
        $container->singleton(ListenerProviderInterface::class, DispatcherContract::class);
    }
}
