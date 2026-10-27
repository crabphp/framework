<?php

declare(strict_types=1);

namespace Crab\Contracts\Container;

/**
 * Thrown when what an application names for the container to make cannot
 * be made: an action, middleware, listener, scheduled task or command, or
 * something one of them needs. Its message names every one of them and is
 * written for whoever runs the application, so it is shown as it is,
 * without a stack trace.
 */
interface MissingServices extends \Throwable
{
    /**
     * One sentence for each, saying where it is named and what is missing.
     *
     * @return list<string>
     */
    public function problems(): array;
}
