<?php

declare(strict_types=1);

namespace Crab\Contracts\Storage;

/**
 * A path a disk will not use: empty, absolute, or climbing out with "..".
 */
final class InvalidPath extends StorageException {}
