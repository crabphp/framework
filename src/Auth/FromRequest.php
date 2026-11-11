<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Http\Request;

/**
 * Lets an application's user class read itself from a request, typed as
 * that class with no class to name:
 *
 *     final readonly class User implements Identity
 *     {
 *         use FromRequest;
 *     }
 *
 *     User::fromRequest($request)?->isAdmin()
 *
 * It is for middleware and error renderers; an action takes the user as a
 * parameter instead.
 */
trait FromRequest
{
    /**
     * The user that authentication middleware put on the request, or null
     * when there is none or it is not of this class.
     */
    public static function fromRequest(Request $request): ?static
    {
        $user = $request->user();
        return $user instanceof static ? $user : null;
    }
}
