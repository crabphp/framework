<?php

declare(strict_types=1);

namespace Crab\Contracts\Runs;

/**
 * Where a run is.
 */
enum Status: string
{
    /** Started, or to be tried again, and waiting for a worker. */
    case Pending = 'pending';
    /** A worker is running it. */
    case Running = 'running';
    /** In a sleep(), to be resumed when its time comes. */
    case Sleeping = 'sleeping';
    /** In an await(), to be resumed by a signal. */
    case Waiting = 'waiting';
    /** Its handle() returned; its result is kept. */
    case Completed = 'completed';
    /** Its handle() failed on every try; Runs::retry() tries it again. */
    case Failed = 'failed';
}
