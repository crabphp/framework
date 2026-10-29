<?php

declare(strict_types=1);

namespace Crab\Contracts\Runs;

/**
 * The run a run's handle() is part of, given to it by the worker that
 * resumes it. What happens through it is kept, so that a run stopped by a
 * crash, a deploy or an error goes on from where it was:
 *
 *     $plan = $run->step('plan', fn () => $this->llm->plan($topic));
 *     $run->sleep('cool-down', 300);
 *     $approved = $run->await('approval');
 *
 * A run is resumed by running its handle() again from the top: each step
 * already done returns what it returned then, without running again. So
 * code between steps must come out the same each time, and anything slow,
 * random or outside the process belongs in a step.
 */
interface Run
{
    /**
     * The run's id, as start() returned it.
     */
    public function id(): int|string;

    /**
     * Runs $work once for this run and keeps what it returns; on every
     * later resumption returns what was kept, without running it. What
     * it returns is kept as JSON and handed back as JSON reads it, the
     * first time too: numbers, text, booleans, null and arrays of them.
     *
     * A step that throws is not kept, and runs again when the run is
     * tried again. A step that finished just before its process died may
     * run twice, so make its work safe to repeat.
     *
     * @template T
     * @param string $name Unique within the run: "plan", "search.3".
     * @param \Closure(): T $work
     * @return T
     */
    public function step(string $name, \Closure $work): mixed;

    /**
     * Waits $seconds without holding a worker: the run is put aside and
     * resumed once the time has come, after a deploy or a restart too.
     * Resumed later, a sleep whose time has passed returns at once.
     */
    public function sleep(string $name, int $seconds): void;

    /**
     * Waits for what Runs::signal() gives this run under $name, from a
     * request, a webhook or another job, and returns it. The run is put
     * aside meanwhile, holding no worker, and resumed when it comes. A
     * signal given before the run reaches its await() is kept for it.
     */
    public function await(string $name): mixed;
}
