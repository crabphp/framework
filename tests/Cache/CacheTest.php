<?php

declare(strict_types=1);

namespace Crab\Tests\Cache;

use Crab\App;
use Crab\Cache\ArrayStore;
use Crab\Cache\Cache;
use Crab\Cache\CacheProvider;
use Crab\Cache\DatabaseStore;
use Crab\Cache\FileStore;
use Crab\Cache\InvalidKey;
use Crab\Config\Config;
use Crab\Console\Output;
use Crab\Contracts\Cache\Cache as CacheContract;
use Crab\Contracts\Cache\Lock;
use Crab\Contracts\Cache\Store;
use Crab\Contracts\Database\Table;
use Crab\Database\Database;
use Crab\Database\DatabaseProvider;
use Crab\Database\Schema;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Every store is put through the same tests. The database store runs on
 * SQLite always, and on MySQL too when CRAB_TEST_MYSQL_DSN names a
 * database the tests may fill.
 */
final class CacheTest extends TestCase
{
    use \Crab\Tests\Database\Mysql\AsyncDriverMatrix;

    private string $directory;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-cache-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->clock = new FrozenClock('2026-01-31 12:00:00');
    }

    protected function tearDown(): void
    {
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $this->pdo('mysql')->exec('drop table if exists cache_test, cache, migrations');
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            !$file->isLink() && $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public static function stores(): array
    {
        $stores = [['array'], ['file'], ['sqlite']];
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $stores[] = ['mysql'];
            $stores[] = ['mysql-async'];
        }
        return $stores;
    }

    #[DataProvider('stores')]
    public function testAValueCanBeTakenOnlyOnceAndExpiredValuesAreNeverReturned(string $kind): void
    {
        $cache = $this->cache($kind);
        foreach ([false, 0, '', null, ['raw' => "\0\xff", 'id' => 7]] as $value) {
            $cache->set('once', $value);
            self::assertSame($value, $cache->take('once', 'missing'));
            self::assertSame('missing', $cache->take('once', 'missing'));
            self::assertFalse($cache->has('once'));
        }
        $cache->set('expired', 'old', 30);
        $cache->set('live', 'new', 31);
        $this->clock->advance('+30 seconds');
        self::assertNull($cache->take('expired'));
        self::assertSame('new', $cache->take('live'));
        self::assertTrue($cache->add('expired', 'replacement', 30));
        self::assertSame('replacement', $cache->take('expired'));
    }

    #[DataProvider('stores')]
    public function testTakingAValueUsesTheCachesPrefix(string $kind): void
    {
        $store = $this->store($kind);
        $first = new Cache($store, prefix: 'first.', clock: $this->clock);
        $second = new Cache($store, prefix: 'second.', clock: $this->clock);
        $first->set('once', 'a');
        $second->set('once', 'b');
        self::assertSame('a', $first->take('once'));
        self::assertSame('b', $second->take('once'));
    }

    public function testTakingAValueRefusesAnInvalidKey(): void
    {
        $this->expectException(InvalidKey::class);
        $this->cache('array')->take('bad:key');
    }

    #[DataProvider('stores')]
    public function testTwoProcessesTakingOneEntryHaveExactlyOneWinner(string $kind): void
    {
        if ($kind === 'array' || $kind === 'mysql-async') {
            self::markTestSkipped('The process race uses shared stores and independent blocking connections.');
        }
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('Needs pcntl to race independent cache consumers.');
        }
        $store = $this->store($kind);
        $store->set('once', 'winner', null);
        $children = [];
        $gates = [];
        for ($child = 0; $child < 2; ++$child) {
            [$read, $write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            if ($pid === 0) {
                pcntl_signal(SIGALRM, SIG_DFL);
                pcntl_alarm(10);
                fclose($write);
                try {
                    if (fread($read, 1) !== 'x') {
                        throw new \RuntimeException('The cache race did not receive its start signal.');
                    }
                    $consumer = $kind === 'file'
                        ? new FileStore($this->directory . '/files')
                        : new DatabaseStore(new Database(fn(): \PDO => $this->pdo($kind), 1), 'cache_test');
                    $result = ['value' => $consumer->take('once', 100)];
                } catch (\Throwable $error) {
                    $result = ['error' => $error->getMessage()];
                }
                file_put_contents(
                    $this->directory . '/take-' . $child . '.json',
                    json_encode($result, JSON_THROW_ON_ERROR),
                );
                posix_kill(getmypid(), SIGKILL);
            }
            self::assertGreaterThan(0, $pid, 'The cache race could not fork its consumer.');
            fclose($read);
            $gates[] = $write;
            $children[] = $pid;
        }
        foreach ($gates as $gate) {
            fwrite($gate, 'x');
            fclose($gate);
        }
        foreach ($children as $pid) {
            $this->waitForChild($pid);
        }
        $values = [];
        for ($child = 0; $child < 2; ++$child) {
            $file = $this->directory . '/take-' . $child . '.json';
            self::assertFileExists($file, 'A cache consumer timed out before recording its result.');
            $result = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('error', $result, $result['error'] ?? '');
            $values[] = $result['value'];
        }
        self::assertSame(1, count(array_filter($values, static fn($value): bool => $value === 'winner')));
        self::assertSame(1, count(array_filter($values, static fn($value): bool => $value === null)));
    }

    #[DataProvider('stores')]
    public function testAValueIsKeptAndGivenBackAsItWas(string $kind): void
    {
        $cache = $this->cache($kind);

        self::assertNull($cache->get('user.7'));
        self::assertSame('none', $cache->get('user.7', 'none'));
        self::assertFalse($cache->has('user.7'));

        $value = [
            'id' => 7,
            'tags' => ['a', 'b'],
            'at' => new \DateTimeImmutable('2026-01-01'),
            'ratio' => 0.5,
            'raw' => "\0\xff",
        ];
        self::assertTrue($cache->set('user.7', $value));

        self::assertEquals($value, $cache->get('user.7'));
        self::assertTrue($cache->has('user.7'));

        $cache->set('user.7', 'replaced');
        self::assertSame('replaced', $cache->get('user.7'));

        self::assertTrue($cache->delete('user.7'));
        self::assertTrue($cache->delete('user.7'));
        self::assertFalse($cache->has('user.7'));
    }

    #[DataProvider('stores')]
    public function testAnEntryIsGoneWhenItsTimeHasCome(string $kind): void
    {
        $cache = $this->cache($kind);
        $cache->set('seconds', 'a', 60);
        $cache->set('interval', 'b', new \DateInterval('PT2M'));
        $cache->set('forever', 'c');

        $this->clock->advance('+59 seconds');
        self::assertSame(['a', 'b', 'c'], [$cache->get('seconds'), $cache->get('interval'), $cache->get('forever')]);

        $this->clock->advance('+1 second');
        self::assertSame([null, 'b', 'c'], [$cache->get('seconds'), $cache->get('interval'), $cache->get('forever')]);
        self::assertFalse($cache->has('seconds'));

        $this->clock->advance('+10 years');
        self::assertSame([null, 'c'], [$cache->get('interval'), $cache->get('forever')]);
    }

    #[DataProvider('stores')]
    public function testATimeToLiveOfNothingRemovesTheEntry(string $kind): void
    {
        $cache = $this->cache($kind);
        $cache->set('key', 'value');

        self::assertTrue($cache->set('key', 'other', 0));
        self::assertFalse($cache->has('key'));

        $cache->set('key', 'value');
        $cache->set('key', 'other', -5);
        self::assertFalse($cache->has('key'));
    }

    #[DataProvider('stores')]
    public function testAddSetsOnlyWhatIsMissing(string $kind): void
    {
        $cache = $this->cache($kind);

        self::assertTrue($cache->add('lock', 'first', 60));
        self::assertFalse($cache->add('lock', 'second', 60));
        self::assertSame('first', $cache->get('lock'));

        // Once the first has expired the key is free again.
        $this->clock->advance('+61 seconds');
        self::assertTrue($cache->add('lock', 'third', 60));
        self::assertSame('third', $cache->get('lock'));

        self::assertFalse($cache->add('gone', 'value', 0));
        self::assertFalse($cache->has('gone'));
    }

    #[DataProvider('stores')]
    public function testALockCanBeTakenByOneHolderAndTakenAgainAfterRelease(string $kind): void
    {
        $cache = $this->cache($kind);

        $lock = $cache->lock('reports.monthly', 60);
        self::assertInstanceOf(Lock::class, $lock);
        self::assertNull($cache->lock('reports.monthly', 60));
        self::assertTrue($lock->release());

        $next = $cache->lock('reports.monthly', 60);
        self::assertInstanceOf(Lock::class, $next);
        self::assertTrue($next->release());
    }

    #[DataProvider('stores')]
    public function testAnExpiredHolderCannotReleaseTheLockTakenInItsPlace(string $kind): void
    {
        $cache = $this->cache($kind);
        $first = $cache->lock('report', 10);
        self::assertInstanceOf(Lock::class, $first);

        $this->clock->advance('+11 seconds');
        $second = $cache->lock('report', 10);
        self::assertInstanceOf(Lock::class, $second);
        self::assertFalse($first->release());
        self::assertNull($cache->lock('report', 10));
        self::assertTrue($second->release());
    }

    #[DataProvider('stores')]
    public function testALockCanBeReleasedOnlyOnce(string $kind): void
    {
        $lock = $this->cache($kind)->lock('report', 60);
        self::assertInstanceOf(Lock::class, $lock);

        self::assertTrue($lock->release());
        self::assertFalse($lock->release());
    }

    #[DataProvider('stores')]
    public function testALockAndAnOrdinaryValueWithTheSameKeyStayApart(string $kind): void
    {
        $cache = $this->cache($kind);
        $lock = $cache->lock('x', 60);
        self::assertInstanceOf(Lock::class, $lock);

        $cache->set('x', 'value');
        self::assertSame('value', $cache->get('x'));
        self::assertTrue($cache->delete('x'));
        self::assertNull($cache->lock('x', 60));
        self::assertTrue($lock->release());

        $cache->set('x', 'value');
        $next = $cache->lock('x', 60);
        self::assertInstanceOf(Lock::class, $next);
        self::assertTrue($next->release());
        self::assertSame('value', $cache->get('x'));
    }

    #[DataProvider('stores')]
    public function testALockRefusesATimeToLiveOfZero(string $kind): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('positive time to live');
        $this->cache($kind)->lock('x', 0);
    }

    #[DataProvider('stores')]
    public function testALockRefusesANegativeTimeToLive(string $kind): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cache($kind)->lock('x', -5);
    }

    #[DataProvider('stores')]
    public function testALockExpiresWithoutBeingReleased(string $kind): void
    {
        $cache = $this->cache($kind);
        $first = $cache->lock('report', 10);
        self::assertInstanceOf(Lock::class, $first);

        $this->clock->advance('+10 seconds');
        self::assertFalse($first->release());
        self::assertInstanceOf(Lock::class, $cache->lock('report', 10));
    }

    #[DataProvider('stores')]
    public function testALockAcceptsAnIntervalAndExpiresAtItsEnd(string $kind): void
    {
        $cache = $this->cache($kind);
        self::assertInstanceOf(Lock::class, $cache->lock('report', new \DateInterval('PT10S')));

        $this->clock->advance('+9 seconds');
        self::assertNull($cache->lock('report', 10));
        $this->clock->advance('+1 second');
        self::assertInstanceOf(Lock::class, $cache->lock('report', 10));
    }

    #[DataProvider('stores')]
    public function testALockRefusesAnIntervalThatDoesNotExpireInTheFuture(string $kind): void
    {
        $cache = $this->cache($kind);
        $backwards = new \DateInterval('PT5S');
        $backwards->invert = 1;
        foreach ([new \DateInterval('PT0S'), $backwards] as $ttl) {
            try {
                $cache->lock('report', $ttl);
                self::fail('A lock accepted an interval that does not expire in the future.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('positive time to live', $error->getMessage());
            }
        }
        self::assertInstanceOf(Lock::class, $cache->lock('report', 10));
    }

    #[DataProvider('stores')]
    public function testTheLastIntegerExpiryFitsForEveryCacheWrite(string $kind): void
    {
        $now = $this->clock->now()->getTimestamp();
        $seconds = PHP_INT_MAX - $now;
        $store = $this->store($kind);
        $cache = new Cache($store, clock: $this->clock);
        $locks = [];

        foreach ([$seconds] as $index => $ttl) {
            self::assertTrue($cache->set('set.' . $index, 'kept', $ttl));
            self::assertTrue($cache->add('add.' . $index, 'added', $ttl));
            self::assertSame(1, $cache->increment('count.' . $index, ttl: $ttl));
            $locks[] = $cache->lock('lock.' . $index, $ttl);
            self::assertInstanceOf(Lock::class, $locks[$index]);
            self::assertSame('kept', $cache->get('set.' . $index));
            self::assertSame('added', $cache->get('add.' . $index));
        }

        $this->clock->set('@' . PHP_INT_MAX);
        foreach ($locks as $index => $lock) {
            self::assertNull($cache->get('set.' . $index));
            self::assertNull($cache->get('add.' . $index));
            self::assertNull($cache->get('count.' . $index));
            self::assertFalse($lock->release());
        }
    }

    #[DataProvider('stores')]
    public function testTheFirstOverflowingExpiryIsRefusedBeforeAnyCacheWrite(string $kind): void
    {
        $cache = $this->cache($kind);
        $seconds = PHP_INT_MAX - $this->clock->now()->getTimestamp() + 1;
        $interval = new \DateInterval('PT0S');
        $interval->s = 100_000_001;
        $cache->set('set', 'kept');
        $cache->set('count', 41);
        $writes = [
            static fn(int|\DateInterval $ttl): bool => $cache->set('set', 'changed', $ttl),
            static fn(int|\DateInterval $ttl): bool => $cache->add('add', 'changed', $ttl),
            static fn(int|\DateInterval $ttl): int => $cache->increment('count', ttl: $ttl),
            static fn(int|\DateInterval $ttl): ?Lock => $cache->lock('lock', $ttl),
        ];

        foreach ([$seconds, $interval] as $ttl) {
            foreach ($writes as $write) {
                try {
                    $write($ttl);
                    self::fail('A cache write accepted an expiry beyond the integer range.');
                } catch (\InvalidArgumentException $error) {
                    self::assertStringContainsString('too long', $error->getMessage());
                    self::assertStringContainsString((string) PHP_INT_MAX, $error->getMessage());
                    self::assertStringContainsString('shorter', $error->getMessage());
                }
            }
        }
        self::assertSame('kept', $cache->get('set'));
        self::assertSame(41, $cache->get('count'));
        self::assertFalse($cache->has('add'));
        self::assertInstanceOf(Lock::class, $cache->lock('lock', 60));
    }

    public function testAnUnderflowingExpiryIsRefusedInsteadOfDeletingAnEntry(): void
    {
        $this->clock->set('@' . PHP_INT_MIN);
        $cache = new Cache(new ArrayStore(), clock: $this->clock);
        $cache->set('kept', 'value');
        foreach ([-1] as $ttl) {
            try {
                $cache->set('kept', 'changed', $ttl);
                self::fail('An underflowing expiry was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString((string) PHP_INT_MIN, $error->getMessage());
            }
        }
        self::assertSame('value', $cache->get('kept'));
    }

    public function testAnIntervalWithAPartPastAHundredMillionIsRefused(): void
    {
        $cache = new Cache(new ArrayStore(), clock: $this->clock);
        $days = new \DateInterval('PT0S');
        $days->d = 2_000_000_000_000_000;
        $mixed = new \DateInterval('PT0S');
        $mixed->d = 2_000_000_000_000_000;
        $mixed->s = -1;
        $back = new \DateInterval('PT0S');
        $back->s = -100_000_001;
        foreach ([$days, $mixed, $back] as $ttl) {
            try {
                $cache->lock('report', $ttl);
                self::fail('An interval PHP cannot add without wrapping was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('too long', $error->getMessage());
            }
        }
        $limit = new \DateInterval('PT0S');
        $limit->y = 100_000_000;
        self::assertInstanceOf(Lock::class, $cache->lock('report', $limit));
    }

    public function testAnIntervalMayBeRelativeOrMixItsSigns(): void
    {
        $cache = new Cache(new ArrayStore(), clock: $this->clock);
        $now = $this->clock->now();
        $weekday = \DateInterval::createFromDateString('next weekday');
        $mixed = new \DateInterval('PT2H');
        $mixed->i = -30;

        self::assertTrue($cache->set('weekday', 'kept', $weekday));
        self::assertTrue($cache->set('mixed', 'kept', $mixed));
        $this->clock->set('@' . ($now->getTimestamp() + 5399));
        self::assertSame('kept', $cache->get('mixed'));
        $this->clock->set('@' . ($now->getTimestamp() + 5400));
        self::assertNull($cache->get('mixed'));
        self::assertSame($now->add($weekday)->getTimestamp() > ($now->getTimestamp() + 5400), $cache->has('weekday'));
    }

    #[DataProvider('stores')]
    public function testALockChecksItsKeyLikeAnOrdinaryCacheEntry(string $kind): void
    {
        $this->expectException(InvalidKey::class);
        $this->cache($kind)->lock('not:a:key', 60);
    }

    #[DataProvider('stores')]
    public function testAnEntryIsDeletedOnlyWhenItIsLiveAndItsValueMatchesExactly(string $kind): void
    {
        $store = $this->store($kind);
        $store->set('owned', "token:\0\n", 110);
        $store->set('expired', 'old', 100);
        $store->set('forever', '', null);

        self::assertFalse($store->deleteIf('missing', 'token', 100));
        self::assertFalse($store->deleteIf('owned', "Token:\0\n", 100));
        self::assertSame("token:\0\n", $store->get('owned', 100));
        self::assertFalse($store->deleteIf('expired', 'old', 100));
        // A store may already have reclaimed an expired entry during maintenance.
        self::assertNull($store->get('expired', 100));
        self::assertTrue($store->deleteIf('owned', "token:\0\n", 100));
        self::assertNull($store->get('owned', 100));
        self::assertFalse($store->deleteIf('owned', "token:\0\n", 100));
        self::assertTrue($store->deleteIf('forever', '', 1_000));
        self::assertNull($store->get('forever', 1_000));
    }

    public function testDatabaseConditionalDeletionComparesBytesEvenWithACaseInsensitiveColumn(): void
    {
        $database = new Database(fn(): \PDO => $this->pdo('sqlite'), 1);
        $database->execute('create table cache_nocase (key text unique, value text collate nocase, expires_at bigint)');
        $store = new DatabaseStore($database, 'cache_nocase');
        $store->set('owned', "\0", 110);

        self::assertFalse($store->deleteIf('owned', 'h', 100));
        self::assertSame("\0", $store->get('owned', 100));
        self::assertTrue($store->deleteIf('owned', "\0", 100));
        self::assertNull($store->get('owned', 100));
    }

    #[DataProvider('stores')]
    public function testPruningRemovesExpiredEntriesAndKeepsLiveAndPermanentOnes(string $kind): void
    {
        $store = $this->store($kind);
        $cache = new Cache($store, clock: $this->clock);
        $cache->setMultiple(['old.one' => 1, 'old.two' => 2, 'old.three' => 3], 10);
        $cache->setMultiple(['live.one' => 4, 'live.two' => 5], 100);
        $cache->set('forever', 6);

        $this->clock->advance('+11 seconds');
        self::assertSame(3, $store->prune($this->clock->now()->getTimestamp()));
        self::assertSame(
            [null, null, null, 4, 5, 6],
            array_values($cache->getMultiple([
                'old.one',
                'old.two',
                'old.three',
                'live.one',
                'live.two',
                'forever',
            ])),
        );
        self::assertSame(0, $store->prune($this->clock->now()->getTimestamp()));
    }

    #[DataProvider('stores')]
    public function testTwoCachesWithDifferentPrefixesDoNotShareALock(string $kind): void
    {
        $store = $this->store($kind);
        $one = new Cache($store, 'one.', clock: $this->clock);
        $two = new Cache($store, 'two.', clock: $this->clock);
        $first = $one->lock('report', 60);
        $second = $two->lock('report', 60);

        self::assertInstanceOf(Lock::class, $first);
        self::assertInstanceOf(Lock::class, $second);
        self::assertTrue($first->release());
        self::assertNull($two->lock('report', 60));
        self::assertTrue($second->release());
    }

    #[DataProvider('stores')]
    public function testClearingRemovesThisCachesValuesAndLeavesItsLocksAndOtherPrefixes(string $kind): void
    {
        $store = $this->store($kind);
        $one = new Cache($store, 'one.', clock: $this->clock);
        $two = new Cache($store, 'two.', clock: $this->clock);
        $bare = new Cache($store, clock: $this->clock);
        $one->set('entry', 'one');
        $two->set('entry', 'two');
        $bare->set('entry', 'bare');
        $held = $two->lock('report', 60);
        self::assertInstanceOf(Lock::class, $held);

        self::assertTrue($two->clear());
        self::assertNull($two->get('entry'));
        self::assertSame('one', $one->get('entry'), "Another prefix's values stay.");
        self::assertSame('bare', $bare->get('entry'));
        self::assertNull($two->lock('report', 60), 'A lock outlives clearing the values.');

        $bare->clear();
        self::assertNull($bare->get('entry'));
        self::assertSame('one', $one->get('entry'), 'Clearing a cache with no prefix leaves the prefixed ones.');
        self::assertNull($two->lock('report', 60));
    }

    #[DataProvider('stores')]
    public function testClearingLocksFreesALockLeftBehindAndOnlyThisCaches(string $kind): void
    {
        $store = $this->store($kind);
        $one = new Cache($store, 'one.', clock: $this->clock);
        $two = new Cache($store, 'two.', clock: $this->clock);
        $one->set('entry', 'one');
        $left = $one->lock('report', 3600);
        $other = $two->lock('report', 3600);
        self::assertInstanceOf(Lock::class, $left);
        self::assertInstanceOf(Lock::class, $other);

        $one->clearLocks();
        self::assertSame('one', $one->get('entry'), 'Clearing locks leaves values.');
        $taken = $one->lock('report', 60);
        self::assertInstanceOf(Lock::class, $taken);
        self::assertFalse($left->release(), 'The old holder can no longer release the new lock.');
        self::assertFalse($left->refresh(60));
        self::assertNull($two->lock('report', 60), "Another prefix's lock stays.");
        self::assertTrue($taken->release());
        self::assertTrue($other->release());
    }

    #[DataProvider('stores')]
    public function testARefreshedLockOutlivesItsFirstTimeAndOnlyItsHolderCanRefreshIt(string $kind): void
    {
        $cache = new Cache($this->store($kind), clock: $this->clock);
        $lock = $cache->lock('report', 60);
        self::assertInstanceOf(Lock::class, $lock);

        $this->clock->advance('+50 seconds');
        self::assertTrue($lock->refresh(60));
        $this->clock->advance('+50 seconds');
        self::assertNull($cache->lock('report', 60), 'Past its first time to live, the refreshed lock is still held.');

        $this->clock->advance('+11 seconds');
        self::assertFalse($lock->refresh(60), 'A lock that ran out is not taken back by refreshing it.');
        $next = $cache->lock('report', 60);
        self::assertInstanceOf(Lock::class, $next);
        self::assertFalse($lock->refresh(60), "Nor is another holder's.");
        self::assertTrue($next->refresh(new \DateInterval('PT2M')));
        $this->clock->advance('+100 seconds');
        self::assertNull($cache->lock('report', 60));
        self::assertTrue($next->release());
        self::assertFalse($next->refresh(60), 'A released lock is not refreshed into being again.');

        $this->expectExceptionMessage('A cache lock needs a positive time to live.');
        $cache->lock('other', 60)?->refresh(0);
    }

    public function testEverySuccessfulFileLockAcquisitionHasItsOwnSuccessfulReleaseAcrossProcesses(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('Needs pcntl to take and release locks from several processes at once.');
        }
        $children = [];
        for ($child = 0; $child < 4; ++$child) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                pcntl_signal(SIGALRM, SIG_DFL);
                pcntl_alarm(30);
                try {
                    $store = new FileStore($this->directory . '/files');
                    $added = 0;
                    $released = 0;
                    $foreign = 0;
                    for ($i = 0; $i < 200; ++$i) {
                        $token = bin2hex(random_bytes(16));
                        $added += (int) $store->add('lock', $token, 1_000, 100);
                        $foreign += (int) $store->deleteIf('lock', 'foreign.' . $token, 100);
                        $released += (int) $store->deleteIf('lock', $token, 100);
                    }
                    $result = [$added, $released, $foreign];
                } catch (\Throwable $error) {
                    $result = ['error' => $error->getMessage() . "\n" . $error->getTraceAsString()];
                }
                file_put_contents(
                    $this->directory . '/child-' . $child . '.json',
                    json_encode($result, JSON_THROW_ON_ERROR),
                );
                // Without the test's shutdown, which belongs to the parent.
                posix_kill(getmypid(), SIGKILL);
            }
            self::assertGreaterThan(0, $pid, 'The lock test could not fork a child process.');
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            $this->waitForChild($pid);
        }
        $total = 0;
        for ($child = 0; $child < 4; ++$child) {
            $file = $this->directory . '/child-' . $child . '.json';
            self::assertFileExists($file);
            $result = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('error', $result, $result['error'] ?? '');
            [$added, $released, $foreign] = $result;
            self::assertSame($added, $released, 'Every acquisition must be released by the same child.');
            self::assertSame(0, $foreign, 'A different token must never release a lock.');
            $total += $added;
        }
        self::assertGreaterThan(0, $total);
        self::assertNull(new FileStore($this->directory . '/files')->get('lock', 100));
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function removalsAfterAnOpenRace(): iterable
    {
        yield 'conditional release' => ['deleteIf', true];
        yield 'take once' => ['take', 'token'];
        yield 'ordinary deletion' => ['delete', null];
        yield 'expired entry' => ['prune', 1];
    }

    #[DataProvider('removalsAfterAnOpenRace')]
    public function testAnEntryThatDisappearsDuringOpenCanReturnWithTheSameInode(
        string $operation,
        mixed $expected,
    ): void {
        if (!function_exists('pcntl_alarm')) {
            self::markTestSkipped('Needs pcntl to bound the isolated file-open race.');
        }
        // Ext4 can reuse an unlinked inode immediately. Hiding and restoring
        // an entry around a real failed open reproduces that observation on
        // every filesystem; the function override stays in this child only.
        $code = <<<'PHP'
            namespace Crab\Cache {
                final class OpenRace {
                    public static bool $pending = true;
                    public static bool $missed = false;
                    public static bool $sameInode = false;
                }

                function fopen(string $file, string $mode): mixed {
                    if ($mode !== 'r+' || !OpenRace::$pending) {
                        return \fopen($file, $mode);
                    }
                    OpenRace::$pending = false;
                    \clearstatcache(true, $file);
                    $inode = \lstat($file)['ino'];
                    $aside = $file . '.parked';
                    if (!\rename($file, $aside)) {
                        throw new \RuntimeException('The file-open fixture could not hide its entry.');
                    }
                    try {
                        // A real missing-path failure, before the same inode is visible again.
                        $handle = @\fopen($file, $mode);
                        OpenRace::$missed = $handle === false;
                    } finally {
                        if (!\rename($aside, $file)) {
                            throw new \RuntimeException('The file-open fixture could not restore its entry.');
                        }
                    }
                    \clearstatcache(true, $file);
                    OpenRace::$sameInode = \lstat($file)['ino'] === $inode;
                    return $handle;
                }
            }
            namespace {
                pcntl_signal(SIGALRM, SIG_DFL);
                pcntl_alarm(10);
                require $argv[1];
                $store = new \Crab\Cache\FileStore($argv[2]);
                $store->set('entry', 'token', 110);
                $result = match ($argv[3]) {
                    'deleteIf' => $store->deleteIf('entry', 'token', 100),
                    'take' => $store->take('entry', 100),
                    'delete' => $store->delete('entry'),
                    'prune' => $store->prune(110),
                };
                echo json_encode([
                    'missed' => \Crab\Cache\OpenRace::$missed,
                    'same_inode' => \Crab\Cache\OpenRace::$sameInode,
                    'result' => $result,
                    'remaining' => $store->get('entry', 100),
                ], JSON_THROW_ON_ERROR);
            }
            PHP;
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                $code,
                dirname(__DIR__, 2) . '/vendor/autoload.php',
                $this->directory . '/files',
                $operation,
            ],
            [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        try {
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            $status = proc_close($process);
        } finally {
            foreach ($pipes as $pipe) {
                if (!is_resource($pipe)) {
                    continue;
                }
                fclose($pipe);
            }
            if (is_resource($process)) {
                proc_terminate($process, SIGKILL);
                proc_close($process);
            }
        }
        self::assertSame(0, $status, 'The isolated file-open race failed or exceeded ten seconds: ' . $error . $output);
        self::assertSame(
            ['missed' => true, 'same_inode' => true, 'result' => $expected, 'remaining' => null],
            json_decode($output, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testPruningTheFileStoreLeavesForeignFilesAlone(): void
    {
        $store = new FileStore($this->directory);
        $store->set('old', 'expired', 100);
        $folder = $this->directory . '/' . substr(sha1('old'), 0, 2);
        file_put_contents($this->directory . '/keep.txt', 'not the cache\'s');
        file_put_contents($folder . '/keep.txt', "10\nnot the cache's");
        mkdir($this->directory . '/foreign');
        file_put_contents($this->directory . '/foreign/' . sha1('foreign'), "10\nnot the cache's");

        self::assertSame(1, $store->prune(100));
        self::assertFileDoesNotExist($folder . '/' . sha1('old'));
        self::assertSame('not the cache\'s', file_get_contents($this->directory . '/keep.txt'));
        self::assertSame("10\nnot the cache's", file_get_contents($folder . '/keep.txt'));
        self::assertFileExists($this->directory . '/foreign/' . sha1('foreign'));
        self::assertSame(0, $store->prune(100));
        self::assertFalse($store->deleteIf('missing', 'value', 100));
        self::assertFileDoesNotExist($this->directory . '/' . substr(sha1('missing'), 0, 2) . '/' . sha1('missing'));
    }

    public function testClearingAndDeletingFileLinksLeaveTheirOutsideTargetsWhole(): void
    {
        $store = new FileStore($this->directory . '/files');
        $target = $this->directory . '/outside.txt';
        file_put_contents($target, 'important outside contents');
        $hash = sha1('linked');
        $file = $this->directory . '/files/' . substr($hash, 0, 2) . '/' . $hash;

        foreach (['clear', 'delete'] as $operation) {
            $store->set('linked', 'old', null);
            unlink($file);
            self::assertTrue(symlink($target, $file));
            if ($operation === 'clear') {
                $store->clear();
            } else {
                $store->delete('linked');
            }
            self::assertFalse(is_link($file));
            self::assertSame('important outside contents', file_get_contents($target));
        }
    }

    public function testClearingAndDeletingBrokenFileLinksRemoveTheLinks(): void
    {
        $store = new FileStore($this->directory . '/files');
        $hash = sha1('linked');
        $file = $this->directory . '/files/' . substr($hash, 0, 2) . '/' . $hash;

        foreach (['clear', 'delete'] as $operation) {
            $store->set('linked', 'old', null);
            unlink($file);
            self::assertTrue(symlink($this->directory . '/missing.txt', $file));
            if ($operation === 'clear') {
                $store->clear();
            } else {
                $store->delete('linked');
            }
            self::assertFalse(is_link($file));
        }
    }

    public function testConditionalDeletionAndPruningSkipFileLinks(): void
    {
        $store = new FileStore($this->directory . '/files');
        $target = $this->directory . '/outside.txt';
        file_put_contents($target, "110\ntoken");
        $store->set('linked', 'old', null);
        $hash = sha1('linked');
        $file = $this->directory . '/files/' . substr($hash, 0, 2) . '/' . $hash;
        unlink($file);
        self::assertTrue(symlink($target, $file));

        self::assertFalse($store->deleteIf('linked', 'token', 100));
        self::assertSame(0, $store->prune(200));
        self::assertTrue(is_link($file));
        self::assertSame("110\ntoken", file_get_contents($target));
    }

    public function testClearingAndPruningSkipLinkedHashFolders(): void
    {
        $cacheDirectory = $this->directory . '/files';
        $outside = $this->directory . '/outside';
        mkdir($cacheDirectory);
        mkdir($outside);
        $hash = sha1('linked');
        $folder = $cacheDirectory . '/' . substr($hash, 0, 2);
        $target = $outside . '/' . $hash;
        file_put_contents($target, "100\nimportant outside contents");
        self::assertTrue(symlink($outside, $folder));
        $store = new FileStore($cacheDirectory);

        try {
            self::assertSame(0, $store->prune(100));
            $store->clear();
            $store->delete('linked');
            self::assertTrue(is_link($folder));
            self::assertSame("100\nimportant outside contents", file_get_contents($target));
        } finally {
            unlink($folder);
        }
    }

    public function testFileWritesRefuseToFollowLinkedEntries(): void
    {
        $store = new FileStore($this->directory . '/files');
        $target = $this->directory . '/outside.txt';
        file_put_contents($target, 'important outside contents');
        $store->set('linked', 'old', null);
        $hash = sha1('linked');
        $file = $this->directory . '/files/' . substr($hash, 0, 2) . '/' . $hash;
        unlink($file);
        self::assertTrue(symlink($target, $file));

        foreach ([
            static function () use ($store): void {
                $store->set('linked', 'changed', null);
            },
            static fn(): bool => $store->add('linked', 'changed', null, 100),
            static fn(): int => $store->increment('linked', 1, null, 100),
        ] as $write) {
            $error = null;
            try {
                $write();
            } catch (\RuntimeException $error) {
            }
            self::assertInstanceOf(\RuntimeException::class, $error);
            self::assertStringContainsString('could not write', $error->getMessage());
            self::assertSame('important outside contents', file_get_contents($target));
        }
    }

    public function testFileReadsRefuseALinkedEntryOrFolder(): void
    {
        $store = new FileStore($this->directory . '/files');
        $planted = $this->directory . '/planted';
        file_put_contents($planted, "0\n" . serialize('planted'));
        $hash = sha1('linked');
        mkdir($this->directory . '/files/' . substr($hash, 0, 2), 0o700, true);
        self::assertTrue(symlink($planted, $this->directory . '/files/' . substr($hash, 0, 2) . '/' . $hash));
        self::assertNull($store->get('linked', 100));

        $other = sha1('in a linked folder');
        mkdir($this->directory . '/elsewhere');
        copy($planted, $this->directory . '/elsewhere/' . $other);
        $folder = $this->directory . '/files/' . substr($other, 0, 2);
        self::assertTrue(symlink($this->directory . '/elsewhere', $folder));
        try {
            self::assertNull($store->get('in a linked folder', 100));
        } finally {
            unlink($folder);
        }
    }

    public function testAFileStoreWhoseDirectoryIsALinkRefusesToReadOrWrite(): void
    {
        mkdir($this->directory . '/real');
        self::assertTrue(symlink($this->directory . '/real', $this->directory . '/link'));
        $store = new FileStore($this->directory . '/link');

        foreach ([
            static fn(): ?string => $store->get('key', 100),
            static function () use ($store): void {
                $store->set('key', 'value', null);
            },
            static function () use ($store): void {
                $store->clear();
            },
            static fn(): int => $store->prune(100),
        ] as $use) {
            $error = null;
            try {
                $use();
            } catch (\RuntimeException $error) {
            }
            self::assertInstanceOf(\RuntimeException::class, $error);
            self::assertStringContainsString('is a link', $error->getMessage());
        }
        self::assertSame([], glob($this->directory . '/real/*'));
    }

    public function testFileEntriesAndTheirFoldersBelongToTheServersUserAlone(): void
    {
        $mask = umask(0o022);
        try {
            $store = new FileStore($this->directory . '/files');
            $store->set('private', 'value', null);
        } finally {
            umask($mask);
        }
        $hash = sha1('private');
        $folder = $this->directory . '/files/' . substr($hash, 0, 2);
        clearstatcache();
        self::assertSame(
            ['0700', '0700', '0600'],
            array_map(static fn(string $path): string => sprintf('%04o', fileperms($path) & 0o777), [
                $this->directory . '/files',
                $folder,
                $folder . '/' . $hash,
            ]),
        );
    }

    public function testAFileStoreRefusesFoldersThatOtherUsersCanWriteTo(): void
    {
        $root = $this->directory . '/shared';
        mkdir($root);
        chmod($root, 0o777);
        $store = new FileStore($root);
        foreach ([
            static fn(): ?string => $store->get('ticket', 100),
            static function () use ($store): void {
                $store->set('ticket', 'i:1;', null);
            },
        ] as $operation) {
            try {
                $operation();
                self::fail('A cache directory anyone can write to was used.');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    "The cache directory {$root} can be written by other users. Run chmod 700 on it.",
                    $error->getMessage(),
                );
            }
        }

        // A folder of entries made writable by others, with an entry planted in it, is refused too.
        chmod($root, 0o700);
        $hash = sha1('ticket');
        $folder = $root . '/' . substr($hash, 0, 2);
        mkdir($folder);
        chmod($folder, 0o777);
        file_put_contents($folder . '/' . $hash, '0 ' . bin2hex('ticket') . "\n" . serialize(1));
        foreach ([
            static fn(): ?string => $store->get('ticket', 100),
            static fn(): ?string => $store->take('ticket', 100),
        ] as $operation) {
            try {
                $operation();
                self::fail('A planted entry was read from a folder anyone can write to.');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    "The cache directory {$folder} can be written by other users. Run chmod 700 on it.",
                    $error->getMessage(),
                );
            }
        }

        chmod($folder, 0o700);
        self::assertSame('i:1;', $store->get('ticket', 100));
    }

    public function testAFileStoreRefusesAFolderAnotherUserOwns(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0 || fileowner('/') === posix_geteuid()) {
            self::markTestSkipped('Needs a user other than root, and a root-owned /.');
        }
        // A folder another account made, 0755, which only that account can fill.
        $store = new FileStore('/');
        foreach ([
            static fn(): ?string => $store->get('ticket', 100),
            static fn(): ?string => $store->take('ticket', 100),
        ] as $operation) {
            try {
                $operation();
                self::fail('A cache directory another user owns was used.');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    "The cache directory / is owned by another user. Set cache.directory to a folder of the server's user.",
                    $error->getMessage(),
                );
            }
        }
    }

    public function testAFailedFileRemovalLeavesTheEntryWholeAndCanBePrunedAfterPermissionsRecover(): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() === 0) {
            self::markTestSkipped('Needs Unix directory permissions and a user other than root.');
        }
        $store = new FileStore($this->directory . '/files');
        $store->set('old', 'expired', 100);
        $hash = sha1('old');
        $file = $this->directory . '/files/' . substr($hash, 0, 2) . '/' . $hash;
        $folder = dirname($file);
        self::assertTrue(chmod($folder, 0o555));

        try {
            foreach ([
                static fn(): int => $store->prune(100),
                static function () use ($store): void {
                    $store->delete('old');
                },
                static fn(): bool => $store->deleteIf('old', 'expired', 50),
            ] as $remove) {
                $error = null;
                try {
                    $remove();
                } catch (\RuntimeException $error) {
                }
                self::assertInstanceOf(\RuntimeException::class, $error);
                self::assertStringContainsString('cannot be written to', $error->getMessage());
                self::assertStringContainsString($folder, $error->getMessage());
                self::assertSame('100 ' . bin2hex('old') . "\nexpired", file_get_contents($file));
            }
        } finally {
            chmod($folder, 0o700);
        }
        self::assertSame(1, $store->prune(100));
        self::assertFileDoesNotExist($file);
        self::assertSame(0, $store->prune(100));
    }

    public function testDescriptorExhaustionFailsFileDeletionWithAnExceptionInsteadOfHanging(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_setrlimit')) {
            self::markTestSkipped('Needs pcntl and POSIX descriptor limits.');
        }
        $store = new FileStore($this->directory . '/files');
        $store->set('owned', 'token', 110);
        $resultFile = $this->directory . '/descriptor-child.json';
        $pid = pcntl_fork();
        if ($pid === 0) {
            pcntl_signal(SIGALRM, SIG_DFL);
            pcntl_alarm(30);
            $handles = [];
            try {
                if (!posix_setrlimit(POSIX_RLIMIT_NOFILE, 64, 64)) {
                    throw new \RuntimeException('The descriptor limit could not be set.');
                }
                while (($handle = @fopen('/dev/null', 'r')) !== false) {
                    $handles[] = $handle;
                }
                $store->delete('owned');
                $result = ['exception' => null, 'message' => 'Deletion returned without an exception.'];
            } catch (\Throwable $error) {
                $result = ['exception' => $error::class, 'message' => $error->getMessage()];
            } finally {
                foreach ($handles as $handle) {
                    fclose($handle);
                }
            }
            file_put_contents($resultFile, json_encode($result, JSON_THROW_ON_ERROR));
            // Without the test's shutdown, which belongs to the parent.
            posix_kill(getmypid(), SIGKILL);
        }
        self::assertGreaterThan(0, $pid, 'The descriptor test could not fork a child process.');
        $this->waitForChild($pid);
        self::assertFileExists($resultFile);
        $result = json_decode(file_get_contents($resultFile), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(\RuntimeException::class, $result['exception']);
        self::assertStringContainsString('could not write', $result['message']);
        self::assertSame('token', $store->get('owned', 100));
    }

    public function testThePruneCommandPrintsHowManyExpiredEntriesItRemoved(): void
    {
        $app = new App(new Config(['cache' => ['directory' => $this->directory . '/files']]));
        $app->container->instance(\Psr\Clock\ClockInterface::class, $this->clock);
        $app->register(CacheProvider::class);
        $cache = $app->container->get(CacheContract::class);
        $cache->setMultiple(['one' => 1, 'two' => 2, 'three' => 3], 10);
        $cache->set('kept', 'value', 100);
        $this->clock->advance('+11 seconds');
        $stream = fopen('php://memory', 'w+');
        $output = new Output($stream, fopen('php://memory', 'w+'));

        self::assertSame(0, $app->console->run(['cache:prune'], $output));
        rewind($stream);
        self::assertSame("Removed 3 expired cache entries.\n", stream_get_contents($stream));
        self::assertSame('value', $cache->get('kept'));
        self::assertSame(0, $app->console->run(['cache:prune'], $output));
        rewind($stream);
        self::assertSame(
            "Removed 3 expired cache entries.\nRemoved 0 expired cache entries.\n",
            stream_get_contents($stream),
        );
    }

    #[DataProvider('stores')]
    public function testIncrementCountsFromZeroAndKeepsTheTimeTheCountStartedWith(string $kind): void
    {
        $cache = $this->cache($kind);

        self::assertSame(1, $cache->increment('hits', ttl: 60));
        self::assertSame(2, $cache->increment('hits', ttl: 600));
        self::assertSame(7, $cache->increment('hits', 5));
        self::assertSame(4, $cache->increment('hits', -3));
        // A count is a value like any other.
        self::assertSame(4, $cache->get('hits'));

        // The later, longer times to live moved nothing: the count ends when it was first said to.
        $this->clock->advance('+60 seconds');
        self::assertFalse($cache->has('hits'));
        self::assertSame(1, $cache->increment('hits'));
        $this->clock->advance('+10 years');
        self::assertSame(2, $cache->increment('hits'));

        // What was set as a number is counted on; anything else is replaced.
        $cache->set('seen', 40, 30);
        self::assertSame(41, $cache->increment('seen', ttl: 600));
        $this->clock->advance('+30 seconds');
        self::assertFalse($cache->has('seen'));
        $cache->set('text', 'not a number');
        self::assertSame(1, $cache->increment('text'));
        self::assertSame(-2, $cache->increment('below', -2));
    }

    public function testIncrementLosesNoCountBetweenProcesses(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('Needs pcntl to count from several processes at once.');
        }
        $children = [];
        for ($child = 0; $child < 4; ++$child) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                pcntl_signal(SIGALRM, SIG_DFL);
                pcntl_alarm(30);
                $store = new FileStore($this->directory);
                for ($i = 0; $i < 250; ++$i) {
                    $store->increment('hits', 1, null, 100);
                }
                // Without the test's shutdown, which belongs to the parent.
                posix_kill(getmypid(), SIGKILL);
            }
            self::assertGreaterThan(0, $pid, 'The counter test could not fork a child process.');
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            $this->waitForChild($pid);
        }

        self::assertSame(1001, new FileStore($this->directory)->increment('hits', 1, null, 100));
    }

    #[DataProvider('stores')]
    public function testRememberMakesAValueOnce(string $kind): void
    {
        $cache = $this->cache($kind);
        $made = 0;
        $make = static function () use (&$made): array {
            ++$made;
            return ['rate' => 1.25];
        };

        self::assertSame(['rate' => 1.25], $cache->remember('rates', 60, $make));
        self::assertSame(['rate' => 1.25], $cache->remember('rates', 60, $make));
        self::assertSame(1, $made);

        $this->clock->advance('+2 minutes');
        $cache->remember('rates', 60, $make);
        self::assertSame(2, $made);
    }

    #[DataProvider('stores')]
    public function testSeveralKeysAtOnceAndClear(string $kind): void
    {
        $cache = $this->cache($kind);

        self::assertTrue($cache->setMultiple(['a' => 1, 'b' => 2, '10' => 'ten'], 60));
        self::assertSame(
            ['a' => 1, 'missing' => 'none', '10' => 'ten'],
            $cache->getMultiple(['a', 'missing', '10'], 'none'),
        );

        self::assertTrue($cache->deleteMultiple(['a', 'missing']));
        self::assertSame([false, true], [$cache->has('a'), $cache->has('b')]);

        self::assertTrue($cache->clear());
        self::assertSame([false, false], [$cache->has('b'), $cache->has('10')]);
        // And it can be written to again.
        $cache->set('b', 3);
        self::assertSame(3, $cache->get('b'));
    }

    #[DataProvider('stores')]
    public function testTwoCachesWithDifferentPrefixesShareAStoreWithoutMeeting(string $kind): void
    {
        $store = $this->store($kind);
        $one = new Cache($store, 'one.', clock: $this->clock);
        $two = new Cache($store, 'two.', clock: $this->clock);

        $one->set('name', 'first');
        $two->set('name', 'second');

        self::assertSame(['first', 'second'], [$one->get('name'), $two->get('name')]);
    }

    public function testTheDefaultTimeToLiveAppliesWhenNoneIsGiven(): void
    {
        $cache = new Cache(new ArrayStore(), ttl: 30, clock: $this->clock);
        $cache->set('default', 'a');
        $cache->set('longer', 'b', 90);

        $this->clock->advance('+31 seconds');

        self::assertSame([null, 'b'], [$cache->get('default'), $cache->get('longer')]);
    }

    public function testAKeyThePsrForbidsIsRefusedAndTheMessageSaysWhatAKeyIs(): void
    {
        $cache = new Cache(new ArrayStore());
        $refused = 0;
        foreach (['', 'user:7', 'a/b', 'a{b}', 'me@host', 'a\\b', '(x)'] as $key) {
            try {
                $cache->get($key);
            } catch (InvalidArgumentException $error) {
                self::assertInstanceOf(InvalidKey::class, $error);
                self::assertStringContainsString('cannot be a cache key', $error->getMessage());
                ++$refused;
            }
        }
        self::assertSame(7, $refused);

        $this->expectException(InvalidArgumentException::class);
        $cache->setMultiple(['fine' => 1, 'not:fine' => 2]);
    }

    public function testTheFileStoreSharesEntriesBetweenObjectsAndClearsOnlyItsOwnFiles(): void
    {
        file_put_contents($this->directory . '/keep.txt', 'not the cache\'s');
        $first = new Cache(new FileStore($this->directory));
        $second = new Cache(new FileStore($this->directory));

        $first->set('shared', 'value');
        self::assertSame('value', $second->get('shared'));

        $second->clear();
        self::assertFalse($first->has('shared'));
        self::assertFileExists($this->directory . '/keep.txt');
    }

    public function testReadingAnExpiredFileEntryNeverTakesAwayTheOneWrittenInItsPlace(): void
    {
        $store = new FileStore($this->directory);
        $other = new FileStore($this->directory);

        $store->set('lock', 'old', 100);
        // One worker finds it expired; another then takes the key; the first reads again with the time it had.
        self::assertNull($store->get('lock', 200));
        self::assertTrue($other->add('lock', 'new', 300, 200));
        self::assertNull($store->get('lock', 300));
        self::assertSame('new', $other->get('lock', 250));
        // Still taken: the read of the expired entry deleted nothing.
        self::assertFalse($store->add('lock', 'third', 300, 250));

        // Deleted and cleared entries can be written again, by whichever object.
        $store->delete('lock');
        self::assertNull($other->get('lock', 250));
        self::assertTrue($other->add('lock', 'fourth', 300, 250));
        $store->clear();
        self::assertTrue($store->add('lock', 'fifth', null, 250));
        $other->set('lock', 'sixth', null);
        self::assertSame('sixth', $store->get('lock', 10_000));
    }

    public function testAFileStoreThatCannotWriteSaysWhere(): void
    {
        file_put_contents($this->directory . '/blocked', '');
        $cache = new Cache(new FileStore($this->directory . '/blocked/cache'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($this->directory . '/blocked');
        $cache->set('key', 'value');
    }

    public function testTheProviderBindsTheConfiguredStoreAndItsCommands(): void
    {
        $app = new App(new Config(['cache' => ['directory' => $this->directory . '/files', 'prefix' => 'app.']]));
        $app->register(CacheProvider::class);
        self::assertSame(['file', 'cache', null], [config('cache.store'), config('cache.table'), config('cache.ttl')]);

        $cache = $app->container->get(CacheContract::class);
        self::assertSame($cache, $app->container->get(CacheInterface::class));
        self::assertInstanceOf(FileStore::class, $app->container->get(Store::class));
        $cache->set('greeting', 'hello');

        $output = $this->quiet();
        self::assertSame(0, $app->console->run(['cache:clear'], $output));
        self::assertFalse($cache->has('greeting'));
    }

    public function testApplicationsWithoutCacheConfigurationUseTheirOwnStorageDirectories(): void
    {
        $firstPath = $this->directory . '/first';
        $secondPath = $this->directory . '/second';
        mkdir($firstPath);
        mkdir($secondPath);
        $first = App::configure($firstPath)->create();
        $second = App::configure($secondPath)->create();
        $one = $first->container->get(CacheContract::class);
        $two = $second->container->get(CacheContract::class);
        $key = 'application.' . bin2hex(random_bytes(6));
        try {
            $one->set($key, 'first');
            self::assertDirectoryExists($firstPath . '/storage/cache');
            self::assertSame($firstPath, $first->config->get('app.path'));
            self::assertNull($two->get($key));
            $two->set($key, 'second');
            self::assertDirectoryExists($secondPath . '/storage/cache');
            $two->clear();
            self::assertSame('first', $one->get($key));
        } finally {
            $one->delete($key);
            $two->delete($key);
        }
    }

    public function testAFileCacheWithoutABasePathOrDirectoryAsksForOneInsteadOfUsingTheTempFolder(): void
    {
        $app = new App();
        $app->register(CacheProvider::class);
        try {
            $app->container->get(CacheContract::class);
            self::fail('A file cache without a folder was made.');
        } catch (\LogicException $error) {
            self::assertSame(
                'A file cache needs a folder: set cache.directory, or build the application with App::configure($basePath).',
                $error->getMessage(),
            );
        }

        $app = new App(new Config(['cache' => ['directory' => $this->directory . '/files']]));
        $app->register(CacheProvider::class);
        $app->container->get(CacheContract::class)->set('key', 'value');
        $hash = sha1('value:key');
        self::assertFileExists($this->directory . '/files/' . substr($hash, 0, 2) . '/' . $hash);
    }

    public function testTheDatabaseStoreIsInstalledByAMigrationTheApplicationOwns(): void
    {
        $app = new App(new Config([
            'cache' => ['store' => 'database'],
            'database' => [
                'connections' => ['main' => [
                    'dsn' => 'sqlite:' . $this->directory . '/app.sqlite',
                    'migrations' => $this->directory . '/migrations',
                ]],
            ],
        ]));
        $app->register(DatabaseProvider::class, CacheProvider::class);

        self::assertSame(0, $app->console->run(['cache:install'], $this->quiet()));
        self::assertCount(1, glob($this->directory . '/migrations/*_create_cache_table.php'));
        // A second time writes nothing.
        self::assertSame(0, $app->console->run(['cache:install'], $this->quiet()));
        self::assertCount(1, glob($this->directory . '/migrations/*.php'));
        self::assertSame(0, $app->console->run(['migrate'], $this->quiet()));

        $cache = $app->container->get(CacheContract::class);
        self::assertInstanceOf(DatabaseStore::class, $app->container->get(Store::class));
        $cache->set('from.the.table', [1, 2, 3], 60);
        self::assertSame([1, 2, 3], $cache->get('from.the.table'));
    }

    public function testAMisconfiguredStoreSaysWhatToDo(): void
    {
        $app = new App(new Config(['cache' => ['store' => 'database']]));
        $app->register(CacheProvider::class);
        try {
            $app->container->get(CacheContract::class);
            self::fail('A database store without a database was made.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('DatabaseProvider', $error->getMessage());
        }

        $app = new App(new Config(['cache' => ['store' => 'redis']]));
        $app->register(CacheProvider::class);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'cache.store is "redis"; it takes "file", "database" or "array". "redis" is one more, with crabphp/redis and its RedisProvider registered after this provider.',
        );
        $app->container->get(CacheContract::class);
    }

    private function cache(string $kind): Cache
    {
        return new Cache($this->store($kind), clock: $this->clock);
    }

    private function store(string $kind): Store
    {
        if ($kind === 'array') {
            return new ArrayStore();
        }
        if ($kind === 'file') {
            return new FileStore($this->directory . '/files');
        }
        $database = new Database(fn(): \PDO => $this->pdo($kind), 1, factory: $this->mysqlFactory($kind));
        $database->execute('drop table if exists cache_test');
        new Schema($database)->create('cache_test', static function (Table $table): void {
            $table->string('key')->unique();
            $table->text('value');
            $table->bigInteger('expires_at')->nullable()->index();
        });
        return new DatabaseStore($database, 'cache_test');
    }

    private function pdo(string $driver): \PDO
    {
        if ($driver === 'mysql') {
            return new \PDO(
                getenv('CRAB_TEST_MYSQL_DSN'),
                getenv('CRAB_TEST_MYSQL_USER') ?: 'root',
                getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '',
            );
        }
        return new \PDO('sqlite:' . $this->directory . '/app.sqlite');
    }

    private function quiet(): Output
    {
        return new Output(fopen('php://memory', 'w+'), fopen('php://memory', 'w+'));
    }

    /**
     * Reaps only this test's child and turns its alarm into a useful failure.
     */
    private function waitForChild(int $pid): void
    {
        self::assertSame($pid, pcntl_waitpid($pid, $status));
        self::assertTrue(pcntl_wifsignaled($status), 'The cache child exited before reporting its result.');
        self::assertSame(
            SIGKILL,
            pcntl_wtermsig($status),
            'The cache child timed out or was interrupted before completing.',
        );
    }
}
