<?php

declare(strict_types=1);

namespace Crab\Contracts\Clock;

use Psr\Clock\ClockInterface;

/**
 * Tells the time: now() returns the current moment. It is PSR-20's clock
 * under Crab's own name, so application code can ask for a Crab type; a
 * clock from any PSR-20 library fits wherever only now() is needed.
 */
interface Clock extends ClockInterface
{
}
