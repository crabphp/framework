<?php

declare(strict_types=1);

namespace Crab\Contracts\Storage;

/**
 * The application's disks, by name, from the "storage" configuration
 * section. Ask for Disk to have the default one.
 *
 *     $storage->disk('uploads')->write("avatars/{$id}.png", $file->getStream());
 */
interface Storage
{
    /**
     * @param string|null $name The default disk when null.
     */
    public function disk(?string $name = null): Disk;
}
