<?php

declare(strict_types=1);

namespace Crab\Tests\Queue;

use Crab\App;
use Crab\Config\Config;
use Crab\Console\Output;
use Crab\Container\Container;
use Crab\Contracts\Database\Table;
use Crab\Contracts\Queue\Queue as QueueContract;
use Crab\Contracts\Queue\Store;
use Crab\Database\Database;
use Crab\Database\DatabaseProvider;
use Crab\Database\Schema;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Worker;
use Crab\Queue\Consumer;
use Crab\Queue\DatabaseStore;
use Crab\Queue\MemoryStore;
use Crab\Queue\Queue;
use Crab\Queue\QueueProvider;
use Crab\Testing\FrozenClock;
use Crab\Testing\WorkerHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * What the jobs below did, for the tests to read.
 */
final class Ledger
{
    /**
     * @var list<string>
     */
    public static array $lines = [];
    /**
     * How many more times FlakyJob throws.
     */
    public static int $failures = 0;
}

final readonly class Greeter
{
    public function __construct(public string $greeting = 'Hello')
    {
    }
}

final readonly class GreetJob
{
    public function __construct(private Greeter $greeter)
    {
    }

    public function handle(string $name, int $times = 1, array $tags = []): void
    {
        Ledger::$lines[] = str_repeat("{$this->greeter->greeting}, {$name}!", $times) . ($tags === [] ? '' : ' ' . json_encode($tags));
    }
}

final class FlakyJob
{
    public function handle(int $id): void
    {
        if (Ledger::$failures > 0) {
            --Ledger::$failures;
            throw new \RuntimeException("mail server refused {$id}");
        }
        Ledger::$lines[] = "sent {$id}";
    }
}

final class NotAJob
{
}

final class Records extends AbstractLogger
{
    /**
     * @var list<array{string, string}>
     */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [(string) $level, strtr((string) $message, ['{job}' => $context['job'] ?? '', '{message}' => $context['message'] ?? ''])];
    }
}

/**
 * Every store is put through the same tests. The database store runs on
 * SQLite always, and on MySQL too when CRAB_TEST_MYSQL_DSN names a
 * database the tests may fill.
 */
