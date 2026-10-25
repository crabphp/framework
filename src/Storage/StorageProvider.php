<?php

declare(strict_types=1);

namespace Crab\Storage;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Container\Container;
use Crab\Contracts\Storage\Disk;
use Crab\Contracts\Storage\Drivers;
use Crab\Contracts\Storage\Storage;
use Crab\Contracts\WorkerProvider;
use Crab\Engine\Process\Worker;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Binds the disks of the "storage" configuration section:
 *
 *     default   the disk used when none is named; "local"
 *     disks     each disk's settings, by a name of your choosing, with its "driver"
 *
 * The drivers are "local", a folder on this machine, and "memory", for
 * tests; crabphp/s3 adds "s3". A local disk's settings:
 *
 *     root      the folder; storage/files in the application unless set
 *     url       where the folder is served from, for url(); none unless set
 *
 * Every disk's driver is checked as each worker starts, so a misspelt
 * one stops the server then and not on its first upload.
 */
final class StorageProvider implements WorkerProvider
{
    public function register(Container $container): void
    {
        $container->get(Config::class)->defaults(
            'storage',
            [
                'default' => 'local',
                'disks' => [
                    'local' => ['driver' => 'local', 'root' => null, 'url' => null],
                ],
            ],
            [
                'default' => ['type' => 'string', 'minLength' => 1],
                'disks' => [
                    'type' => 'object',
                    'additionalProperties' => [
                        'type' => 'object',
                        // A driver's other settings are its own, and it checks them.
                        'properties' => [
                            'driver' => [
                                'type' => 'string',
                                'minLength' => 1,
                                'examples' => ['local', 'memory', 's3'],
                            ],
                        ],
                    ],
                ],
            ],
        );
        $container->singleton(StorageManager::class, static function (Container $container): StorageManager {
            $storage = new StorageManager($container->get(Config::class));
            $storage->add('local', ['root', 'url'], static function (array $settings, string $name) use (
                $container,
            ): LocalDisk {
                $root = $settings['root'] ?? null;
                if ($root === null) {
                    $base = $container->get(Config::class)->get('app.path');
                    if (!is_string($base)) {
                        throw new \LogicException(
                            "The disk \"{$name}\" needs a folder: set its \"root\", or build the application with App::configure(\$basePath).",
                        );
                    }
                    $root = $base . '/storage/files';
                }
                $url = $settings['url'] ?? null;
                return new LocalDisk(
                    (string) $root,
                    $container->get(StreamFactoryInterface::class),
                    $url === null ? null : (string) $url,
                );
            });
            $storage->add('memory', [], static function (array $settings, string $name) use ($container): MemoryDisk {
                return new MemoryDisk(
                    $container->get(StreamFactoryInterface::class),
                    $container->has(ClockInterface::class) ? $container->get(ClockInterface::class) : null,
                );
            });
            return $storage;
        });
        $container->singleton(Storage::class, StorageManager::class);
        $container->singleton(Drivers::class, StorageManager::class);
        $container->singleton(Disk::class, static fn(Container $container): Disk => $container->get(
            Storage::class,
        )->disk());
    }

    public function start(Worker $worker, Container $container): void
    {
        $problems = $container->get(StorageManager::class)->problems();
        if ($problems !== []) {
            throw new \LogicException(implode("\n", $problems));
        }
    }

    public function stop(Worker $worker, Container $container): void {}
}
