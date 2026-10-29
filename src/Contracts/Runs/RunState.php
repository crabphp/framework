<?php

declare(strict_types=1);

namespace Crab\Contracts\Runs;

/**
 * A run as it stands, as Runs::find() reads it.
 */
final readonly class RunState
{
    /**
     * @param class-string $run The class whose handle() it runs.
     * @param array<string, mixed> $data What it was started with.
     * @param mixed $result What its handle() returned, once completed.
     * @param string|null $error Why its last try failed.
     * @param int $attempts How many tries have failed since it was started or retried.
     * @param list<string> $steps The names of the steps done, sleeps and signals included, in order.
     */
    public function __construct(
        public int|string $id,
        public string $run,
        public array $data,
        public Status $status,
        public mixed $result,
        public ?string $error,
        public int $attempts,
        public array $steps,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {}
}
