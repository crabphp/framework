<?php

declare(strict_types=1);

namespace Crab\Contracts\Schedule;

/**
 * Dispatched by the scheduler after each run of a task, however it
 * ended, when the application has an event dispatcher. A run that was
 * passed over, because the task was still running or another server had
 * taken it, is not a run.
 */
final readonly class TaskRan
{
    /**
     * @param string $task The task's name.
     * @param float $seconds How long the run took.
     * @param \Throwable|null $error What it threw; null when it finished.
     */
    public function __construct(
        public string $task,
        public float $seconds,
        public ?\Throwable $error = null,
    ) {
    }
}
