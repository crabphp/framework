<?php

declare(strict_types=1);

namespace Crab\Broadcast;

use Crab\Contracts\Broadcast\Broadcaster as BroadcasterContract;
use Crab\Contracts\Broadcast\Carrier;
use Crab\Contracts\Config\Config;
use Crab\Contracts\Container\Container;
use Crab\Contracts\WebSocket\Channels as ChannelsContract;
use Crab\Contracts\WorkerProvider;
use Crab\Engine\Process\Worker;

/**
 * Lazy broadcasting and membership bindings. Carriers belong to workers,
 * and no subscription, connection or timer exists until first use.
 */
final class BroadcastProvider implements WorkerProvider
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults('broadcast', [
            'carrier' => 'local',
            'prefix' => 'crab:broadcast:',
            'max_channels' => 64,
            'batch' => 128,
            'max_pending_bytes' => 1_048_576,
            'max_backlog_bytes' => 524_288,
        ]);
        $container->instance(Lifecycle::class, new Lifecycle());
        $container->singleton(Carrier::class, static function (Container $container): Carrier {
            $name = $container->get(Config::class)->get('broadcast.carrier');
            if ($name !== 'local') {
                throw new \LogicException(
                    "Unknown broadcast carrier {$name}. For redis, install crabphp/redis and register RedisProvider after BroadcastProvider; otherwise bind "
                    . Carrier::class
                    . '.',
                );
            }
            $life = $container->get(Lifecycle::class);
            return $life->carrier = new LocalCarrier(static fn(): bool => $life->serving);
        });
        $container->singleton(BroadcasterContract::class, static function (Container $container): Broadcaster {
            $life = $container->get(Lifecycle::class);
            $life->carrier = $container->get(Carrier::class);
            return new Broadcaster($life->carrier);
        });
        $container->singleton(Broadcaster::class, BroadcasterContract::class);
        $container->singleton(ChannelsContract::class, static function (Container $container): Channels {
            $config = $container->get(Config::class);
            $limits = [];
            foreach (['max_channels', 'batch', 'max_pending_bytes', 'max_backlog_bytes'] as $key) {
                $value = filter_var($config->get('broadcast.' . $key), FILTER_VALIDATE_INT);
                if ($value === false || $value < 1) {
                    throw new \InvalidArgumentException("Set broadcast.{$key} to a positive whole number.");
                }
                $limits[$key] = $value;
            }
            $life = $container->get(Lifecycle::class);
            $life->carrier = $container->get(Carrier::class);
            return $life->channels = new Channels(
                $life->carrier,
                $limits['max_channels'],
                $limits['batch'],
                $limits['max_pending_bytes'],
                $limits['max_backlog_bytes'],
            );
        });
        $container->singleton(Channels::class, ChannelsContract::class);
    }

    public function start(Worker $worker, Container $container): void
    {
        // Glue marks HTTP workers; nothing is opened at worker start.
    }

    public function stop(Worker $worker, Container $container): void
    {
        $container->get(Lifecycle::class)->close();
    }
}
