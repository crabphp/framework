<?php

declare(strict_types=1);

namespace Crab\Contracts\Storage;

/**
 * There is no file at the path.
 */
final class FileNotFound extends StorageException
{
    public function __construct(
        public readonly string $path,
        ?\Throwable $previous = null,
    ) {
        parent::__construct("There is no file at \"{$path}\".", 0, $previous);
    }
}
