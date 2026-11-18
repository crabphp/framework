<?php

declare(strict_types=1);

namespace Crab\Contracts\Routing;

/**
 * Whether a request's action was called, for whatever writes the
 * request's error afterwards. An error alone does not say: a guard's
 * refusal and an exception thrown half way through an action can carry
 * the same status, and only one of them left the application as it was.
 *
 * It is request state and nothing else's: one is put on the request's
 * attribute of this name where the request enters, in front of every
 * middleware, when a Representation may come to write its error; the
 * request keeps it however often middleware makes another of it; and
 * the router marks it the moment before it calls the action. It is the
 * one object of a request that changes, because it is what happened to
 * the request, which is not known when the request arrives.
 */
final class Entered
{
    public const string ATTRIBUTE = 'crab.entered';

    private bool $entered = false;

    /**
     * The action is about to be called: from here on, nothing is known
     * of what it did unless it says so itself.
     */
    public function mark(): void
    {
        $this->entered = true;
    }

    public function entered(): bool
    {
        return $this->entered;
    }
}
