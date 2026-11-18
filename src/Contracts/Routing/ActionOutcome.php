<?php

declare(strict_types=1);

namespace Crab\Contracts\Routing;

use Crab\Contracts\Http\Problem;
use Psr\Http\Message\ResponseInterface;

/**
 * How invoking a prepared action ended: it ran and returned a value, or
 * the request did not fit its signature and it was never called. One of
 * the two, never an ambiguous null: an action that returns nothing is a
 * returned outcome whose value is null.
 */
final readonly class ActionOutcome
{
    private function __construct(
        public bool $entered,
        public mixed $value,
        public ?ResponseInterface $rejection,
        public ?Problem $problem,
    ) {}

    /**
     * The action ran and returned this.
     */
    public static function returned(mixed $value): self
    {
        return new self(true, $value, null, null);
    }

    /**
     * The action was not called: the request's path, query or body did
     * not fit, or it takes a user and there is none.
     *
     * @param ResponseInterface $rejection What the router would have answered with: a 422 or a 401.
     * @param Problem $problem The same, before it was written as a response.
     */
    public static function rejected(ResponseInterface $rejection, Problem $problem): self
    {
        return new self(false, null, $rejection, $problem);
    }
}
