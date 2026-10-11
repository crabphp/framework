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

    public function increment(string $key, int $by, ?int $expiresAt, int $now): int
    {
        for ($try = 0; $try < 5; ++$try) {
            // The row is there before it is locked: locking a row that is missing is what makes two callers wait on each other.
            $this->connection->table($this->table)->insertOrIgnore(
                ['key' => $key, 'value' => base64_encode(Counter::write(0)), 'expires_at' => $expiresAt],
            );
            $count = $this->connection->transaction(function (Connection $connection) use ($key, $by, $expiresAt, $now): ?int {
                $row = $connection->table($this->table)->select('value', 'expires_at')->where('key', $key)->lockForUpdate()->first();
                if ($row === null) {
                    // Deleted since it was inserted.
                    return null;
                }
                $live = $row['expires_at'] === null || (int) $row['expires_at'] > $now;
                $entry = $live ? base64_decode((string) $row['value'], true) : false;
                $entry = $entry === false ? null : $entry;
                $count = Counter::read($entry) + $by;
                $changes = ['value' => base64_encode(Counter::write($count))];
                if (!Counter::is($entry)) {
                    $changes['expires_at'] = $expiresAt;
                }
                $connection->table($this->table)->where('key', $key)->update($changes);
                return $count;
            });
            if ($count !== null) {
                return $count;
            }
        }
        throw new \RuntimeException("The cache could not count on \"{$key}\": its entry kept being deleted.");
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
