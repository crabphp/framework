<?php

declare(strict_types=1);

namespace Crab\Tests\Queue;

use Crab\App;
use Crab\Config\Config;
use Crab\Console\Output;
use Crab\Container\Container;
use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Table;
use Crab\Contracts\Queue\JobRan;
use Crab\Contracts\Queue\Queue as QueueContract;
use Crab\Contracts\Queue\Store;
use Crab\Database\Database;
use Crab\Database\DatabaseProvider;
use Crab\Database\Schema;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Worker;
use Crab\Events\Dispatcher;
use Crab\Queue\Consumer;
use Crab\Queue\DatabaseStore;
use Crab\Queue\MemoryStore;
use Crab\Queue\Queue;
use Crab\Queue\QueueProvider;
use Crab\Queue\Retry;
use Crab\Queue\Supervisor;
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
    public function __construct(
        public string $greeting = 'Hello',
    ) {}
}

final readonly class GreetJob
{
    public function __construct(
        private Greeter $greeter,
    ) {}

    public function handle(string $name, int $times = 1, array $tags = []): void
    {
        Ledger::$lines[] =
            str_repeat("{$this->greeter->greeting}, {$name}!", $times) . ($tags === [] ? '' : ' ' . json_encode($tags));
    }
}

final class TypedJob
{
    public function handle(
        int $id,
        float $ratio,
        ?string $note = null,
        string|int $key = '',
        array $tags = [],
        mixed $anything = null,
    ): void {}
}

final class HiddenJob
{
    private function handle(): void {}
}

final class NamedJob
{
    public function handle(string $job = ''): void {}
}

final class DatedJob
{
    public function handle(\DateTimeImmutable $when): void {}
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

/**
 * Gives up on a failing job after one try, whatever the consumer allows.
 */
#[Retry(tries: 1)]
final readonly class SingleTryJob
{
    public function handle(int $id): void
    {
        new FlakyJob()->handle($id);
    }
}

/**
 * Lets a failing job outlast a consumer's smaller retry limit.
 */
#[Retry(tries: 5)]
final readonly class FiveTryJob
{
    public function handle(int $id): void
    {
        new FlakyJob()->handle($id);
    }
}

/**
 * Models a declaration lowered after four reservations under older code.
 */
#[Retry(tries: 2)]
final readonly class LoweredRetryJob
{
    public function handle(int $id): void
    {
        new FlakyJob()->handle($id);
    }
}

/**
 * Changes the retry limit while keeping the consumer's pauses.
 */
#[Retry(tries: 4)]
final readonly class FourTryJob
{
    public function handle(int $id): void
    {
        new FlakyJob()->handle($id);
    }
}

/**
 * Uses short pauses while keeping the consumer's retry limit.
 */
#[Retry(backoff: [1, 2])]
final readonly class ShortBackoffJob
{
    public function handle(int $id): void
    {
        new FlakyJob()->handle($id);
    }
}

/**
 * Repeats one pause while keeping the consumer's retry limit.
 */
#[Retry(backoff: [5])]
final readonly class FiveSecondBackoffJob
{
    public function handle(int $id): void
    {
        new FlakyJob()->handle($id);
    }
}

/**
 * Overrides both supervisor settings, allowing immediate retries.
 */
#[Retry(tries: 3, backoff: [])]
final readonly class ImmediateRetryJob
{
    public function handle(int $id): void
    {
        new FlakyJob()->handle($id);
    }
}

/**
 * A zero-second pause is valid and still keeps the consumer's retry limit.
 */
#[Retry(backoff: [0])]
final readonly class ZeroBackoffJob
{
    public function handle(int $id): void
    {
        new FlakyJob()->handle($id);
    }
}

/**
 * These jobs have callable methods so push refuses their retry settings.
 */
#[Retry(tries: 0)]
final readonly class ZeroTriesJob
{
    public function handle(): void {}
}

/**
 * A retry pause cannot be negative.
 */
#[Retry(backoff: [-1])]
final readonly class NegativeBackoffJob
{
    public function handle(): void {}
}

/**
 * A retry pause cannot be text.
 */
#[Retry(backoff: ['a'])]
final readonly class TextBackoffJob
{
    public function handle(): void {}
}

/**
 * A retry declaration must change at least one setting.
 */
#[Retry]
final readonly class UnsetRetryJob
{
    public function handle(): void {}
}

/**
 * Retry pauses are ordered as a list, without missing indexes.
 */
#[Retry(backoff: [1 => 5])]
final readonly class SparseBackoffJob
{
    public function handle(): void {}
}

/**
 * A retry pause is a whole number, even when a float has no fraction.
 */
#[Retry(backoff: [1.0])]
final readonly class FloatBackoffJob
{
    public function handle(): void {}
}

/**
 * A numeric string is not silently converted to a retry pause.
 */
#[Retry(backoff: ['1'])]
final readonly class NumericTextBackoffJob
{
    public function handle(): void {}
}

/**
 * A boolean is not silently converted to a retry pause.
 */
#[Retry(backoff: [true])]
final readonly class BooleanBackoffJob
{
    public function handle(): void {}
}

/**
 * Bypasses push validation to model a stored job whose retry settings
 * became invalid after a deployment. Its handler must never run.
 */
#[Retry(tries: 0)]
final readonly class InvalidStoredRetryJob
{
    public function handle(): void
    {
        Ledger::$lines[] = 'invalid stored job ran';
    }
}

/**
 * A wrong constructor argument must be refused before this handler runs.
 */
#[Retry(tries: 'bad')]
final readonly class WrongRetryTriesJob
{
    public function handle(): void
    {
        Ledger::$lines[] = 'wrong retry tries job ran';
    }
}

/**
 * A backoff must be an array before its entries can be checked.
 */
#[Retry(backoff: 5)]
final readonly class WrongRetryBackoffJob
{
    public function handle(): void
    {
        Ledger::$lines[] = 'wrong retry backoff job ran';
    }
}

/**
 * Repeating the attribute must name the job and never run its handler.
 */
#[Retry(tries: 1)]
#[Retry(backoff: [5])]
final readonly class RepeatedRetryJob
{
    public function handle(): void
    {
        Ledger::$lines[] = 'repeated retry job ran';
    }
}

final class NotAJob {}

final class Records extends AbstractLogger
{
    /**
     * @var list<array{string, string}>
     */
    public array $records = [];

    /**
     * The settings and failure details passed to each log line.
     *
     * @var list<array<string, mixed>>
     */
    public array $contexts = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            (string) $level,
            strtr((string) $message, ['{job}' => $context['job'] ?? '', '{message}' => $context['message'] ?? '']),
        ];
        $this->contexts[] = $context;
    }
}

/**
 * Every store is put through the same tests. The database store runs on
 * SQLite always, and on MySQL too when CRAB_TEST_MYSQL_DSN names a
 * database the tests may fill.
 */
