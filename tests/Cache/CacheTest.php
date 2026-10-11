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
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public static function stores(): array
    {
        $stores = [['array'], ['file'], ['sqlite']];
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $stores[] = ['mysql'];
        }
        return $stores;
    }

    #[DataProvider('stores')]
    public function testAValueIsKeptAndGivenBackAsItWas(string $kind): void
    {
        $cache = $this->cache($kind);

        self::assertNull($cache->get('user.7'));
        self::assertSame('none', $cache->get('user.7', 'none'));
        self::assertFalse($cache->has('user.7'));

        $value = ['id' => 7, 'tags' => ['a', 'b'], 'at' => new \DateTimeImmutable('2026-01-01'), 'ratio' => 0.5, 'raw' => "\0\xff"];
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
                $store = new FileStore($this->directory);
                for ($i = 0; $i < 250; ++$i) {
                    $store->increment('hits', 1, null, 100);
                }
                // Without the test's shutdown, which belongs to the parent.
                posix_kill(getmypid(), SIGKILL);
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        self::assertSame(1001, (new FileStore($this->directory))->increment('hits', 1, null, 100));
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
        self::assertSame(['a' => 1, 'missing' => 'none', '10' => 'ten'], $cache->getMultiple(['a', 'missing', '10'], 'none'));

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

    public function testTheDatabaseStoreIsInstalledByAMigrationTheApplicationOwns(): void
    {
        $app = new App(new Config([
            'cache' => ['store' => 'database'],
            'database' => ['dsn' => 'sqlite:' . $this->directory . '/app.sqlite', 'migrations' => $this->directory . '/migrations'],
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
        $this->expectExceptionMessage('"file", "database" and "array"');
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
        $database = new Database(fn (): \PDO => $this->pdo($kind), 1);
        $database->execute('drop table if exists cache_test');
        (new Schema($database))->create('cache_test', static function (Table $table): void {
            $table->string('key')->unique();
            $table->text('value');
            $table->bigInteger('expires_at')->nullable()->index();
        });
        return new DatabaseStore($database, 'cache_test');
    }

    private function pdo(string $driver): \PDO
    {
        if ($driver === 'mysql') {
            return new \PDO(getenv('CRAB_TEST_MYSQL_DSN'), getenv('CRAB_TEST_MYSQL_USER') ?: 'root', getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '');
        }
        return new \PDO('sqlite:' . $this->directory . '/app.sqlite');
    }

    private function quiet(): Output
    {
        return new Output(fopen('php://memory', 'w+'), fopen('php://memory', 'w+'));
    }
}
