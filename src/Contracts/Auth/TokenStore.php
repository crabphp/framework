<?php

declare(strict_types=1);

namespace Crab\Contracts\Auth;

/**
 * Issues the tokens that requests authenticate with, and remembers whom
 * each belongs to. A token is shown once, when it is issued; only a hash
 * of it is kept.
 */
interface TokenStore
{
    /**
     * @param Identity|int|string $for The user, or the user's id.
     * @param string $name What the token is for, to tell a user's tokens apart: "iPhone", "deploy script".
     * @param \DateTimeInterface|null $expiresAt When it stops working; never when left out.
     * @return string The token, to hand to the client. It cannot be read again.
     */
    public function issue(Identity|int|string $for, string $name = '', ?\DateTimeInterface $expiresAt = null): string;

    /**
     * @return int|string|null The id of the user the token belongs to, or null when it is unknown, revoked or expired.
     */
    public function owner(string $token): int|string|null;

    /**
     * Makes one token stop working.
     */
    public function revoke(string $token): void;

    /**
     * Makes every token of a user stop working, as when a password changes.
     */
    public function revokeAll(Identity|int|string $of): void;
}
