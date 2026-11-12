<?php

declare(strict_types=1);

namespace Crab\Session;

use Crab\Contracts\Cache\Store;
use Crab\Contracts\Session\Driver;
use Crab\Contracts\Session\Record;

/**
 * Keeps sessions in a cache store of their own, under a random id that the
 * cookie carries: `FileStore` for the file driver, `ArrayStore` for the
 * memory driver. What a session holds never leaves the server.
 */
final readonly class StoreDriver implements Driver
{
    public function __construct(
        private Store $store,
    ) {}

    public function read(string $cookie, int $now): ?Record
    {
        if (!self::isId($cookie)) {
            return null;
        }
        $json = $this->store->get($cookie, $now);
        return $json === null ? null : Record::fromJson($json, $now);
    }

    public function write(?string $cookie, Record $record): string
    {
        $id = $cookie !== null && self::isId($cookie) ? $cookie : bin2hex(random_bytes(32));
        $this->store->set($id, $record->toJson(), $record->expiresAt);
        return $id;
    }

    public function destroy(string $cookie): void
    {
        if (self::isId($cookie)) {
            $this->store->delete($cookie);
        }
    }

    public function sweep(int $now): int
    {
        return $this->store->prune($now);
    }

    private static function isId(string $cookie): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $cookie) === 1;
    }
}
