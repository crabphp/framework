<?php

declare(strict_types=1);

namespace Crab\Contracts\Auth;

/**
 * Turns a password into something safe to store, and checks a password
 * against what was stored.
 */
interface PasswordHasher
{
    public function hash(#[\SensitiveParameter] string $password): string;

    public function verify(#[\SensitiveParameter] string $password, string $hash): bool;

    /**
     * Whether a stored hash was made with older settings and should be
     * replaced, which can be done when its password is next verified.
     */
    public function needsRehash(string $hash): bool;
}
