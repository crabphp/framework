<?php

declare(strict_types=1);

namespace Crab\Contracts\Storage;

/**
 * What a disk knows of a file it keeps.
 */
final readonly class File
{
    /**
     * @param string $path Its path on the disk.
     * @param int $size In bytes.
     * @param \DateTimeImmutable $modified When it was last written.
     * @param string|null $type Its media type, when the disk keeps one.
     */
    public function __construct(
        public string $path,
        public int $size,
        public \DateTimeImmutable $modified,
        public ?string $type = null,
    ) {}
}
