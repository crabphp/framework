<?php

declare(strict_types=1);

namespace Crab\Contracts\Routing;

/**
 * For a request several prepared actions share, such as a page whose
 * layouts and page each load their own data from one query string: the
 * query fields that belong to the others. They are left out of what this
 * action's query is validated from, so that an input marked
 * #[RejectUnknown] refuses only a field none of them declares.
 *
 * It is made by the server when the actions are prepared, never from a
 * request, and it only removes fields: what remains is validated by the
 * action's own types and rules as always, and the request itself is
 * unchanged.
 */
final readonly class InputProjection
{
    /**
     * @param list<string> $foreignQuery The names of query fields other actions of the same request declare and this one does not.
     */
    public function __construct(
        public array $foreignQuery = [],
    ) {}
}
