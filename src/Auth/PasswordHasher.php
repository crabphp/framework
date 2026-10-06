<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\PasswordHasher as PasswordHasherContract;

/**
 * PHP's own password hashing, with the algorithm PHP currently
 * recommends. A hash carries its settings, so old ones keep verifying
 * when the recommendation changes.
 */
final readonly class PasswordHasher implements PasswordHasherContract
{
    /**
     * @param array<string, mixed> $options Options of password_hash(), such as ['cost' => 12].
     */
    public function __construct(private array $options = [])
    {
    }

    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT, $this->options);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT, $this->options);
    }
}
