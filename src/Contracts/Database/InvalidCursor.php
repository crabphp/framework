<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

use Crab\Contracts\Http\HttpError;

/**
 * A cursor that cursorPaginate() did not give for this order: altered,
 * cut short, or from a list sorted another way. A cursor always comes from
 * a client, so it is answered as a mistake in the request, with a 422.
 */
final class InvalidCursor extends \InvalidArgumentException implements HttpError
{
    public readonly int $status;

    public function __construct()
    {
        parent::__construct('The cursor is not one this list gave; start again without it.');
        $this->status = 422;
    }
}
