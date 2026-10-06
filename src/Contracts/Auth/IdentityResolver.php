<?php

declare(strict_types=1);

namespace Crab\Contracts\Auth;

/**
 * Finds a user by id. The application implements it, since only the
 * application knows where its users are kept, and binds it in a provider.
 */
interface IdentityResolver
{
    /**
     * @return Identity|null The user of that id, or null when there is none any more.
     */
    public function resolve(int|string $id): ?Identity;
}