final class QueueTest extends TestCase
{
    private string $directory;
    private FrozenClock $clock;
    private Records $log;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-queue-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->clock = new FrozenClock('2026-01-31 12:00:00');
        $this->log = new Records();
        Ledger::$lines = [];
        Ledger::$failures = 0;
    }

    protected function tearDown(): void
    {
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $this->pdo('mysql')->exec('drop table if exists queue_test, jobs, migrations');
        }
        foreach (glob($this->directory . '/migrations/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->directory . '/migrations');
        @unlink($this->directory . '/app.sqlite');
        @unlink($this->directory . '/app.log');
        rmdir($this->directory);
    }

    public static function stores(): array
    {
        $stores = [['memory'], ['sqlite']];
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $stores[] = ['mysql'];
        }
        return $stores;
    }

    #[DataProvider('stores')]
    public function testAPushedJobIsRunWithItsDataAndItsServices(string $kind): void
    {
        $store = $this->store($kind);
        $queue = new Queue($store, clock: $this->clock);
        $consumer = $this->consumer($store);

        $queue->push(GreetJob::class, name: 'Ada');
        $queue->push(GreetJob::class, name: 'Lin', times: 2, tags: ['vip', ['nested' => 1.5]]);
        self::assertSame(2, $store->size('default'));

        self::assertTrue($consumer->runNext());
        self::assertTrue($consumer->runNext());
        self::assertFalse($consumer->runNext());

        self::assertSame(['Hello, Ada!', 'Hello, Lin!Hello, Lin! ["vip",{"nested":1.5}]'], Ledger::$lines);
        self::assertSame(0, $store->size('default'));
        self::assertSame([], $this->log->records);
    }

    #[DataProvider('stores')]
    public function testADelayedJobWaitsForItsTime(string $kind): void
    {
        $store = $this->store($kind);
        $queue = new Queue($store, clock: $this->clock);
        $consumer = $this->consumer($store);

        $queue->later(600, GreetJob::class, name: 'seconds');
        $queue->later(new \DateInterval('PT1H'), GreetJob::class, name: 'interval');

        self::assertFalse($consumer->runNext());
        $this->clock->advance('+10 minutes');
        self::assertTrue($consumer->runNext());
        self::assertFalse($consumer->runNext());
        $this->clock->advance('+50 minutes');
        self::assertTrue($consumer->runNext());

        self::assertSame(['Hello, seconds!', 'Hello, interval!'], Ledger::$lines);
    }

    #[DataProvider('stores')]
    public function testAFailedJobIsTriedAgainAfterAPauseAndThenSucceeds(string $kind): void
    {
        $store = $this->store($kind);
        $consumer = $this->consumer($store, tries: 3, backoff: [10, 60]);
        (new Queue($store, clock: $this->clock))->push(FlakyJob::class, id: 5);
        Ledger::$failures = 2;

        self::assertTrue($consumer->runNext());
        // Not before its pause is over.
        $this->clock->advance('+9 seconds');
        self::assertFalse($consumer->runNext());
        $this->clock->advance('+1 second');
        self::assertTrue($consumer->runNext());
        $this->clock->advance('+59 seconds');
        self::assertFalse($consumer->runNext());
        $this->clock->advance('+1 second');
        self::assertTrue($consumer->runNext());

        self::assertSame(['sent 5'], Ledger::$lines);
        self::assertSame(0, $store->size('default'));
        self::assertSame([], $store->failed());
        self::assertSame(['warning', 'warning'], array_column($this->log->records, 0));
        self::assertStringContainsString('mail server refused 5', $this->log->records[0][1]);
    }

    #[DataProvider('stores')]
    public function testAJobThatKeepsFailingIsGivenUpOnAndCanBePutBackOrForgotten(string $kind): void
    {
        $store = $this->store($kind);
        $consumer = $this->consumer($store, tries: 2, backoff: []);
        $queue = new Queue($store, clock: $this->clock);
        $queue->push(FlakyJob::class, id: 1);
        $queue->push(FlakyJob::class, id: 2);
        Ledger::$failures = 4;

        while ($consumer->runNext()) {
            // Each job is started twice.
        }

        $failed = $store->failed();
        self::assertCount(2, $failed);
        self::assertSame([FlakyJob::class, ['id' => 1], 2], [$failed[0]->job, $failed[0]->data, $failed[0]->attempts]);
        self::assertSame('RuntimeException: mail server refused 1', $failed[0]->error);
        self::assertSame($this->clock->now()->getTimestamp(), $failed[0]->failedAt);
        self::assertSame(0, $store->size('default'));
        self::assertSame(['warning', 'error', 'warning', 'error'], array_column($this->log->records, 0));
        // A failed job is not taken again by itself.
        $this->clock->advance('+1 day');
        self::assertFalse($consumer->runNext());

        self::assertSame(0, $store->retry(999, $this->clock->now()->getTimestamp()));
        self::assertSame(1, $store->retry($failed[0]->id, $this->clock->now()->getTimestamp()));
        self::assertTrue($consumer->runNext());
        self::assertSame(['sent 1'], Ledger::$lines);

        self::assertSame(1, $store->forget(null));
        self::assertSame([], $store->failed());
    }

    #[DataProvider('stores')]
    public function testAJobIsGivenToOneWorkerAtATimeAndAgainWhenItsWorkerDied(string $kind): void
    {
        $store = $this->store($kind);
        (new Queue($store, clock: $this->clock))->push(GreetJob::class, name: 'once');
        $now = $this->clock->now()->getTimestamp();

        $first = $store->reserve(['default'], $now, 90);
        self::assertSame([GreetJob::class, 1], [$first?->job, $first?->attempts]);
        self::assertNull($store->reserve(['default'], $now + 89, 90));

        // Held longer than a job may take: its worker is taken to be dead.
        $second = $store->reserve(['default'], $now + 90, 90);
        self::assertSame([$first->id, 2], [$second?->id, $second?->attempts]);

        // And when that happens as often as the job may be tried, it is given up on without being run.
        $this->clock->advance('+3 minutes');
        $consumer = $this->consumer($store, tries: 2);
        self::assertTrue($consumer->runNext());
        self::assertSame([], Ledger::$lines);
        self::assertCount(1, $store->failed());
        self::assertStringContainsString('never finished', (string) $store->failed()[0]->error);
    }

    #[DataProvider('stores')]
    public function testQueuesAreTakenFromInTheOrderNamed(string $kind): void
    {
        $store = $this->store($kind);
        $queue = new Queue($store, clock: $this->clock);
        $queue->push(GreetJob::class, name: 'ordinary');
        $queue->on('urgent')->push(GreetJob::class, name: 'urgent');
        $queue->on('other')->push(GreetJob::class, name: 'other');
        $consumer = $this->consumer($store);

        self::assertTrue($consumer->runNext(['urgent', 'default']));
        self::assertTrue($consumer->runNext(['urgent', 'default']));
        self::assertFalse($consumer->runNext(['urgent', 'default']));

        self::assertSame(['Hello, urgent!', 'Hello, ordinary!'], Ledger::$lines);
        self::assertSame([1, 0], [$store->size('other'), $store->size('urgent')]);
    }

    public function testWhatIsPushedIsCheckedAgainstTheJobWhereItIsPushed(): void
    {
        $queue = new Queue(new MemoryStore());
        $mistakes = [
            'no such class' => static fn () => $queue->push('App\\Jobs\\Missing'),
            'no handle() method' => static fn () => $queue->push(NotAJob::class),
            'by position' => static fn () => $queue->push(GreetJob::class, 'Ada'),
            'no parameter for; it takes name, times, tags' => static fn () => $queue->push(GreetJob::class, name: 'Ada', title: 'Dr'),
            'without name' => static fn () => $queue->push(GreetJob::class, times: 2),
            'stored as JSON' => static fn () => $queue->push(GreetJob::class, name: 'Ada', tags: [new \DateTimeImmutable()]),
        ];
        foreach ($mistakes as $says => $mistake) {
            try {
                $mistake();
                self::fail("A push that should say \"{$says}\" was accepted.");
            } catch (\LogicException $error) {
                self::assertStringContainsString($says, $error->getMessage());
            }
        }
    }

    public function testAJobWhoseClassIsGoneFailsLikeAnyOther(): void
    {
        $store = new MemoryStore();
        $store->push('default', 'App\\Jobs\\Removed', [], 0, 0);
        $consumer = $this->consumer($store, tries: 1);

        self::assertTrue($consumer->runNext());

        self::assertStringContainsString('renamed or removed', (string) $store->failed()[0]->error);
    }

    public function testAConsumerWorksUntilCancelledAndOutlastsAStoreThatFails(): void
    {
        $store = new MemoryStore();
        $queue = new Queue($store);
        $queue->push(GreetJob::class, name: 'one');
        $queue->push(GreetJob::class, name: 'two');
        $consumer = new Consumer($store, new Container(), $this->log);

        $ran = WorkerHarness::run([], static function (Worker $worker) use ($consumer, $queue): int {
            $cancellation = new Cancellation();
            $task = $worker->spawn(static fn (): int => $consumer->work($worker->loop, $cancellation, sleep: 0.005));
            Async::sleep($worker->loop, 0.02);
            // Pushed while it waits; it finds it on its next look.
            $queue->push(GreetJob::class, name: 'three');
            Async::sleep($worker->loop, 0.03);
            $cancellation->cancel();
            return $task->await();
        });

        self::assertSame(3, $ran);
        self::assertSame(['Hello, one!', 'Hello, two!', 'Hello, three!'], Ledger::$lines);

        $broken = new class extends \ArrayObject implements Store {
            public int $asked = 0;

            public function push(string $queue, string $job, array $data, int $availableAt, int $now): int|string
            {
                return 1;
            }

            public function reserve(array $queues, int $now, int $retryAfter): ?\Crab\Contracts\Queue\Message
            {
                ++$this->asked;
                throw new \RuntimeException('database has gone away');
            }

            public function complete(\Crab\Contracts\Queue\Message $message): void
            {
            }

            public function release(\Crab\Contracts\Queue\Message $message, int $availableAt): void
            {
            }

            public function fail(\Crab\Contracts\Queue\Message $message, string $error, int $now): void
            {
            }

            public function failed(): array
            {
                return [];
            }

            public function retry(int|string|null $id, int $now): int
            {
                return 0;
            }

            public function forget(int|string|null $id): int
            {
                return 0;
            }

            public function size(string $queue): int
            {
                return 0;
            }
        };
        $consumer = new Consumer($broken, null, $this->log);
        WorkerHarness::run([], static function (Worker $worker) use ($consumer): void {
            $cancellation = new Cancellation();
            $worker->loop->delay(0.03, static fn () => $cancellation->cancel());
            $consumer->work($worker->loop, $cancellation, sleep: 0.005);
        });

        self::assertGreaterThan(1, $broken->asked);
        self::assertSame('error', $this->log->records[0][0]);
    }

    public function testTheProviderBindsTheQueueAndItsCommandsRunTheJobs(): void
    {
        $app = new App(new Config([
            'queue' => ['tries' => 1],
            'log' => ['path' => $this->directory . '/app.log'],
            'database' => ['dsn' => 'sqlite:' . $this->directory . '/app.sqlite', 'migrations' => $this->directory . '/migrations'],
        ]));
        $app->register(DatabaseProvider::class, QueueProvider::class);
        self::assertSame(['database', 'jobs', 'default', 90], [config('queue.store'), config('queue.table'), config('queue.queue'), config('queue.retry_after')]);

        self::assertSame(0, $app->console->run(['queue:install'], $this->quiet()));
        self::assertSame(0, $app->console->run(['queue:install'], $this->quiet()));
        self::assertCount(1, glob($this->directory . '/migrations/*_create_jobs_table.php'));
        self::assertSame(0, $app->console->run(['migrate'], $this->quiet()));

        $queue = $app->container->get(QueueContract::class);
        self::assertInstanceOf(DatabaseStore::class, $app->container->get(Store::class));
        $queue->push(GreetJob::class, name: 'worker');
        $queue->push(FlakyJob::class, id: 9);
        Ledger::$failures = 1;

        [$output, $read] = $this->captured();
        self::assertSame(0, $app->console->run(['queue:work', '--stop-when-empty'], $output));
        self::assertSame("Ran 2 jobs.\n", $read());
        self::assertSame(['Hello, worker!'], Ledger::$lines);
        // What a job throws reaches the application's log, trace and all.
        self::assertStringContainsString('failed for good after 1 tries: mail server refused 9', (string) file_get_contents($this->directory . '/app.log'));

        [$output, $read] = $this->captured();
        self::assertSame(0, $app->console->run(['queue:failed'], $output));
        self::assertStringContainsString(FlakyJob::class, $read());
        self::assertStringContainsString('mail server refused 9', $read());

        self::assertSame(1, $app->console->run(['queue:retry'], $this->quiet()));
        self::assertSame(1, $app->console->run(['queue:retry', '404'], $this->quiet()));
        self::assertSame(0, $app->console->run(['queue:retry', 'all'], $this->quiet()));
        self::assertSame(0, $app->console->run(['queue:work', '--stop-when-empty'], $this->quiet()));
        self::assertSame(['Hello, worker!', 'sent 9'], Ledger::$lines);

        [$output, $read] = $this->captured();
        self::assertSame(0, $app->console->run(['queue:failed'], $output));
        self::assertSame("No job has failed.\n", $read());
        self::assertSame(0, $app->console->run(['queue:forget', 'all'], $this->quiet()));
    }

    public function testAMisconfiguredStoreSaysWhatToDo(): void
    {
        $app = new App(new Config());
        $app->register(QueueProvider::class);
        try {
            $app->container->get(QueueContract::class);
            self::fail('A database store without a database was made.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('DatabaseProvider', $error->getMessage());
        }

        $app = new App(new Config(['queue' => ['store' => 'memory']]));
        $app->register(QueueProvider::class);
        $app->container->get(QueueContract::class)->on('emails')->push(GreetJob::class, name: 'test');
        $store = $app->container->get(Store::class);
        self::assertInstanceOf(MemoryStore::class, $store);
        self::assertSame(['emails', GreetJob::class, ['name' => 'test']], [$store->waiting()[0]->queue, $store->waiting()[0]->job, $store->waiting()[0]->data]);
    }

    /**
     * @param list<int> $backoff
     */
    private function consumer(Store $store, int $tries = 3, array $backoff = [10, 60]): Consumer
    {
        return new Consumer($store, new Container(), $this->log, $this->clock, $tries, $backoff, 90);
    }

    private function store(string $kind): Store
    {
        if ($kind === 'memory') {
            return new MemoryStore();
        }
        $database = new Database(fn (): \PDO => $this->pdo($kind), 1);
        $database->execute('drop table if exists queue_test');
        (new Schema($database))->create('queue_test', static function (Table $table): void {
            $table->id();
            $table->string('queue');
            $table->string('job');
            $table->text('payload');
            $table->integer('attempts')->default(0);
            $table->bigInteger('available_at');
            $table->bigInteger('reserved_at')->nullable();
            $table->bigInteger('failed_at')->nullable();
            $table->text('error')->nullable();
            $table->bigInteger('created_at');
            $table->index(['queue', 'available_at']);
        });
        return new DatabaseStore($database, 'queue_test');
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

    /**
     * @return array{Output, \Closure(): string} An output, and what has been written to it.
     */
    private function captured(): array
    {
        $stream = fopen('php://memory', 'w+');
        return [new Output($stream, $stream), static function () use ($stream): string {
            rewind($stream);
            return (string) stream_get_contents($stream);
        }];
    }
}