final class QueueTest extends TestCase
{
    use \Crab\Tests\Database\Mysql\AsyncDriverMatrix;

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
        // The database, and beside it the write-ahead log SQLite keeps while a connection is open.
        foreach (glob($this->directory . '/app.*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public static function stores(): array
    {
        $stores = [['memory'], ['sqlite']];
        if (getenv('CRAB_TEST_MYSQL_DSN') !== false) {
            $stores[] = ['mysql'];
            $stores[] = ['mysql-async'];
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
        $queue->push(GreetJob::class, name: 'now');

        // Where each job stands: one may run, two wait for their time, and one taken by a worker is being run.
        $now = $this->clock->now()->getTimestamp();
        $counts = static function (string $queue, int $at) use ($store): array {
            $counts = $store->counts($queue, $at);
            return [$counts['pending'], $counts['delayed'], $counts['processing'], $counts['oldest']];
        };
        // Pending since it was pushed: how far behind the workers are.
        self::assertSame([1, 2, 0, $now], $counts('default', $now));
        self::assertNotNull($store->reserve(['default'], $now, 90));
        self::assertSame([0, 2, 1, null], $counts('default', $now));
        // Ten minutes on, the first delayed one may run, and has waited since its time came.
        self::assertSame([1, 1, 1, $now + 600], $counts('default', $now + 900));
        self::assertSame(3, $store->size('default'));
        self::assertSame([0, 0, 0, null], $counts('other', $now));
        self::assertSame(['default'], $store->queues());
        // The same, job by job, to be looked at: nothing is taken by looking.
        $listed = static fn(string $state, int $at): array => array_map(
            static fn($message): array => [$message->data['name'], $message->availableAt, $message->reservedAt],
            $store->jobs('default', $state, $at),
        );
        self::assertSame([['now', $now, $now]], $listed('processing', $now));
        self::assertSame([['seconds', $now + 600, null], ['interval', $now + 3600, null]], $listed('delayed', $now));
        self::assertSame([['seconds', $now + 600, null]], $listed('pending', $now + 900));
        self::assertSame([], $listed('pending', $now));
        self::assertCount(1, $store->jobs('default', 'delayed', $now, 1));
        self::assertSame([0, 2, 1, null], $counts('default', $now));
        // Its worker gone, it is given out again after a while and run with the others.
        $this->clock->advance('+2 minutes');
        self::assertTrue($consumer->runNext());
        Ledger::$lines = [];
        $this->clock->advance('-2 minutes');

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
        new Queue($store, clock: $this->clock)->push(FlakyJob::class, id: 5);
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
        // The latest so many, still oldest first, for a list that has grown long.
        self::assertSame([['id' => 2]], array_column($store->failed(1), 'data'));
        self::assertCount(2, $store->failed(5));
        self::assertSame([], $store->failed(0));
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
    public function testAJobWithOneTryIsGivenUpOnAfterItsFirstFailure(string $kind): void
    {
        $store = $this->store($kind);
        $consumer = $this->consumer($store, tries: 3, backoff: []);
        new Queue($store, clock: $this->clock)->push(SingleTryJob::class, id: 1);
        Ledger::$failures = 1;

        self::assertTrue($consumer->runNext());

        self::assertCount(1, $store->failed());
        self::assertSame([SingleTryJob::class, 1], [$store->failed()[0]->job, $store->failed()[0]->attempts]);
        self::assertSame([], $store->jobs('default', 'pending', $this->clock->now()->getTimestamp()));
        self::assertSame(0, $store->size('default'));
        self::assertFalse($consumer->runNext());
        self::assertSame([], Ledger::$lines);
        self::assertSame(['error'], array_column($this->log->records, 0));
        self::assertSame(1, $this->log->contexts[0]['attempt']);
    }

    #[DataProvider('stores')]
    public function testAJobIsTriedAsOftenAsItSaysEvenWhenTheConsumerAllowsFewerTries(string $kind): void
    {
        $store = $this->store($kind);
        $consumer = $this->consumer($store, tries: 2, backoff: []);
        new Queue($store, clock: $this->clock)->push(FiveTryJob::class, id: 5);
        Ledger::$failures = 5;

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            self::assertTrue($consumer->runNext());
            if ($attempt < 5) {
                self::assertSame([], $store->failed());
                $pending = $store->jobs('default', 'pending', $this->clock->now()->getTimestamp());
                self::assertCount(1, $pending);
                self::assertSame($attempt, $pending[0]->attempts);
                self::assertSame(5, $this->log->contexts[$attempt - 1]['tries']);
            }
        }

        self::assertCount(1, $store->failed());
        self::assertSame(5, $store->failed()[0]->attempts);
        self::assertSame(0, $store->size('default'));
        self::assertFalse($consumer->runNext());
        self::assertSame(['warning', 'warning', 'warning', 'warning', 'error'], array_column($this->log->records, 0));
        self::assertSame(5, $this->log->contexts[4]['attempt']);
    }

    #[DataProvider('stores')]
    public function testAJobsBackoffWaitsUntilEachPauseEndsAndRepeatsTheLastPause(string $kind): void
    {
        $store = $this->store($kind);
        $consumer = $this->consumer($store, tries: 4, backoff: [99]);
        new Queue($store, clock: $this->clock)->push(ShortBackoffJob::class, id: 1);
        Ledger::$failures = 3;

        self::assertTrue($consumer->runNext());
        foreach ([1, 2, 2] as $wait) {
            $this->clock->advance('+' . ($wait - 1) . ' seconds');
            self::assertFalse($consumer->runNext());
            $this->clock->advance('+1 second');
            self::assertTrue($consumer->runNext());
        }

        self::assertSame(['sent 1'], Ledger::$lines);
        self::assertSame([], $store->failed());
        self::assertSame(0, $store->size('default'));
        self::assertSame([1, 2, 2], array_column($this->log->contexts, 'wait'));
        self::assertSame([4, 4, 4], array_column($this->log->contexts, 'tries'));
    }

    #[DataProvider('stores')]
    public function testAJobThatOnlySetsTriesKeepsTheConsumersBackoff(string $kind): void
    {
        $store = $this->store($kind);
        $consumer = $this->consumer($store, tries: 2, backoff: [3, 7]);
        new Queue($store, clock: $this->clock)->push(FourTryJob::class, id: 4);
        Ledger::$failures = 4;

        self::assertTrue($consumer->runNext());
        foreach ([3, 7, 7] as $wait) {
            self::assertSame([], $store->failed());
            $this->clock->advance('+' . ($wait - 1) . ' seconds');
            self::assertFalse($consumer->runNext());
            $this->clock->advance('+1 second');
            self::assertTrue($consumer->runNext());
        }

        self::assertCount(1, $store->failed());
        self::assertSame(4, $store->failed()[0]->attempts);
        self::assertSame(0, $store->size('default'));
        self::assertSame([3, 7, 7], array_column($this->log->contexts, 'wait'));
    }

    #[DataProvider('stores')]
    public function testAJobThatOnlySetsBackoffKeepsTheConsumersTries(string $kind): void
    {
        $store = $this->store($kind);
        $consumer = $this->consumer($store, tries: 2, backoff: [99]);
        new Queue($store, clock: $this->clock)->push(FiveSecondBackoffJob::class, id: 2);
        Ledger::$failures = 2;

        self::assertTrue($consumer->runNext());
        self::assertSame([], $store->failed());
        $this->clock->advance('+4 seconds');
        self::assertFalse($consumer->runNext());
        $this->clock->advance('+1 second');
        self::assertTrue($consumer->runNext());

        self::assertCount(1, $store->failed());
        self::assertSame(2, $store->failed()[0]->attempts);
        self::assertFalse($consumer->runNext());
        self::assertSame(5, $this->log->contexts[0]['wait']);
        self::assertSame(2, $this->log->contexts[0]['tries']);
    }

    #[DataProvider('stores')]
    public function testAJobWhoseWorkersKeepDyingIsGivenUpOnByItsOwnTries(string $kind): void
    {
        $store = $this->store($kind);
        $events = new Dispatcher();
        $heard = [];
        $events->listen(JobRan::class, static function (JobRan $event) use (&$heard, $store): void {
            $heard[] = [$event, count($store->failed())];
        });
        $consumer = new Consumer($store, new Container(), $this->log, $this->clock, tries: 6, events: $events);
        $id = new Queue($store, clock: $this->clock)->push(FourTryJob::class, id: 4);

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $message = $store->reserve(['default'], $this->clock->now()->getTimestamp(), 90);
            self::assertNotNull($message);
            self::assertSame([$id, $attempt], [$message->id, $message->attempts]);
            $this->clock->advance('+91 seconds');
        }
        self::assertTrue($consumer->runNext());

        self::assertSame([], Ledger::$lines);
        self::assertCount(1, $store->failed());
        self::assertStringContainsString(
            'retry limit of 4 is exhausted; the job was taken 4 times before this check',
            (string) $store->failed()[0]->error,
        );
        self::assertSame(4, $this->log->contexts[0]['attempt']);
        self::assertCount(1, $heard);
        self::assertSame([$id, 5, true, 1], [
            $heard[0][0]->message->id,
            $heard[0][0]->message->attempts,
            $heard[0][0]->gaveUp,
            $heard[0][1],
        ]);
        self::assertInstanceOf(\RuntimeException::class, $heard[0][0]->error);
        self::assertSame($this->log->contexts[0]['exception'], $heard[0][0]->error);
        self::assertGreaterThanOrEqual(0.0, $heard[0][0]->seconds);
        self::assertSame(0, $store->size('default'));
        self::assertFalse($consumer->runNext());
        self::assertCount(1, $heard);
    }

    #[DataProvider('stores')]
    public function testAnAbandonedJobWithOneTryReportsItsFinalFailureAtTheRecoveryTime(string $kind): void
    {
        $store = $this->store($kind);
        $events = new Dispatcher();
        $heard = [];
        $events->listen(JobRan::class, static function (JobRan $event) use (&$heard, $store): void {
            $heard[] = [$event, count($store->failed())];
        });
        $consumer = new Consumer($store, new Container(), $this->log, $this->clock, events: $events);
        $id = new Queue($store, clock: $this->clock)->push(SingleTryJob::class, id: 1);
        $first = $store->reserve(['default'], $this->clock->now()->getTimestamp(), 90);
        self::assertSame([$id, 1], [$first?->id, $first?->attempts]);

        $this->clock->advance('+89 seconds');
        self::assertFalse($consumer->runNext());
        self::assertSame([], $store->failed());
        self::assertSame([], $heard);
        $this->clock->advance('+1 second');
        self::assertTrue($consumer->runNext());

        self::assertSame([], Ledger::$lines);
        self::assertCount(1, $store->failed());
        self::assertSame([$id, 2], [$store->failed()[0]->id, $store->failed()[0]->attempts]);
        $reason = 'The retry limit of 1 is exhausted; the job was taken once before this check. This can happen without a recorded failure when a worker stopped while running it or it ran longer than 90 seconds (queue.retry_after). It can also happen when its limit was lowered. Review the job before retrying it with queue:retry.';
        self::assertSame(\RuntimeException::class . ': ' . $reason, $store->failed()[0]->error);
        self::assertSame(1, $this->log->contexts[0]['attempt']);
        self::assertCount(1, $heard);
        [$event, $failedWhenTold] = $heard[0];
        self::assertSame($id, $event->message->id);
        self::assertTrue($event->gaveUp);
        self::assertInstanceOf(\RuntimeException::class, $event->error);
        self::assertSame($this->log->contexts[0]['exception'], $event->error);
        self::assertSame(1, $failedWhenTold);
        self::assertSame(\RuntimeException::class . ': ' . $event->error->getMessage(), $store->failed()[0]->error);
        self::assertGreaterThanOrEqual(0.0, $event->seconds);
        self::assertFalse($consumer->runNext());
        $this->clock->advance('+90 seconds');
        self::assertFalse($consumer->runNext());
        self::assertCount(1, $heard);
    }

    #[DataProvider('stores')]
    public function testALoweredRetryLimitKeepsThePriorReservationsWithoutClaimingAWorkerDied(string $kind): void
    {
        $store = $this->store($kind);
        $id = new Queue($store, clock: $this->clock)->push(LoweredRetryJob::class, id: 2);
        $now = $this->clock->now()->getTimestamp();
        // Four reported failures under the old limit of five, before the new worker starts.
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $message = $store->reserve(['default'], $now, 90);
            self::assertNotNull($message);
            self::assertSame([$id, $attempt], [$message->id, $message->attempts]);
            $store->release($message, $now);
        }
        $consumer = $this->consumer($store, tries: 6);

        self::assertTrue($consumer->runNext());

        self::assertSame([], Ledger::$lines);
        self::assertCount(1, $store->failed());
        self::assertSame([$id, 5], [$store->failed()[0]->id, $store->failed()[0]->attempts]);
        $reason = 'The retry limit of 2 is exhausted; the job was taken 4 times before this check. This can happen without a recorded failure when a worker stopped while running it or it ran longer than 90 seconds (queue.retry_after). It can also happen when its limit was lowered. Review the job before retrying it with queue:retry.';
        self::assertSame(\RuntimeException::class . ': ' . $reason, $store->failed()[0]->error);
        self::assertSame(4, $this->log->contexts[0]['attempt']);
        self::assertSame(
            $this->log->contexts[0]['exception']::class . ': ' . $this->log->contexts[0]['message'],
            $store->failed()[0]->error,
        );
        self::assertSame($reason, $this->log->contexts[0]['message']);
        self::assertStringContainsString($reason, $this->log->records[0][1]);
        self::assertSame(0, $store->size('default'));
        self::assertFalse($consumer->runNext());
    }

    #[DataProvider('stores')]
    public function testAnInvalidRetryDeclarationIsRefusedWhereTheJobIsPushed(string $kind): void
    {
        $store = $this->store($kind);
        $queue = new Queue($store, clock: $this->clock);
        $mistakes = [
            ZeroTriesJob::class => 'tries must be at least 1',
            NegativeBackoffJob::class => 'backoff must be a list of whole numbers greater than or equal to 0',
            TextBackoffJob::class => 'backoff must be a list of whole numbers greater than or equal to 0',
            UnsetRetryJob::class => 'needs tries, backoff or both',
            SparseBackoffJob::class => 'backoff must be a list of whole numbers greater than or equal to 0',
            FloatBackoffJob::class => 'backoff must be a list of whole numbers greater than or equal to 0',
            NumericTextBackoffJob::class => 'backoff must be a list of whole numbers greater than or equal to 0',
            BooleanBackoffJob::class => 'backoff must be a list of whole numbers greater than or equal to 0',
        ];

        foreach ($mistakes as $job => $says) {
            try {
                $queue->push($job);
                self::fail("The invalid retry declaration of {$job} was accepted.");
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($job, $error->getMessage());
                self::assertStringContainsString($says, $error->getMessage());
                self::assertInstanceOf(\InvalidArgumentException::class, $error->getPrevious());
            }
        }

        self::assertSame(0, $store->size('default'));
        self::assertSame([], $store->failed());
    }

    #[DataProvider('stores')]
    public function testMalformedRetryArgumentsAndRepeatedDeclarationsAreRefusedWhereTheJobIsPushed(string $kind): void
    {
        $store = $this->store($kind);
        $queue = new Queue($store, clock: $this->clock);
        $mistakes = [
            WrongRetryTriesJob::class => \TypeError::class,
            WrongRetryBackoffJob::class => \TypeError::class,
            RepeatedRetryJob::class => \Error::class,
        ];

        foreach ($mistakes as $job => $cause) {
            try {
                $queue->push($job);
                self::fail("The malformed retry declaration of {$job} was accepted.");
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($job, $error->getMessage());
                self::assertStringContainsString('Use one #[Retry] declaration', $error->getMessage());
                self::assertStringContainsString('integer of at least 1', $error->getMessage());
                self::assertStringContainsString(
                    'list of whole numbers greater than or equal to 0',
                    $error->getMessage(),
                );
                self::assertInstanceOf($cause, $error->getPrevious());
            }
        }

        self::assertSame(0, $store->size('default'));
        self::assertSame([], $store->failed());
    }

    #[DataProvider('stores')]
    public function testAnInvalidRetryDeclarationAlreadyInTheStoreFailsTheJobForGood(string $kind): void
    {
        $store = $this->store($kind);
        $events = new Dispatcher();
        $heard = [];
        $events->listen(JobRan::class, static function (JobRan $event) use (&$heard, $store): void {
            $heard[] = [$event, count($store->failed())];
        });
        $consumer = new Consumer($store, new Container(), $this->log, $this->clock, tries: 3, events: $events);
        $mistakes = [
            InvalidStoredRetryJob::class => \InvalidArgumentException::class,
            WrongRetryTriesJob::class => \TypeError::class,
            WrongRetryBackoffJob::class => \TypeError::class,
            RepeatedRetryJob::class => \Error::class,
        ];
        $failedCount = 0;
        foreach ($mistakes as $job => $cause) {
            $now = $this->clock->now()->getTimestamp();
            $id = $store->push('default', $job, [], $now, $now);
            self::assertTrue($consumer->runNext());

            ++$failedCount;
            self::assertCount($failedCount, $store->failed());
            $failed = $store->failed(1)[0];
            self::assertSame([$id, $job, 1, $now], [$failed->id, $failed->job, $failed->attempts, $failed->failedAt]);
            self::assertStringContainsString($job, (string) $failed->error);
            self::assertStringContainsString('Use one #[Retry] declaration', (string) $failed->error);
            self::assertSame([], Ledger::$lines);
            self::assertSame([], $store->jobs('default', 'pending', $now));
            self::assertSame([], $store->jobs('default', 'processing', $now));
            self::assertSame(0, $store->size('default'));
            self::assertFalse($consumer->runNext());
            $index = $failedCount - 1;
            self::assertSame('error', $this->log->records[$index][0]);
            self::assertStringContainsString('failed for good', $this->log->records[$index][1]);
            // Invalid metadata must not cap or lose the actual reservation count.
            self::assertSame(1, $this->log->contexts[$index]['attempt']);
            self::assertSame($job, $this->log->contexts[$index]['job']);
            self::assertCount($failedCount, $heard);
            [$event, $failedWhenTold] = $heard[$index];
            self::assertSame($id, $event->message->id);
            self::assertTrue($event->gaveUp);
            self::assertInstanceOf(\InvalidArgumentException::class, $event->error);
            self::assertInstanceOf($cause, $event->error->getPrevious());
            self::assertSame($this->log->contexts[$index]['exception'], $event->error);
            self::assertGreaterThanOrEqual(0.0, $event->seconds);
            self::assertSame($failedCount, $failedWhenTold);

            $this->clock->advance('+91 seconds');
            self::assertFalse($consumer->runNext());
            self::assertCount($failedCount, $store->failed());
            self::assertCount($failedCount, $heard);
        }
        // The same consumer can take the next valid job after refusing this one.
        new Queue($store, clock: $this->clock)->push(GreetJob::class, name: 'next');
        self::assertTrue($consumer->runNext());
        self::assertSame(['Hello, next!'], Ledger::$lines);
        self::assertCount($failedCount + 1, $heard);
        self::assertFalse($heard[$failedCount][0]->gaveUp);
        self::assertFalse($consumer->runNext());
    }

    #[DataProvider('stores')]
    public function testAJobsLastFailedTryIsTheOneThatTellsListenersItGaveUp(string $kind): void
    {
        $store = $this->store($kind);
        $queue = new Queue($store, clock: $this->clock);
        $events = new Dispatcher();
        $heard = [];
        $events->listen(JobRan::class, static function (JobRan $event) use (&$heard, $store): void {
            $heard[] = [$event->message->attempts, $event->gaveUp, count($store->failed())];
        });
        $consumer = new Consumer(
            $store,
            new Container(),
            $this->log,
            $this->clock,
            tries: 2,
            backoff: [],
            events: $events,
        );
        $queue->push(FiveTryJob::class, id: 5);
        Ledger::$failures = 5;

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            self::assertTrue($consumer->runNext());
        }
        // A job that succeeds on its only try has not been given up on.
        $queue->push(SingleTryJob::class, id: 1);
        self::assertTrue($consumer->runNext());

        self::assertSame(
            [
                [1, false, 0],
                [2, false, 0],
                [3, false, 0],
                [4, false, 0],
                [5, true, 1],
                [1, false, 1],
            ],
            $heard,
        );
        self::assertSame(['sent 1'], Ledger::$lines);
        self::assertFalse($consumer->runNext());
    }

