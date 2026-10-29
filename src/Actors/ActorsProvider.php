<?php

declare(strict_types=1);

namespace Crab\Actors;

use Crab\Actors\Commands\Work;
use Crab\Broadcast\LocalCarrier;
use Crab\Contracts\Actors\Actors as ActorsContract;
use Crab\Contracts\Broadcast\Carrier;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Console;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Servers;
use Crab\Contracts\WorkerProvider;
use Crab\Contracts\Workers;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Worker;
use Psr\Log\LoggerInterface;

/**
 * Binds actors from the "actors" configuration section, registers the
 * "actors" server and adds `actors:work`. With the local broadcast
 * carrier, an actor runs in the process that sends to it; with another,
 * such as Redis's, in the "actors" server, so the broadcast provider and
 * the carrier's come before this one.
 *
 *     workers    how many processes the "actors" server has; 2
 *     idle       seconds an actor's inbox may be empty before it ends; 60
 *     max_inbox  the most messages waiting in one actor's inbox; 1000
 */
final class ActorsProvider implements WorkerProvider
{
    /**
     * The actors of this process, once anything has sent to one.
     */
    private ?Actors $made = null;

    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults(
            'actors',
            [
                'workers' => 2,
                'idle' => 60,
                'max_inbox' => 1000,
            ],
            [
                'workers' => ['type' => 'integer', 'minimum' => 1],
                'idle' => ['type' => 'number', 'exclusiveMinimum' => 0],
                'max_inbox' => ['type' => 'integer', 'minimum' => 1],
            ],
        );
        $container->singleton(Actors::class, function (Container $container): Actors {
            $config = $container->get(Config::class);
            $config->check('actors');
            $carrier = $container->has(Carrier::class) ? $container->get(Carrier::class) : null;
            return $this->made = new Actors(
                $container,
                $carrier instanceof LocalCarrier ? null : $carrier,
                (int) $config->get('actors.workers'),
                (float) $config->get('actors.idle'),
                (int) $config->get('actors.max_inbox'),
                $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null,
            );
        });
        $container->singleton(ActorsContract::class, Actors::class);
        if ($container->has(Servers::class)) {
            // Named in "server.with", the actors run in workers of their own, beside HTTP.
            $container->get(Servers::class)->add(
                'actors',
                static function () use ($container): array {
                    $config = $container->get(Config::class);
                    $config->check('actors');
                    self::requireCarrier($container);
                    return [new Workers(
                        'actors',
                        static function (Worker $worker, Cancellation $cancellation) use ($container): void {
                            $container->get(Actors::class)->serve([$worker->index], $cancellation);
                        },
                        (int) $config->get('actors.workers'),
                    )];
                },
                10.0,
                'actors:work',
            );
        }
        if ($container->has(Console::class)) {
            $container->get(Console::class)->add('actors:work', Work::class);
        }
    }

    public function start(Worker $worker, Container $container): void {}

    public function stop(Worker $worker, Container $container): void
    {
        // Only where actors were sent to: the inboxes end, and the actors' waits are cancelled.
        $this->made?->stop();
    }

    /**
     * Refuses to run the "actors" server where it would have nothing to do.
     */
    public static function requireCarrier(Container $container): void
    {
        $carrier = $container->has(Carrier::class) ? $container->get(Carrier::class) : null;
        if ($carrier === null || $carrier instanceof LocalCarrier) {
            throw new \LogicException(
                'The "actors" server needs a carrier between processes: set broadcast.carrier to redis and register RedisProvider. With the local carrier, an actor runs in the process that sends to it, and no server is needed.',
            );
        }
    }
}
