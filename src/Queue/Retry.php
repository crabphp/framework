<?php

declare(strict_types=1);

namespace Crab\Queue;

/**
 * Gives a job its own retry limits. A setting left null still comes
 * from the consumer, so a job can change only the limit it needs.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Retry
{
    /**
     * @param int|null $tries How many times the job may be started, or null for the consumer's limit.
     * @param list<int>|null $backoff Seconds between tries, repeating the last, or null for the consumer's pauses.
     */
    public function __construct(public ?int $tries = null, public ?array $backoff = null)
    {
        if ($tries === null && $backoff === null) {
            throw new \InvalidArgumentException('Retry needs tries, backoff or both. Set tries to at least 1 or backoff to a list of whole numbers greater than or equal to 0.');
        }
        if ($tries !== null && $tries < 1) {
            throw new \InvalidArgumentException('Retry tries must be at least 1.');
        }
        if ($backoff !== null) {
            if (!array_is_list($backoff)) {
                throw new \InvalidArgumentException('Retry backoff must be a list of whole numbers greater than or equal to 0.');
            }
            foreach ($backoff as $seconds) {
                if (!is_int($seconds) || $seconds < 0) {
                    throw new \InvalidArgumentException('Retry backoff must be a list of whole numbers greater than or equal to 0.');
                }
            }
        }
    }

    /**
     * Reads an existing job's attribute once per process, including its
     * absence. A missing class may become available later and is not cached.
     * The queue checks it before storing a job, and consumers use the
     * same reader so an invalid declaration names the job that needs fixing.
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
        $attributes = (new \ReflectionClass($job))->getAttributes(self::class);
        try {
            $retry = $attributes === [] ? null : $attributes[0]->newInstance();
        } catch (\Throwable $error) {
            throw new \InvalidArgumentException("The job {$job} has invalid retry settings: {$error->getMessage()} Use one #[Retry] declaration with tries set to an integer of at least 1 or backoff set to a list of whole numbers greater than or equal to 0, or both.", previous: $error);
        }
        return $jobs[$job] = $retry;
    }
}
