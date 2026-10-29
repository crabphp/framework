<?php

declare(strict_types=1);

namespace Crab\Runs;

/**
 * Thrown by a sleep() or an await() that must wait, to end this try of a
 * run cleanly; the run is put aside and resumed when the wait is over.
 * It passes through the run's own code, so a run must not catch every
 * Throwable around a sleep or an await.
 *
 * @internal
 */
final class Suspended extends \Exception
{
    /**
     * @param int|null $wakeAt When a sleep ends, as a Unix timestamp; null for an await.
     */
    public function __construct(
        public readonly string $name,
        public readonly ?int $wakeAt,
    ) {
        parent::__construct($wakeAt === null ? "The run waits for \"{$name}\"." : "The run sleeps in \"{$name}\".");
    }
}
