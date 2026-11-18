<?php

declare(strict_types=1);

namespace Crab\Contracts\Routing;

use Crab\Contracts\Http\Request;

/**
 * An action whose signature has been read: it can be invoked for a
 * request, and says what it takes. What was read is fixed for the
 * process; the action's object is made in the worker on first use; the
 * arguments and the result belong to one request.
 */
interface PreparedAction
{
    /**
     * Validates the request against the signature and calls the action.
     * A request that does not fit is not an exception: the outcome is the
     * rejection the router would have answered with, and the action was
     * not called. What the action throws is thrown.
     *
     * @param InputProjection|null $projection Query fields that belong to other actions sharing this request; see InputProjection.
     */
    public function invoke(Request $request, ?InputProjection $projection = null): ActionOutcome;

    /**
     * The action's signature, as read when it was prepared.
     */
    public function signature(): \ReflectionFunctionAbstract;

    /**
     * What the action takes, as JSON Schema; see Router::input().
     *
     * @return array{parameters: array<string, mixed>, query?: array<string, mixed>, body?: array<string, mixed>, user: array{class: string, required: bool}|null}
     */
    public function input(): array;

    /**
     * The classes the container makes for a request to it: the action's
     * own, when it is not a closure. For a registry's report of what must
     * be made; see Definition::entries().
     *
     * @return list<class-string>
     */
    public function dependencies(): array;
}
