<?php

declare(strict_types=1);

namespace Crab\Contracts\Queue;

/**
 * Why a try of a job ended: it ran past its timeout. The job was told by
 * the cancellation its handle() may take, and a listener of JobRan finds
 * this as the try's error.
 */
final class TimedOut extends \RuntimeException
{
    public function __construct(
        public readonly int $seconds,
    ) {
        parent::__construct("The job ran past its timeout of {$seconds} " . ($seconds === 1 ? 'second.' : 'seconds.'));
    }
}