    #[DataProvider('stores')]
    public function testAJobsTriesOverrideItsSupervisorsTries(string $kind): void
    {
        $store = $this->store($kind);
        $supervisor = new Supervisor('mail', ['mail'], tries: 1, backoff: [2]);
        $consumer = $this->consumer($store, tries: 2, backoff: [99])->within($supervisor);
        new Queue($store, clock: $this->clock)->on('mail')->push(FiveTryJob::class, id: 5);
        Ledger::$failures = 5;

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            self::assertTrue($consumer->runNext($supervisor->queues));
            if ($attempt < 5) {
                self::assertSame([], $store->failed());
                $this->clock->advance('+1 second');
                self::assertFalse($consumer->runNext($supervisor->queues));
                $this->clock->advance('+1 second');
            }
        }

        self::assertCount(1, $store->failed());
        self::assertSame(5, $store->failed()[0]->attempts);
        self::assertSame([5, 5, 5, 5], array_column($this->log->contexts, 'tries'));
        self::assertSame([2, 2, 2, 2], array_column($this->log->contexts, 'wait'));
        self::assertFalse($consumer->runNext($supervisor->queues));
    }

    #[DataProvider('stores')]
    public function testAJobCanOverrideBothSupervisorSettingsWithAnEmptyBackoff(string $kind): void
    {
        $store = $this->store($kind);
        $supervisor = new Supervisor('mail', ['mail'], tries: 5, backoff: [99]);
        $consumer = $this->consumer($store)->within($supervisor);
        new Queue($store, clock: $this->clock)->on('mail')->push(ImmediateRetryJob::class, id: 3);
        Ledger::$failures = 3;

        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            self::assertTrue($consumer->runNext($supervisor->queues));
            self::assertCount($attempt === 3 ? 1 : 0, $store->failed());
        }

        self::assertSame(3, $store->failed()[0]->attempts);
        self::assertSame([0, 0], array_column($this->log->contexts, 'wait'));
        self::assertSame([3, 3], array_column($this->log->contexts, 'tries'));
        self::assertFalse($consumer->runNext($supervisor->queues));
    }

    #[DataProvider('stores')]
    public function testAJobCanAskForAZeroSecondBackoff(string $kind): void
    {
        $store = $this->store($kind);
        $consumer = $this->consumer($store, tries: 2, backoff: [99]);
        new Queue($store, clock: $this->clock)->push(ZeroBackoffJob::class, id: 1);
        Ledger::$failures = 1;

        self::assertTrue($consumer->runNext());
        self::assertTrue($consumer->runNext());

        self::assertSame(['sent 1'], Ledger::$lines);
        self::assertSame([], $store->failed());
        self::assertSame(0, $this->log->contexts[0]['wait']);
        self::assertFalse($consumer->runNext());
    }

    #[DataProvider('stores')]
    public function testAJobWhoseClassIsGoneKeepsTheConsumersRetrySettings(string $kind): void
    {
        $store = $this->store($kind);
        $now = $this->clock->now()->getTimestamp();
        $store->push('default', 'App\\Actions\\RemovedRetryJob', [], $now, $now);
        $consumer = $this->consumer($store, tries: 2, backoff: [3]);

        self::assertTrue($consumer->runNext());
        self::assertSame([], $store->failed());
        $this->clock->advance('+2 seconds');
        self::assertFalse($consumer->runNext());
        $this->clock->advance('+1 second');
        self::assertTrue($consumer->runNext());

        self::assertCount(1, $store->failed());
        self::assertSame(2, $store->failed()[0]->attempts);
        self::assertStringContainsString('renamed or removed', (string) $store->failed()[0]->error);
        self::assertSame(2, $this->log->contexts[0]['tries']);
        self::assertSame(3, $this->log->contexts[0]['wait']);
        self::assertFalse($consumer->runNext());
    }

    #[DataProvider('stores')]
    public function testAClassLoadedAfterAMissingJobUsesItsOwnRetryDeclaration(string $kind): void
    {
        $store = $this->store($kind);
        $job = __NAMESPACE__ . '\\LaterLoadedRetryJob' . bin2hex(random_bytes(6));
        $now = $this->clock->now()->getTimestamp();
        $store->push('missing', $job, ['id' => 1], $now, $now);
        $consumer = $this->consumer($store, tries: 3, backoff: []);

        self::assertTrue($consumer->runNext(['missing']));
        self::assertSame([], $store->failed());
        self::assertSame(3, $this->log->contexts[0]['tries']);
        // Registering this existing fixture makes the previously missing name loadable.
        self::assertTrue(class_alias(SingleTryJob::class, $job));
        $id = new Queue($store, clock: $this->clock)->on('fresh')->push($job, id: 1);
        Ledger::$failures = 1;
        self::assertTrue($consumer->runNext(['fresh']));

        self::assertSame(1, Retry::for($job)?->tries);
        self::assertCount(1, $store->failed());
        self::assertSame([$id, $job, 1], [
            $store->failed()[0]->id,
            $store->failed()[0]->job,
            $store->failed()[0]->attempts,
        ]);
        self::assertSame(0, $store->size('fresh'));
        self::assertSame([], Ledger::$lines);
        self::assertFalse($consumer->runNext(['fresh']));
    }

    #[DataProvider('stores')]
    public function testAnInvalidClassLoadedAfterAMissingJobIsStillRefusedOnPush(string $kind): void
    {
        $store = $this->store($kind);
        $job = __NAMESPACE__ . '\\LaterLoadedInvalidRetryJob' . bin2hex(random_bytes(6));
        $now = $this->clock->now()->getTimestamp();
        $store->push('missing', $job, [], $now, $now);
        self::assertTrue($this->consumer($store)->runNext(['missing']));
        self::assertSame([], $store->failed());
        self::assertTrue(class_alias(ZeroTriesJob::class, $job));

        try {
            new Queue($store, clock: $this->clock)->on('fresh')->push($job);
            self::fail('The retry declaration of the newly loaded class was not checked.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString($job, $error->getMessage());
            self::assertStringContainsString('tries must be at least 1', $error->getMessage());
            self::assertInstanceOf(\InvalidArgumentException::class, $error->getPrevious());
        }

        self::assertSame(0, $store->size('fresh'));
        self::assertSame([], Ledger::$lines);
    }

    #[DataProvider('stores')]
    public function testACachedRetryDeclarationKeepsEachConsumersDefaultTries(string $kind): void
    {
        $store = $this->store($kind);
        $queue = new Queue($store, clock: $this->clock);
        $retry = Retry::for(ShortBackoffJob::class);
        self::assertNotNull($retry);
        self::assertSame([null, [1, 2]], [$retry->tries, $retry->backoff]);
        $queue->on('one')->push(ShortBackoffJob::class, id: 1);
        $queue->on('three')->push(ShortBackoffJob::class, id: 3);
        Ledger::$failures = 4;
        $one = $this->consumer($store, tries: 1);
        $three = $this->consumer($store, tries: 3);

        self::assertTrue($one->runNext(['one']));
        self::assertCount(1, $store->failed());
        self::assertTrue($three->runNext(['three']));
        $this->clock->advance('+1 second');
        self::assertTrue($three->runNext(['three']));
        self::assertCount(1, $store->failed());
        $this->clock->advance('+2 seconds');
        self::assertTrue($three->runNext(['three']));

        self::assertSame([1, 3], array_column($store->failed(), 'attempts'));
        self::assertSame($retry, Retry::for(ShortBackoffJob::class));
        self::assertNull(Retry::for(FlakyJob::class));
        self::assertNull(Retry::for('App\\Actions\\RemovedRetryJob'));
        self::assertSame(0, $store->size('one'));
        self::assertSame(0, $store->size('three'));
    }

    #[DataProvider('stores')]
    public function testAJobIsGivenToOneWorkerAtATimeAndAgainWhenItsWorkerDied(string $kind): void
    {
        $store = $this->store($kind);
        new Queue($store, clock: $this->clock)->push(GreetJob::class, name: 'once');
        $now = $this->clock->now()->getTimestamp();

        $first = $store->reserve(['default'], $now, 90);
        self::assertSame([GreetJob::class, 1], [$first?->job, $first?->attempts]);
        self::assertNull($store->reserve(['default'], $now + 89, 90));

        // Held longer than a job may take: its worker is taken to be dead.
        $second = $store->reserve(['default'], $now + 90, 90);
        self::assertSame([$first->id, 2], [$second?->id, $second?->attempts]);

        // The first worker was only slow. What it says of its try changes nothing: the job is the second's now.
        $store->release($first, $now + 91);
        $store->fail($first, 'too slow', $now + 91);
        self::assertSame([0, 0, 1], [
            $store->counts('default', $now + 91)['pending'],
            $store->counts('default', $now + 91)['delayed'],
            $store->counts('default', $now + 91)['processing'],
        ]);
        self::assertNull($store->reserve(['default'], $now + 100, 90));
        self::assertSame([], $store->failed());

        // And when that happens as often as the job may be tried, it is given up on without being run.
        $this->clock->advance('+3 minutes');
        $consumer = $this->consumer($store, tries: 2);
        self::assertTrue($consumer->runNext());
        self::assertSame([], Ledger::$lines);
        self::assertCount(1, $store->failed());
        self::assertStringContainsString(
            'retry limit of 2 is exhausted; the job was taken 2 times before this check',
            (string) $store->failed()[0]->error,
        );
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

    #[DataProvider('databases')]
    public function testAJobPushedWithinATransactionIsStoredWithItOrNotAtAll(string $kind): void
    {
        $this->store($kind);
        $db = new Database(fn(): \PDO => $this->pdo($kind), 1, factory: $this->mysqlFactory($kind));
        $store = new DatabaseStore($db, 'queue_test');
        $queue = new Queue($store, clock: $this->clock);
        $now = $this->clock->now()->getTimestamp();

        $id = $db->transaction(static function (Connection $tx) use ($queue, $store, $now): int|string {
            $id = $queue->within($tx)->on('mail')->push(GreetJob::class, name: 'user 7');
            // Stored by the transaction's own connection, on a pool that has no other to lend.
            self::assertSame(1, $tx->table('queue_test')->count());
            return $id;
        });
        self::assertNotSame('', $id);
        self::assertSame(1, $store->size('mail', $now));

        try {
            $db->transaction(static function (Connection $tx) use ($queue): void {
                $queue->within($tx)->push(GreetJob::class, name: 'user 8');
                $tx->transaction(static fn(Connection $inner) => $queue->within($inner)->push(
                    GreetJob::class,
                    name: 'user 9',
                ));
                throw new \DomainException('the order was refused');
            });
            self::fail('The failure was swallowed.');
        } catch (\DomainException) {
        }
        self::assertSame(
            [1, 0],
            [$store->size('mail', $now), $store->size('default', $now)],
            'A job of a write that was undone must not exist.',
        );
    }

    #[DataProvider('databases')]
    public function testAJobKeptElsewhereIsPushedOnceTheTransactionHasCommitted(string $kind): void
    {
        $this->store($kind);
        $db = new Database(fn(): \PDO => $this->pdo($kind), 1, factory: $this->mysqlFactory($kind));
        $now = $this->clock->now()->getTimestamp();
        // In memory, and in a database that is not the transaction's own.
        foreach ([
            new MemoryStore(),
            new DatabaseStore(
                new Database(fn(): \PDO => $this->pdo($kind), 1, factory: $this->mysqlFactory($kind)),
                'queue_test',
            ),
        ] as $store) {
            $queue = new Queue($store, clock: $this->clock);
            $before = $store->size('default', $now);

            $db->transaction(static function (Connection $tx) use ($queue, $store, $now, $before, $kind): void {
                self::assertSame('', $queue->within($tx)->push(GreetJob::class, name: 'user 7'));
                try {
                    $tx->transaction(static function (Connection $inner) use ($queue): void {
                        $queue->within($inner)->push(GreetJob::class, name: 'user 8');
                        throw new \DomainException('only this part is undone');
                    });
                } catch (\DomainException) {
                }
                if ($store instanceof MemoryStore || $kind === 'mysql') {
                    self::assertSame($before, $store->size('default', $now), 'Nothing is pushed before the commit.');
                }
            });
            self::assertSame(
                $before + 1,
                $store->size('default', $now),
                'The job of the part that was undone is not pushed.',
            );

            try {
                $db->transaction(static function (Connection $tx) use ($queue): void {
                    $queue->within($tx)->push(GreetJob::class, name: 'user 9');
                    throw new \DomainException('refused');
                });
            } catch (\DomainException) {
            }
            self::assertSame($before + 1, $store->size('default', $now));

            // Outside a transaction there is nothing to wait for.
            $queue->within($db)->push(GreetJob::class, name: 'user 10');
            self::assertSame($before + 2, $store->size('default', $now));
        }
    }

    public static function databases(): array
    {
        return array_values(array_filter(self::stores(), static fn(array $store): bool => $store[0] !== 'memory'));
    }

    public function testWhatIsPushedIsCheckedAgainstTheJobWhereItIsPushed(): void
    {
        $queue = new Queue(new MemoryStore());
        $mistakes = [
            'no such class' => static fn() => $queue->push('App\\Jobs\\Missing'),
            'no handle() method' => static fn() => $queue->push(NotAJob::class),
            'by position' => static fn() => $queue->push(GreetJob::class, 'Ada'),
            'no parameter for; it takes name, times, tags' => static fn() => $queue->push(
                GreetJob::class,
                name: 'Ada',
                title: 'Dr',
            ),
            'without name' => static fn() => $queue->push(GreetJob::class, times: 2),
            'stored as JSON' => static fn() => $queue->push(
                GreetJob::class,
                name: 'Ada',
                tags: [new \DateTimeImmutable()],
            ),
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

    public function testAValueIsCheckedAgainstTheTypeItsParameterTakes(): void
    {
        $store = new MemoryStore();
        $queue = new Queue($store, 'default', $this->clock);

        // What handle() would take: a whole number for a float, null where it may be, any of a union.
        $queue->push(TypedJob::class, id: 7, ratio: 2, note: null, key: 'a', tags: ['x']);
        $queue->push(TypedJob::class, id: 7, ratio: 0.5, note: 'hi', key: 3, anything: ['free' => true]);
        self::assertSame(2, $store->size('default'));

        $mistakes = [
            'array as "id", which its handle() takes as int' => static fn() => $queue->push(
                TypedJob::class,
                id: ['wrong'],
                ratio: 1.0,
            ),
            'string as "id"' => static fn() => $queue->push(TypedJob::class, id: '7', ratio: 1.0),
            'string as "ratio", which its handle() takes as float' => static fn() => $queue->push(
                TypedJob::class,
                id: 7,
                ratio: 'half',
            ),
            'float as "key", which its handle() takes as string|int' => static fn() => $queue->push(
                TypedJob::class,
                id: 7,
                ratio: 1.0,
                key: 1.5,
            ),
            'null as "tags"' => static fn() => $queue->push(TypedJob::class, id: 7, ratio: 1.0, tags: null),
            'takes $when as DateTimeImmutable' => static fn() => $queue->push(DatedJob::class, when: '2026-01-01'),
            'must be a public method' => static fn() => $queue->push(HiddenJob::class),
            'takes $job, a name push() and later() use for themselves' => static fn() => $queue->push(NamedJob::class),
        ];
        foreach ($mistakes as $says => $mistake) {
            try {
                $mistake();
                self::fail("A push that should say \"{$says}\" was accepted.");
            } catch (\LogicException $error) {
                self::assertStringContainsString($says, $error->getMessage());
            }
        }
        self::assertSame(2, $store->size('default'));
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
            $task = $worker->spawn(static fn(): int => $consumer->work($worker->loop, $cancellation, sleep: 0.005));
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

            public function complete(\Crab\Contracts\Queue\Message $message): void {}

            public function release(\Crab\Contracts\Queue\Message $message, int $availableAt): void {}

            public function fail(\Crab\Contracts\Queue\Message $message, string $error, int $now): void {}

            public function failed(?int $latest = null): array
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

            public function counts(string $queue, int $now): array
            {
                return ['pending' => 0, 'delayed' => 0, 'processing' => 0, 'oldest' => null];
            }

            public function jobs(string $queue, string $state, int $now, int $limit = 50): array
            {
                return [];
            }

            public function queues(): array
            {
                return [];
            }
        };
        $consumer = new Consumer($broken, null, $this->log);
        WorkerHarness::run([], static function (Worker $worker) use ($consumer): void {
            $cancellation = new Cancellation();
            $worker->loop->delay(0.03, static fn() => $cancellation->cancel());
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
            'database' => [
                'connections' => ['main' => [
                    'dsn' => 'sqlite:' . $this->directory . '/app.sqlite',
                    'migrations' => $this->directory . '/migrations',
                ]],
            ],
        ]));
        $app->register(DatabaseProvider::class, QueueProvider::class);
        self::assertSame(['database', 'jobs', 'default', 90], [
            config('queue.store'),
            config('queue.table'),
            config('queue.queue'),
            config('queue.retry_after'),
        ]);

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
        self::assertStringContainsString(
            'failed for good after 1 tries: mail server refused 9',
            (string) file_get_contents($this->directory . '/app.log'),
        );

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

    public function testTheInstalledQueueIndexExcludesFailedJobsBeforeTheAvailabilityRange(): void
    {
        $app = new App(new Config([
            'database' => [
                'connections' => ['main' => [
                    'dsn' => 'sqlite:' . $this->directory . '/app.sqlite',
                    'migrations' => $this->directory . '/migrations',
                ]],
            ],
        ]));
        $app->register(DatabaseProvider::class, QueueProvider::class);
        self::assertSame(0, $app->console->run(['queue:install'], $this->quiet()));
        self::assertSame(0, $app->console->run(['migrate'], $this->quiet()));
        $database = $app->container->get(\Crab\Contracts\Database\Database::class);
        $indexes = $database->select('PRAGMA index_list(jobs)');
        self::assertCount(1, $indexes);
        $columns = $database->select("PRAGMA index_info('{$indexes[0]['name']}')");
        self::assertSame(['queue', 'failed_at', 'available_at'], array_column($columns, 'name'));

        $now = $this->clock->now()->getTimestamp();
        $database->execute('with recursive numbers(n) as (select 1 union all select n + 1 from numbers where n < 1000) '
        . 'insert into jobs (queue, job, payload, available_at, failed_at, created_at) '
        . "select 'default', 'old', '{}', ?, ?, ? from numbers", [$now - 100, $now - 1, $now - 100]);
        $plan = $database->select('EXPLAIN QUERY PLAN select id from jobs where queue = ? and failed_at is null '
        . 'and available_at <= ? and (reserved_at is null or reserved_at <= ?) order by id limit 5', [
            'default',
            $now,
            $now - 90,
        ]);
        self::assertStringContainsString('queue=? AND failed_at=? AND available_at<?', implode(' ', array_column(
            $plan,
            'detail',
        )));

        $store = $app->container->get(Store::class);
        self::assertNull($store->reserve(['default'], $now, 90));
        $first = $store->push('default', GreetJob::class, ['name' => 'first'], $now, $now);
        $second = $store->push('default', GreetJob::class, ['name' => 'second'], $now, $now);
        $store->push('default', GreetJob::class, ['name' => 'future'], $now + 200, $now);
        self::assertSame($first, $store->reserve(['default'], $now, 90)?->id);
        self::assertSame($second, $store->reserve(['default'], $now, 90)?->id);
        self::assertNull($store->reserve(['default'], $now, 90));
        $recovered = $store->reserve(['default'], $now + 90, 90);
        self::assertNotNull($recovered);
        self::assertSame([$first, 2], [$recovered->id, $recovered->attempts]);
    }

    public function testSupervisorsAreReadFromTheConfigurationWithTheQueuesOwnSettingsForWhatTheyLeaveOut(): void
    {
        $config = new Config([
            'queue' => [
                'queue' => 'default',
                'workers' => 2,
                'concurrency' => 1,
                'tries' => 3,
                'backoff' => [10, 60],
                'retry_after' => 90,
                'sleep' => 1.0,
                'supervisors' => [
                    'mail' => [
                        'queues' => ['mail'],
                        'workers' => 4,
                        'tries' => 5,
                        'backoff' => [1],
                        'retry_after' => 30,
                        'sleep' => 0.2,
                    ],
                    'rest' => ['queues' => ['high', 'default'], 'concurrency' => 10],
                ],
            ],
        ]);

        $supervisors = Supervisor::all($config);

        self::assertSame(['mail', 'rest'], array_keys($supervisors));
        $mail = $supervisors['mail'];
        self::assertSame([['mail'], 4, 1, 5, [1], 30, 0.2], [
            $mail->queues,
            $mail->workers,
            $mail->concurrency,
            $mail->tries,
            $mail->backoff,
            $mail->retryAfter,
            $mail->sleep,
        ]);
        $rest = $supervisors['rest'];
        self::assertSame([['high', 'default'], 2, 10, 3, [10, 60], 90, 1.0], [
            $rest->queues,
            $rest->workers,
            $rest->concurrency,
            $rest->tries,
            $rest->backoff,
            $rest->retryAfter,
            $rest->sleep,
        ]);
        self::assertSame([], Supervisor::all(new Config(['queue' => ['supervisors' => []]])));

        $mistakes = [
            'has the setting "max", which is not one of queues, workers' => [
                'mail' => ['queues' => ['mail'], 'max' => 4],
            ],
            'names no queue' => ['mail' => ['queues' => []]],
            '"workers" set to 0' => ['mail' => ['queues' => ['mail'], 'workers' => 0]],
            'by name' => [['queues' => ['mail']]],
        ];
        foreach ($mistakes as $says => $declared) {
            try {
                Supervisor::all(new Config(['queue' => ['supervisors' => $declared] + $config->get('queue')]));
                self::fail("A supervisor that {$says} was accepted.");
            } catch (\LogicException $error) {
                self::assertStringContainsString($says, $error->getMessage());
            }
        }
    }

    public function testEachSupervisorRunsItsOwnQueuesWithinItsOwnLimits(): void
    {
        $app = new App(new Config([
            'queue' => [
                'store' => 'memory',
                'backoff' => [0],
                'supervisors' => [
                    'mail' => ['queues' => ['mail'], 'tries' => 1],
                    'rest' => ['queues' => ['default'], 'tries' => 2],
                ],
            ],
            'log' => ['path' => $this->directory . '/app.log'],
        ]));
        $app->register(QueueProvider::class);
        $queue = $app->container->get(QueueContract::class);
        $queue->on('mail')->push(FlakyJob::class, id: 1);
        $queue->push(GreetJob::class, name: 'rest');
        $queue->on('reports')->push(GreetJob::class, name: 'nobody');
        Ledger::$failures = 1;

        // One supervisor alone leaves the others' queues as they are.
        [$output, $read] = $this->captured();
        self::assertSame(0, $app->console->run(['queue:work', '--supervisor=rest', '--stop-when-empty'], $output));
        self::assertSame("Ran 1 job.\n", $read());
        self::assertSame(['Hello, rest!'], Ledger::$lines);

        // All of them: mail gives its job one try, and no supervisor takes from "reports".
        $queue->push(FlakyJob::class, id: 2);
        Ledger::$failures = 2;
        [$output, $read] = $this->captured();
        self::assertSame(0, $app->console->run(['queue:work', '--stop-when-empty'], $output));
        self::assertSame("Ran 3 jobs.\n", $read());
        $log = (string) file_get_contents($this->directory . '/app.log');
        self::assertStringContainsString('failed for good after 1 tries: mail server refused 1', $log);
        self::assertStringNotContainsString('failed for good after 2 tries', $log);
        self::assertSame(['Hello, rest!', 'sent 2'], Ledger::$lines);
        $store = $app->container->get(Store::class);
        self::assertSame([0, 0, 1], [$store->size('mail'), $store->size('default'), $store->size('reports')]);

        // The command line describes one set of workers in place of the supervisors.
        self::assertSame(0, $app->console->run(['queue:work', '--queue=reports', '--stop-when-empty'], $this->quiet()));
        self::assertSame('Hello, nobody!', Ledger::$lines[2]);

        foreach ([
            'The supervisors are mail, rest' => ['--supervisor=reports'],
            'leave out --queue' => ['--supervisor=mail', '--queue=mail'],
        ] as $says => $words) {
            [$output, $read] = $this->captured();
            self::assertSame(1, $app->console->run(['queue:work', '--stop-when-empty', ...$words], $output));
            self::assertStringContainsString($says, $read());
        }
    }

    public function testEachTryOfAJobIsToldToTheApplicationsListeners(): void
    {
        $store = new MemoryStore();
        $queue = new Queue($store, 'default', $this->clock);
        $events = new Dispatcher();
        $heard = [];
        $events->listen(JobRan::class, static function (JobRan $event) use (&$heard, $store): void {
            $heard[] = [
                $event->message->job,
                $event->message->attempts,
                $event->error?->getMessage(),
                $event->gaveUp,
                $event->seconds >= 0.0,
            ];
            // What the store held as failed at the moment the listener was told.
            $heard[array_key_last($heard)][] = count($store->failed());
        });
        // A listener that fails changes nothing for the job, and is logged.
        $events->listen(JobRan::class, static fn() => throw new \LogicException('the dashboard is down'));
        $consumer = new Consumer(
            $store,
            new Container(),
            $this->log,
            $this->clock,
            tries: 2,
            backoff: [0],
            events: $events,
        );

        $queue->push(GreetJob::class, name: 'Ada');
        $queue->push(FlakyJob::class, id: 5);
        Ledger::$failures = 2;
        while ($consumer->runNext()) {
        }

        self::assertSame(
            [
                [GreetJob::class, 1, null,                    false, true, 0],
                [FlakyJob::class, 1, 'mail server refused 5', false, true, 0],
                // Told after the store knows: the job given up on is already there to be found.
                [FlakyJob::class, 2, 'mail server refused 5', true,  true, 1],
            ],
            $heard,
        );
        self::assertCount(1, $store->failed());
        self::assertSame(['Hello, Ada!'], Ledger::$lines);
        self::assertStringContainsString('the dashboard is down', implode("\n", array_column($this->log->records, 1)));
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
        self::assertSame(['emails', GreetJob::class, ['name' => 'test']], [
            $store->waiting()[0]->queue,
            $store->waiting()[0]->job,
            $store->waiting()[0]->data,
        ]);
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
        $database = new Database(fn(): \PDO => $this->pdo($kind), 1, factory: $this->mysqlFactory($kind));
        $database->execute('drop table if exists queue_test');
        new Schema($database)->create('queue_test', static function (Table $table): void {
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
            $table->index(['queue', 'failed_at', 'available_at']);
        });
        return new DatabaseStore($database, 'queue_test');
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
     * @return array{Output, \Closure(): string} An output, and what has been written to it.
     */
    private function captured(): array
    {
        $stream = fopen('php://memory', 'w+');
        return [
            new Output($stream, $stream),
            static function () use ($stream): string {
                rewind($stream);
                return (string) stream_get_contents($stream);
            },
        ];
    }
}
