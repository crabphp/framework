<?php

declare(strict_types=1);

namespace Crab\Cache;

use Crab\Contracts\Cache\Store;
use Crab\Contracts\Database\Connection;

/**
 * Entries kept in a table of the application's database, shared by every
 * worker on every machine that uses it. The `cache:install` command
 * writes the table's migration. An entry is stored as base64 text, and
 * the column is a `text`, so on MySQL one entry holds about 48 kB; keep
 * larger values in a file store.
 */
final readonly class DatabaseStore implements Store
{
    public function __construct(private Connection $connection, private string $table = 'cache')
    {
    }

    public function get(string $key, int $now): ?string
    {
        $row = $this->connection->table($this->table)->select('value', 'expires_at')->where('key', $key)->first();
        if ($row === null) {
            return null;
        }
        if ($row['expires_at'] !== null && (int) $row['expires_at'] <= $now) {
            return null;
        }
        $value = base64_decode((string) $row['value'], true);
        return $value === false ? null : $value;
    }

    public function set(string $key, string $value, ?int $expiresAt): void
    {
        $this->connection->table($this->table)->upsert(
            ['key' => $key, 'value' => base64_encode($value), 'expires_at' => $expiresAt],
            'key',
        );
    }

    public function add(string $key, string $value, ?int $expiresAt, int $now): bool
    {
        // An expired entry is out of the way first; then the key's uniqueness decides who wins.
        $this->connection->table($this->table)->where('key', $key)->where('expires_at', '<=', $now)->delete();
        $inserted = $this->connection->table($this->table)->insertOrIgnore(
            ['key' => $key, 'value' => base64_encode($value), 'expires_at' => $expiresAt],
        );
        return $inserted === 1;
    }

    public function delete(string $key): void
    {
        $this->connection->table($this->table)->where('key', $key)->delete();
    }

    public function clear(): void
    {
        $this->connection->table($this->table)->delete();
    }
}
