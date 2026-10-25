<?php

declare(strict_types=1);

namespace Crab\Contracts\Storage;

/**
 * The kinds of disk a "driver" setting can name. The framework has
 * "local" and "memory"; a package adds its own in its provider:
 *
 *     $container->get(Drivers::class)->add('s3', ['key', 'secret', 'bucket'], static fn (array $settings, string $name): Disk => new S3Disk(...));
 *
 * A disk's settings are checked against the names its driver takes
 * before the driver is called: one it does not take is refused, naming
 * the disk, so a misspelt key is never quietly left out.
 */
interface Drivers
{
    /**
     * @param list<string> $settings The settings a disk of this driver takes, besides "driver".
     * @param \Closure(array<string, mixed> $settings, string $name): Disk $make Called with a disk's settings and name, once, when the disk is first used.
     */
    public function add(string $driver, array $settings, \Closure $make): void;
}
