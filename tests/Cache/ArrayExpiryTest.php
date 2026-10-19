<?php

declare(strict_types=1);

namespace Crab\Tests\Cache;

use Crab\Cache\ArrayStore;
use Crab\Cache\Cache;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArrayExpiryTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function cacheActivity(): iterable
    {
        foreach (['set', 'get', 'has', 'remember', 'add', 'increment', 'take', 'lock'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('cacheActivity')]
    public function testCacheActivityReclaimsExpiredKeysItNeverReads(string $method): void
    {
        $clock = new FrozenClock('2026-01-01 12:00:00');
        $store = new ArrayStore();
        $cache = new Cache($store, clock: $clock);
        $cache->set('permanent', 'kept');
        $cache->set('live', 'also kept', 1000);
        $cache->set('null', null, 1000);
        for ($wave = 0; $wave < 3; ++$wave) {
            for ($index = 0; $index < 20; ++$index) {
                $cache->set("old.{$wave}.{$index}", str_repeat('x', 1024), 1);
            }
            $clock->advance('+6 seconds');
            $key = "new.{$wave}";
            match ($method) {
                'set' => $cache->set($key, 'new', 60),
                'get' => $cache->get($key),
                'has' => $cache->has($key),
                'remember' => $cache->remember($key, 60, static fn(): string => 'new'),
                'add' => $cache->add($key, 'new', 60),
                'increment' => $cache->increment($key, ttl: 60),
                'take' => $cache->take($key),
                'lock' => $cache->lock($key, 60),
            };
            // Reading old keys here would hide their retention until revisited.
            foreach (array_keys($this->entries($store)) as $retained) {
                self::assertStringStartsNotWith('old.', $retained, 'Expired keys must leave worker memory.');
            }
        }
        self::assertSame('kept', $cache->get('permanent'));
        self::assertSame('also kept', $cache->get('live'));
        self::assertTrue($cache->has('null'));
        self::assertNull($cache->get('null', 'missing'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function storeActivity(): iterable
    {
        foreach (['get', 'take', 'add', 'increment', 'deleteIf'] as $method) {
            yield $method => [$method];
        }
    }

    #[DataProvider('storeActivity')]
    public function testStandaloneStoreActivityReclaimsUnrelatedExpiredKeys(string $method): void
    {
        $store = new ArrayStore();
        $store->set('expired', 'old', 100);
        $store->set('permanent', 'kept', null);
        match ($method) {
            'get' => $store->get('missing', 101),
            'take' => $store->take('missing', 101),
            'add' => $store->add('new', 'value', 200, 101),
            'increment' => $store->increment('new', 1, 200, 101),
            'deleteIf' => $store->deleteIf('missing', 'value', 101),
        };
        self::assertArrayNotHasKey('expired', $this->entries($store));
        self::assertSame('kept', $store->get('permanent', 101));
    }

    public function testWriteOnlyExpiryWavesLeaveOnlyLiveEntries(): void
    {
        $clock = new FrozenClock('2026-01-01');
        $store = new ArrayStore();
        $cache = new Cache($store, clock: $clock);
        for ($wave = 0; $wave < 3; ++$wave) {
            for ($index = 0; $index < 20; ++$index) {
                $cache->set("wave.{$wave}.{$index}", $index, 1);
            }
            $clock->advance('+6 seconds');
        }
        $cache->set('final', 'live', 60);
        self::assertSame(['final' => ['s:4:"live";', $clock->now()->getTimestamp() + 60]], $this->entries($store));
    }

    public function testAutomaticPruningIsPeriodicAndExplicitPruningStillCountsItsRemovals(): void
    {
        $clock = new FrozenClock('2026-01-01');
        $store = new ArrayStore();
        $cache = new Cache($store, clock: $clock);
        $cache->set('old', 'value', 1);
        $clock->advance('+2 seconds');
        $cache->get('missing');
        self::assertArrayHasKey('old', $this->entries($store), 'Ordinary reads do not scan on every call.');
        self::assertSame(1, $store->prune($clock->now()->getTimestamp()));
        self::assertSame(0, $store->prune($clock->now()->getTimestamp()));

        $cache->set('renewed', 'old', 1);
        $clock->advance('+2 seconds');
        $cache->set('renewed', 'new', 100);
        $cache->set('expired', 'gone', 1);
        $clock->advance('+6 seconds');
        $cache->get('another');
        self::assertArrayNotHasKey('expired', $this->entries($store));
        self::assertSame('new', $cache->get('renewed'));
    }

    public function testMovingTheClockBackwardsDoesNotPostponeReclamationUntilItCatchesUp(): void
    {
        $clock = new FrozenClock('2026-01-01 12:00:00');
        $store = new ArrayStore();
        $cache = new Cache($store, clock: $clock);
        $cache->get('missing');
        $clock->advance('-1 hour');
        $cache->set('old', 'value', 1);
        $clock->advance('+6 seconds');
        $cache->get('another');
        self::assertSame([], $this->entries($store));
    }

    public function testClearingTheStoreResetsItsMaintenanceAndLockReleaseAlsoPrunes(): void
    {
        $clock = new FrozenClock('2026-01-01');
        $store = new ArrayStore();
        $cache = new Cache($store, clock: $clock);
        $cache->set('first', 'value');
        $cache->clear();
        $store->set('expired', 'old', $clock->now()->getTimestamp() - 1);
        $cache->get('missing');
        self::assertSame([], $this->entries($store));

        $lock = $cache->lock('work', 60);
        self::assertNotNull($lock);
        $cache->set('old', 'value', 1);
        $clock->advance('+6 seconds');
        self::assertTrue($lock->release());
        self::assertSame([], $this->entries($store));
        self::assertFalse($lock->release());
    }

    /**
     * Memory ownership is inspected without reading keys that would expire lazily.
     *
     * @return array<string, array{string, ?int}>
     */
    private function entries(ArrayStore $store): array
    {
        return new \ReflectionProperty($store, 'entries')->getValue($store);
    }
}
