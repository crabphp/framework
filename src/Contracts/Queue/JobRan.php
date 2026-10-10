<?php

declare(strict_types=1);

namespace Crab\Contracts\Queue;

/**
 * Dispatched by the queue's consumer after each try of a job, however it
 * ended, when the application has an event dispatcher.
 */
final readonly class JobRan
{
    /**
     * @param Message $message The job, with this try counted in its attempts.
     * @param float $seconds How long the try took.
     * @param \Throwable|null $error What it threw; null when it finished.
     * @param bool $gaveUp Whether this was its last try and it is now kept as failed.
     */
    public function __construct(
        public Message $message,
        public float $seconds,
        public ?\Throwable $error = null,
        public bool $gaveUp = false,
    ) {
    }
}
