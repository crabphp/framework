<?php

declare(strict_types=1);

namespace Crab\Cache;

use Psr\SimpleCache\InvalidArgumentException;

/**
 * A cache key that PSR-16 does not allow.
 */
final class InvalidKey extends \InvalidArgumentException implements InvalidArgumentException {}
