<?php

declare(strict_types=1);

namespace Crab\Auth;

use Crab\Contracts\Auth\Identity;
use Crab\Contracts\Auth\TokenStore;
use Crab\Contracts\Database\Connection;
use Psr\Clock\ClockInterface;

/**
 * Tokens kept in the auth_tokens table. A token is 64 random characters
 * and only its SHA-256 hash is stored, so reading the table gives no one
 * a token that works. Looking one up is one query by that hash.
 */
final readonly class DatabaseTokenStore implements TokenStore
{
    /**
     * @param ClockInterface|null $clock Tells whether a token has expired; the real time when left out.
     */
    public function __construct(
        private Connection $connection,
        private string $table = 'auth_tokens',
        private ?ClockInterface $clock = null,
    ) {
    }

    public function issue(Identity|int|string $for, string $name = '', ?\DateTimeInterface $expiresAt = null): string
    {
        $token = bin2hex(random_bytes(32));
        $this->connection->table($this->table)->insert([
            'owner_id' => (string) self::id($for),
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
            'created_at' => $this->now(),
        ]);
        return $token;
    }

    public function owner(string $token): int|string|null
    {
        $row = $this->connection->table($this->table)
            ->select('owner_id', 'expires_at')
            ->where('token_hash', hash('sha256', $token))
            ->first();
        if ($row === null) {
            return null;
        }
        if ($row['expires_at'] !== null && $row['expires_at'] <= $this->now()) {
            return null;
        }
        $owner = (string) $row['owner_id'];
        // Ids are stored as text; one that is a number is given back as a number.
        return ctype_digit($owner) ? (int) $owner : $owner;
    }

    public function revoke(string $token): void
    {
        $this->connection->table($this->table)->where('token_hash', hash('sha256', $token))->delete();
    }

    public function revokeAll(Identity|int|string $of): void
    {
        $this->connection->table($this->table)->where('owner_id', (string) self::id($of))->delete();
    }

    private static function id(Identity|int|string $user): int|string
    {
        return $user instanceof Identity ? $user->id : $user;
    }

    private function now(): string
    {
        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        return $now->format('Y-m-d H:i:s');
    }
}
