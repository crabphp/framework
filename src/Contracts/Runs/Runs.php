<?php

declare(strict_types=1);

namespace Crab\Contracts\Runs;

use Crab\Contracts\Database\Connection;

/**
 * Starts runs: work of many steps that goes on from where it was after a
 * crash, a deploy or a failure, and may wait minutes or days between
 * them without holding a worker. A run is a class like a job, its
 * services in the constructor and its data in handle(), which also takes
 * the Run it is part of:
 *
 *     final readonly class ResearchTopic
 *     {
 *         public function __construct(private Llm $llm) {}
 *
 *         public function handle(Run $run, string $topic): string
 *         {
 *             $plan = $run->step('plan', fn () => $this->llm->plan($topic));
 *             return $run->step('report', fn () => $this->llm->report($plan));
 *         }
 *     }
 *
 *     $id = $runs->start(ResearchTopic::class, topic: 'pricing');
 *
 * Runs are kept in the database and carried out by queue workers.
 */
interface Runs
{
    /**
     * Starts a run of the class with the data its handle() takes, by name,
     * checked there and then as a pushed job's is.
     *
     * @param class-string $run
     * @param mixed ...$data The parameters of its handle(), by name: numbers, text, booleans, null and arrays of them.
     * @return int|string The run's id.
     */
    public function start(string $run, mixed ...$data): int|string;

    /**
     * The same, for a write made in a transaction: the run starts exactly
     * when the write is committed, and not at all when it is rolled back.
     *
     * @param Connection $transaction The connection the transaction's callback was given.
     */
    public function within(Connection $transaction): Runs;

    /**
     * Gives a run what its await($name) waits for, and resumes it. The
     * first signal under a name is the one kept.
     *
     * @return bool Whether it was kept: false when the run has had one under that name, has ended, or does not exist.
     */
    public function signal(int|string $id, string $name, mixed $value = null): bool;

    /**
     * The run as it stands, or null when there is none of that id.
     */
    public function find(int|string $id): ?RunState;

    /**
     * Tries a failed run again, with all its tries, from the step that
     * failed; or one left running by a worker that died and whose lease
     * has run out.
     *
     * @return bool Whether it is now to be tried again.
     */
    public function retry(int|string $id): bool;
}
