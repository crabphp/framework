<?php

declare(strict_types=1);

namespace Crab\HttpClient;

/**
 * The time a request was allowed ran out, and what it was doing then.
 *
 * @internal
 */
final class TimedOut extends \RuntimeException
{
    public function __construct(public readonly string $doing)
    {
        parent::__construct("it timed out while {$doing}");
    }
}
