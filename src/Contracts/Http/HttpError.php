<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

/**
 * An exception that is answered with an HTTP status of its own, its
 * message shown to the client; below 500 it is an answer, not a failure,
 * and is not logged. HttpException is one. A component that must not
 * depend on HTTP, such as the database, throws its own for a mistake that
 * can only be the client's.
 */
interface HttpError extends \Throwable
{
    /**
     * From 400 to 599.
     */
    public int $status { get; }
}
