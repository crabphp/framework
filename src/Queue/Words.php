<?php

declare(strict_types=1);

namespace Crab\Queue;

/**
 * How Signature's messages name a kind of work: "job", "pushed", "push".
 *
 * @internal
 */
final readonly class Words
{
    /**
     * @param string $kind What it is, as in "a job".
     * @param string $verb What was done with its data, as in "was pushed with".
     * @param string $call The method given the data, as in "push(...)".
     * @param string $reserves How the reserved names are taken, as in "a name push() uses for itself".
     */
    public function __construct(
        public string $kind,
        public string $verb,
        public string $call,
        public string $reserves,
    ) {}
}
