<?php

declare(strict_types=1);

namespace Crab\Queue;

/**
 * How long one try of a job may run, in place of "queue.timeout". When
 * the time is up, the Cancellation its handle() may take is cancelled,
 * and the try counts as failed when the job throws for it. A job that
 * goes on regardless, or blocks its process, is ended with the process
 * once the queue's grace has passed too.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Timeout
{
    public function __construct(
        public int $seconds,
    ) {
        if ($seconds < 1) {
            throw new \InvalidArgumentException('Timeout seconds must be at least 1.');
        }
    }

    /**
     * Reads a job's attribute once per process, including its absence,
     * as Retry::for() does.
     */
    public static function for(string $job): ?self
    {
        /** @var array<string, self|null> $jobs */
        static $jobs = [];
        if (array_key_exists($job, $jobs)) {
            return $jobs[$job];
        }
        if (!class_exists($job)) {
            return null;
        }
        $attributes = new \ReflectionClass($job)->getAttributes(self::class);
        try {
            $timeout = $attributes === [] ? null : $attributes[0]->newInstance();
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException(
                "The job {$job} has an invalid timeout: {$error->getMessage()} Use one #[Timeout] with seconds set to an integer of at least 1.",
                previous: $error,
            );
        }
        return $jobs[$job] = $timeout;
    }
}
