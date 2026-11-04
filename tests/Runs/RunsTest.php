<?php

declare(strict_types=1);

namespace Crab\Tests\Runs;

use Crab\Container\Container;
use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Migration;
use Crab\Contracts\Runs\Run;
use Crab\Contracts\Runs\Status;
use Crab\Database\Database;
use Crab\Database\Schema;
use Crab\Engine\Async\Cancellation;
use Crab\Queue\Consumer;
use Crab\Queue\MemoryStore;
use Crab\Queue\Queue;
use Crab\Runs\Commands\InstallRuns;
use Crab\Runs\Runs;
use Crab\Testing\FrozenClock;
use PHPUnit\Framework\TestCase;

/**
 * What the runs below did, for the tests to look at.
 */
final class Journal
{
    /** @var list<string> */
    public array $did = [];
    /** @var array<string, int> */
    public array $failures = [];
}

final readonly class ResearchTopic
{
    public function __construct(
        private Journal $journal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Run $run, string $topic, int $depth = 2): array
    {
        $questions = $run->step('plan', function () use ($topic, $depth): array {
            $this->journal->did[] = 'plan';
            return array_map(static fn(int $i): string => "{$topic} {$i}", range(1, $depth));
        });
        $answers = [];
        foreach ($questions as $i => $question) {
            $answers[] = $run->step("search.{$i}", function () use ($question): string {
                $this->journal->did[] = "search {$question}";
                if (($this->journal->failures[$question] ?? 0) > 0) {
                    --$this->journal->failures[$question];
                    throw new \RuntimeException("The search for {$question} is down.");
                }
                return strtoupper($question);
            });
        }
        $run->sleep('cool-down', 300);
        $approved = $run->await('approval');
        return $run->step('report', function () use ($answers, $approved): array {
            $this->journal->did[] = 'report';
            return ['answers' => $answers, 'approved' => $approved];
        });
    }
}

final readonly class Returns
{
    public function handle(Run $run): mixed
    {
        return $run->step('when', static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-10-30 09:00:00 UTC'));
    }
}

final readonly class Twice
{
    public function handle(Run $run): void
    {
        $run->step('same', static fn(): int => 1);
        $run->step('same', static fn(): int => 2);
    }
}

final class LeaseProbe
{
    public static ?\Closure $watch = null;

    public function handle(Run $run): void
    {
        $run->step('long', static function (): bool {
            (self::$watch)();
            \Crab\Engine\Async\Async::sleep(\Crab\Engine\EventLoop::current(), 2.2);
            (self::$watch)();
            return true;
        });
    }
}

final readonly class NoRun
{
    public function handle(int $id): void {}
}

final readonly class DatedRun
{
    public function handle(Run $run, \DateTimeImmutable $when): void {}
}

final readonly class RunNamedData
{
    public function handle(Run $step, int $run): void {}
}

final readonly class DuplicateRun
{
    public function handle(Run $run, Run $other, int $id): void {}
}

final readonly class DuplicateCancellationRun
{
    public function handle(Run $run, Cancellation $first, int $id, Cancellation $second): void {}
}

final class OvertakenRun
{
    public static int $calls = 0;

    public function handle(Run $run): string
    {
        // The first worker stops here, past its lease, until the test lets it go on.
        if (++self::$calls === 1) {
            \Fiber::suspend();
            throw new \RuntimeException('the first worker failed after losing its lease');
        }
        return 'the second worker finished';
    }
}

final class AwaitedRun
{
    public function handle(Run $run): mixed
    {
        return $run->await('approval');
    }
}

/**
 * A queue whose next push fails, as a store or its connection can.
 */
final class FailingOnceQueue implements \Crab\Contracts\Queue\Queue
{
    public bool $failNext = false;

    public function __construct(
        private \Crab\Contracts\Queue\Queue $inner,
    ) {}

    public function push(string $job, mixed ...$data): int|string
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new \RuntimeException('the queue is down for a moment');
        }
        return $this->inner->push($job, ...$data);
    }

    public function later(int|\DateInterval $delay, string $job, mixed ...$data): int|string
    {
        return $this->inner->later($delay, $job, ...$data);
    }

    public function on(string $queue): \Crab\Contracts\Queue\Queue
    {
        return $this;
    }

    public function within(\Crab\Contracts\Database\Connection $transaction): \Crab\Contracts\Queue\Queue
    {
        return $this;
    }
}

final class RacedStepRun
{
    public static int $calls = 0;

    public function handle(Run $run): string
    {
        return $run->step('quote', static function (): string {
            // Each worker stops inside the step, until the test lets it go on.
            $call = ++self::$calls;
            \Fiber::suspend();
            return $call === 1 ? 'stale quote' : 'current quote';
        });
    }
}

final class TwoAwaitsRun
{
    public function handle(Run $run): string
    {
        return $run->await('one') . ' ' . $run->await('two');
    }
}

/**
 * A queue whose pushed job is taken and run before push() returns, as a quick worker can.
 */
final class EagerQueue implements \Crab\Contracts\Queue\Queue
{
    public ?\Closure $taken = null;

    public function __construct(
        private \Crab\Contracts\Queue\Queue $inner,
    ) {}

    public function push(string $job, mixed ...$data): int|string
    {
        $id = $this->inner->push($job, ...$data);
        $this->taken?->__invoke();
        return $id;
    }

    public function later(int|\DateInterval $delay, string $job, mixed ...$data): int|string
    {
        return $this->inner->later($delay, $job, ...$data);
    }

    public function on(string $queue): \Crab\Contracts\Queue\Queue
    {
        return $this;
    }

    public function within(\Crab\Contracts\Database\Connection $transaction): \Crab\Contracts\Queue\Queue
    {
        return $this;
    }
}

final class RunsTest extends TestCase
{
    private string $directory;
    private Database $db;
    private MemoryStore $store;
    private FrozenClock $clock;
    private Journal $journal;
    private Runs $runs;
    private Consumer $consumer;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/crab-runs-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        // On the MySQL server of tests/linux/run.sh --mysql when there is one, and SQLite otherwise.
        $dsn = getenv('CRAB_TEST_MYSQL_DSN');
        $this->db = new Database(fn(): \PDO => is_string($dsn)
            ? new \PDO($dsn, getenv('CRAB_TEST_MYSQL_USER') ?: 'root', getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '')
            : new \PDO('sqlite:' . $this->directory . '/app.sqlite'), 1);
        $this->db->execute('drop table if exists run_steps');
        $this->db->execute('drop table if exists runs');
        $migration = $this->directory . '/runs.php';
        file_put_contents($migration, new \ReflectionMethod(InstallRuns::class, 'migration')->invoke(
            null,
            'runs',
            'run_steps',
        ));
        $made = require $migration;
        self::assertInstanceOf(Migration::class, $made);
        $made->up(new Schema($this->db));
        $this->store = new MemoryStore();
        $this->clock = new FrozenClock('2026-10-30 09:00:00 UTC');
        $this->journal = new Journal();
        $container = new Container();
        $container->instance(Journal::class, $this->journal);
        $this->runs = new Runs(
            $this->db,
            new Queue($this->store, clock: $this->clock),
            $container,
            $this->clock,
            tries: 2,
            backoff: [30],
        );
        $container->instance(Runs::class, $this->runs);
        $this->consumer = new Consumer($this->store, $container, clock: $this->clock);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    public function testARunGoesOnFromWhereItWasAndDoesEachStepOnce(): void
    {
        $this->journal->failures = ['tides 2' => 1];
        $id = $this->runs->start(ResearchTopic::class, topic: 'tides');

        self::assertSame(Status::Pending, $this->runs->find($id)?->status);
        $this->work();
        $failed = $this->runs->find($id);
        self::assertSame([Status::Pending, 1, ['plan', 'search.0']], [
            $failed?->status,
            $failed?->attempts,
            $failed?->steps,
        ]);
        self::assertStringContainsString('The search for tides 2 is down.', (string) $failed?->error);

        $this->work(); // not yet: the backoff is 30 seconds
        $this->clock->advance('+30 seconds');
        $this->work();
        self::assertSame(Status::Sleeping, $this->runs->find($id)?->status);
        self::assertSame(['plan', 'search tides 1', 'search tides 2', 'search tides 2'], $this->journal->did);

        $this->clock->advance('+299 seconds');
        $this->work();
        self::assertSame(Status::Sleeping, $this->runs->find($id)?->status, 'A sleep is not cut short.');
        $this->clock->advance('+1 second');
        $this->work();
        self::assertSame(Status::Waiting, $this->runs->find($id)?->status);

        self::assertTrue($this->runs->signal($id, 'approval', ['by' => 7]));
        self::assertFalse($this->runs->signal($id, 'approval', ['by' => 8]), 'The first signal under a name is kept.');
        $this->work();

        $done = $this->runs->find($id);
        self::assertSame(Status::Completed, $done?->status);
        self::assertSame(['answers' => ['TIDES 1', 'TIDES 2'], 'approved' => ['by' => 7]], $done?->result);
        self::assertSame(['plan', 'search.0', 'search.1', 'cool-down', 'approval', 'report'], $done?->steps);
        self::assertSame(['plan', 'search tides 1', 'search tides 2', 'search tides 2', 'report'], $this->journal->did);
        self::assertSame(['topic' => 'tides'], $done?->data);
        self::assertFalse($this->runs->signal($id, 'late'), 'A run that has ended takes no signal.');
        self::assertFalse($this->work(), 'Nothing is left in the queue.');
    }

    public function testASignalGivenBeforeTheRunWaitsIsKeptForIt(): void
    {
        $id = $this->runs->start(ResearchTopic::class, topic: 'sea', depth: 1);
        self::assertTrue($this->runs->signal($id, 'approval', 'yes'));
        $this->work();
        $this->clock->advance('+300 seconds');
        $this->work();

        self::assertSame(['answers' => ['SEA 1'], 'approved' => 'yes'], $this->runs->find($id)?->result);
    }

    public function testLatestGivesEachRunItsOwnStepsAsFindDoes(): void
    {
        $first = $this->runs->start(ResearchTopic::class, topic: 'sea', depth: 1);
        $second = $this->runs->start(ResearchTopic::class, topic: 'sky', depth: 2);
        $this->work();
        $this->work();
        $one = $this->runs->find($first);
        $two = $this->runs->find($second);
        self::assertNotSame($one?->steps, $two?->steps, 'The two runs took different steps.');
        // Newest first, each with the steps it took.
        self::assertEquals([$two, $one], $this->runs->latest());
        self::assertEquals([$two], $this->runs->latest(limit: 1));
    }

    public function testAWorkerThatLostItsLeaseChangesNothingAfterAnotherTookTheRunOver(): void
    {
        OvertakenRun::$calls = 0;
        $runs = new Runs($this->db, new Queue($this->store, clock: $this->clock), clock: $this->clock, lease: 3);
        $id = $runs->start(OvertakenRun::class);
        $first = new \Fiber(static fn() => $runs->resume($id));
        $first->start();
        // Its lease runs out, and a second worker claims the run and finishes it.
        $this->clock->advance('+4 seconds');
        $runs->resume($id);
        $finished = $runs->find($id);
        self::assertSame([Status::Completed, 'the second worker finished'], [$finished?->status, $finished?->result]);
        $queued = $this->store->size('default');

        // The first worker comes back and fails: the run is not its own any more.
        $first->resume();
        self::assertEquals($finished, $runs->find($id));
        self::assertSame($queued, $this->store->size('default'), 'The overtaken worker queued another try.');
    }

    public function testAStepAnOvertakenWorkerFinishesIsNotKeptForTheOneThatHasTheRun(): void
    {
        RacedStepRun::$calls = 0;
        $runs = new Runs($this->db, new Queue($this->store, clock: $this->clock), clock: $this->clock, lease: 3);
        $id = $runs->start(RacedStepRun::class);
        $first = new \Fiber(static fn() => $runs->resume($id));
        $first->start();
        $this->clock->advance('+4 seconds');
        $second = new \Fiber(static fn() => $runs->resume($id));
        $second->start();

        // The first worker, overtaken, finishes its step first: it may not keep it.
        $first->resume();
        $second->resume();
        $done = $runs->find($id);
        self::assertSame([Status::Completed, 'current quote'], [$done?->status, $done?->result]);
    }

    public function testAWakingJobTakenBeforeItsPushReturnsLeavesTheRunToBeWokenAgain(): void
    {
        $queue = new EagerQueue(new Queue($this->store, clock: $this->clock));
        $runs = new Runs($this->db, $queue, clock: $this->clock);
        $id = $runs->start(TwoAwaitsRun::class);
        $this->work();
        $queue->taken = fn() => $this->work();
        $runs = new Runs($this->db, $queue, clock: $this->clock);

        // Woken, taken and back at its second await, all before push() has returned.
        self::assertTrue($runs->signal($id, 'one', 'first'));
        self::assertSame(Status::Waiting, $runs->find($id)?->status);
        // So the second signal still wakes it.
        self::assertTrue($runs->signal($id, 'two', 'second'));
        self::assertSame([Status::Completed, 'first second'], [$runs->find($id)?->status, $runs->find($id)?->result]);
    }

    public function testASignalWhoseWakingDidNotReachTheQueueWakesTheRunWhenSentAgain(): void
    {
        $queue = new FailingOnceQueue(new Queue($this->store, clock: $this->clock));
        $runs = new Runs($this->db, $queue, clock: $this->clock);
        $id = $runs->start(AwaitedRun::class);
        $runs->resume($id);
        self::assertSame(Status::Waiting, $runs->find($id)?->status);
        $before = $this->store->size('default');

        $queue->failNext = true;
        try {
            $runs->signal($id, 'approval', 'yes');
            self::fail('The failed push was hidden.');
        } catch (\RuntimeException $error) {
            self::assertSame('the queue is down for a moment', $error->getMessage());
        }
        // Not stranded: still waiting, with its signal kept, and no job pretended.
        self::assertSame(Status::Waiting, $runs->find($id)?->status);
        self::assertSame($before, $this->store->size('default'));

        // Sent again, the signal is not new, but the run is woken and finishes with it.
        self::assertFalse($runs->signal($id, 'approval', 'yes'));
        self::assertSame($before + 1, $this->store->size('default'));
        $runs->resume($id);
        self::assertSame([Status::Completed, 'yes'], [$runs->find($id)?->status, $runs->find($id)?->result]);
    }

    public function testARetryWhosePushFailsLeavesTheRunFailedToBeRetriedAgain(): void
    {
        $this->journal->failures = ['sky 1' => 2];
        $queue = new FailingOnceQueue(new Queue($this->store, clock: $this->clock));
        $runs = new Runs($this->db, $queue, clock: $this->clock, tries: 1);
        $id = $runs->start(ResearchTopic::class, topic: 'sky', depth: 1);
        $runs->resume($id);
        self::assertSame(Status::Failed, $runs->find($id)?->status);

        $queue->failNext = true;
        try {
            $runs->retry($id);
            self::fail('The failed push was hidden.');
        } catch (\RuntimeException) {
        }
        self::assertSame(Status::Failed, $runs->find($id)?->status);
        self::assertTrue($runs->retry($id));
        // The worker that takes the job claims it from failed, with its tries afresh.
        $this->journal->failures = [];
        $this->work();
        self::assertSame([Status::Sleeping, 0], [$runs->find($id)?->status, $runs->find($id)?->attempts]);
    }

    public function testTwoRetriesAskedForOneFailureRestartItOnceThoughTheRunFailsAgain(): void
    {
        $this->journal->failures = ['sky 1' => 4];
        $id = $this->runs->start(ResearchTopic::class, topic: 'sky', depth: 1);
        $this->work();
        $this->clock->advance('+30 seconds');
        $this->work();
        self::assertSame(Status::Failed, $this->runs->find($id)?->status);

        self::assertTrue($this->runs->retry($id));
        self::assertTrue($this->runs->retry($id));
        // The first job's try fails at once; the second, asked for the same failure, neither restarts the
        // run nor takes its next try ahead of its backoff.
        $this->work();
        self::assertSame([Status::Pending, 1], [$this->runs->find($id)?->status, $this->runs->find($id)?->attempts]);
        $this->clock->advance('+30 seconds');
        $this->work();
        self::assertSame([Status::Failed, 2], [$this->runs->find($id)?->status, $this->runs->find($id)?->attempts]);
        self::assertSame(['plan', 'search sky 1', 'search sky 1', 'search sky 1', 'search sky 1'], $this->journal->did);
        self::assertFalse($this->work(), 'A retry asked for the first failure restarted the second.');
    }

    public function testARunThatFailsEveryTryIsFailedAndCanBeRetried(): void
    {
        $this->journal->failures = ['sky 1' => 2];
        $id = $this->runs->start(ResearchTopic::class, topic: 'sky', depth: 1);
        $this->work();
        $this->clock->advance('+30 seconds');
        $this->work();

        $failed = $this->runs->find($id);
        self::assertSame([Status::Failed, 2], [$failed?->status, $failed?->attempts]);
        self::assertEquals([$failed], $this->runs->latest(Status::Failed));
        self::assertFalse($this->work(), 'A failed run waits to be retried.');

        self::assertTrue($this->runs->retry($id));
        // Failed until a worker takes it up: asked again meanwhile, it is asked for again, and still tried once.
        self::assertSame(Status::Failed, $this->runs->find($id)?->status);
        self::assertTrue($this->runs->retry($id));
        $this->work();
        self::assertSame([Status::Sleeping, 0, null], [
            $this->runs->find($id)?->status,
            $this->runs->find($id)?->attempts,
            $this->runs->find($id)?->error,
        ]);
        self::assertSame(
            ['plan', 'search sky 1', 'search sky 1', 'search sky 1'],
            $this->journal->did,
            'The plan was not made again.',
        );
    }

    public function testARunWhoseJobWasLostIsGivenOneOnceItIsALeaseLate(): void
    {
        // Started, and its job lost, as by a process that died before the push reached the queue.
        $this->journal->failures = ['sky 1' => 1];
        $id = $this->runs->start(ResearchTopic::class, topic: 'sky', depth: 1);
        $this->lose();
        self::assertSame(0, $this->runs->recover(), 'A run was recovered before its job could be late.');
        $this->clock->advance('+91 seconds');
        self::assertSame(1, $this->runs->recover());
        self::assertSame(0, $this->runs->recover(), 'A run given a job was given another at once.');
        // Its try fails; the job for the next, after the backoff, is lost too.
        $this->work();
        self::assertSame(Status::Pending, $this->runs->find($id)?->status);
        $this->lose();
        $this->clock->advance('+91 seconds');
        self::assertSame(0, $this->runs->recover(), 'Its next try was not a lease late yet: it was due 30 seconds on.');
        $this->clock->advance('+30 seconds');
        self::assertSame(1, $this->runs->recover());
        // Its try runs to the sleep; the job that would end it is lost.
        $this->work();
        self::assertSame(Status::Sleeping, $this->runs->find($id)?->status);
        $this->lose();
        $this->clock->advance('+300 seconds');
        self::assertSame(0, $this->runs->recover(), 'Its sleep has only just ended.');
        $this->clock->advance('+91 seconds');
        self::assertSame(1, $this->runs->recover());
        $this->work();
        // A run waiting for its signal has no job to lose.
        self::assertSame(Status::Waiting, $this->runs->find($id)?->status);
        $this->clock->advance('+1 day');
        self::assertSame(0, $this->runs->recover());
        self::assertSame(['plan', 'search sky 1', 'search sky 1'], $this->journal->did);
    }

    public function testADuplicateJobThatComesDuringABackoffWaitsForItsEnd(): void
    {
        $this->journal->failures = ['sky 1' => 2];
        $id = $this->runs->start(ResearchTopic::class, topic: 'sky', depth: 1);
        // A second job for it, as a queue that delivers at least once, or recover(), may make.
        new Queue($this->store, clock: $this->clock)->push(\Crab\Runs\ResumeRun::class, run: $id);
        $this->work();
        self::assertSame([Status::Pending, 1], [$this->runs->find($id)?->status, $this->runs->find($id)?->attempts]);
        self::assertSame(['plan', 'search sky 1'], $this->journal->did, 'A duplicate took the next try at once.');
        $this->clock->advance('+30 seconds');
        $this->work();
        self::assertSame([Status::Failed, 2], [$this->runs->find($id)?->status, $this->runs->find($id)?->attempts]);
        self::assertSame(['plan', 'search sky 1', 'search sky 1'], $this->journal->did);
    }

    public function testARunLeftRunningWithNoJobToTakeItUpIsCountedAFailedTryAndGoesOn(): void
    {
        $id = $this->runs->start(ResearchTopic::class, topic: 'sky', depth: 1);
        // Claimed by a worker that died, and its job done with, as a retry's is when redelivered.
        $this->lose();
        $this->db
            ->table('runs')
            ->where('id', $id)
            ->update([
                'status' => Status::Running->value,
                'claim' => 'gone',
                'lease_until' => $this->clock->now()->getTimestamp() + 90,
            ]);
        $this->clock->advance('+150 seconds');
        self::assertSame(0, $this->runs->recover(), 'Its lease ran out only now: the queue may yet take it up.');
        $this->clock->advance('+31 seconds');
        self::assertSame(1, $this->runs->recover());
        $state = $this->runs->find($id);
        self::assertSame([Status::Pending, 1], [$state?->status, $state?->attempts]);
        self::assertStringContainsString('stopped before it ended', (string) $state?->error);
        $this->clock->advance('+30 seconds');
        $this->work();
        self::assertSame(Status::Sleeping, $this->runs->find($id)?->status);

        // A second worker death, with no try left: the run is failed, and can be retried.
        $this->lose();
        $this->db
            ->table('runs')
            ->where('id', $id)
            ->update([
                'status' => Status::Running->value,
                'claim' => 'gone again',
                'lease_until' => $this->clock->now()->getTimestamp(),
            ]);
        $this->clock->advance('+91 seconds');
        self::assertSame(0, $this->runs->recover());
        self::assertSame([Status::Failed, 2], [$this->runs->find($id)?->status, $this->runs->find($id)?->attempts]);
        self::assertTrue($this->runs->retry($id));
    }

    public function testWhatAStepReturnsComesBackAsJsonReadsItTheFirstTimeToo(): void
    {
        $id = $this->runs->start(Returns::class);
        $this->work();

        self::assertSame('2026-10-30 09:00:00.000000', $this->runs->find($id)?->result['date'] ?? null);
    }

    public function testAStepNameUsedTwiceInOneTryFailsTheRun(): void
    {
        $id = $this->runs->start(Twice::class);
        $this->work();

        self::assertStringContainsString('used "same" twice', (string) $this->runs->find($id)?->error);
    }

    public function testAResumptionThatFindsTheRunHeldLooksAgainWhenTheLeaseRunsOut(): void
    {
        $id = $this->runs->start(ResearchTopic::class, topic: 'held');
        $this->db
            ->table('runs')
            ->where('id', $id)
            ->update([
                'status' => 'running',
                'lease_until' => $this->clock->now()->getTimestamp() + 50,
            ]);
        $this->work();

        self::assertSame([], $this->journal->did, 'Another worker has it.');
        self::assertSame(Status::Running, $this->runs->find($id)?->status);
        $this->clock->advance('+51 seconds');
        $this->work();
        self::assertSame(Status::Sleeping, $this->runs->find($id)?->status, 'Its lease ran out, and it was taken up.');
    }

    public function testTheLeaseIsRenewedWhileAStepRunsLong(): void
    {
        $runs = new Runs($this->db, new Queue($this->store), new Container(), lease: 3);
        $this->db
            ->table('runs')
            ->insert([
                'run' => LeaseProbe::class,
                'data' => '{}',
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        $leases = [];
        LeaseProbe::$watch = function () use (&$leases): void {
            $leases[] = (int) $this->db->table('runs')->where('id', 1)->value('lease_until');
        };
        $loop = \Crab\Engine\EventLoop::current();
        $task = \Crab\Engine\Async\Task::spawn($loop, static fn() => $runs->resume(1));
        \Crab\Tests\Support\Wait::until($loop, $task->isComplete(...), 'the run finishing its long step');
        $task->result();

        self::assertSame(Status::Completed, $runs->find(1)?->status);
        self::assertCount(2, $leases);
        self::assertGreaterThan($leases[0], $leases[1], 'The lease was renewed while the step ran.');
    }

    public function testARunStartedWithinATransactionStartsOnlyWithIt(): void
    {
        try {
            $this->db->transaction(function (Connection $tx): void {
                $this->runs->within($tx)->start(ResearchTopic::class, topic: 'lost');
                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->db->table('runs')->count());
        self::assertFalse($this->work());
    }

    public function testWhatARunIsStartedWithIsCheckedAtOnce(): void
    {
        foreach ([
            'by position' => static fn(Runs $runs) => $runs->start(ResearchTopic::class, 'x'),
            'has no parameter for' => static fn(Runs $runs) => $runs->start(
                ResearchTopic::class,
                topic: 'x',
                colour: 'red',
            ),
            'without topic' => static fn(Runs $runs) => $runs->start(ResearchTopic::class),
            'stored as JSON' => static fn(Runs $runs) => $runs->start(
                ResearchTopic::class,
                topic: 'x',
                depth: new \stdClass(),
            ),
            'string as "depth", which its handle() takes as int' => static fn(Runs $runs) => $runs->start(
                ResearchTopic::class,
                topic: 'x',
                depth: 'deep',
            ),
            'takes $when as DateTimeImmutable' => static fn(Runs $runs) => $runs->start(DatedRun::class, when: 'x'),
            'text that is not UTF-8' => static fn(Runs $runs) => $runs->start(ResearchTopic::class, topic: "\xff"),
            'takes no ' . Run::class => static fn(Runs $runs) => $runs->start(NoRun::class, id: 1),
            'a name start() uses for itself' => static fn(Runs $runs) => $runs->start(RunNamedData::class),
            'takes the ' . Run::class . ' more than once' => static fn(Runs $runs) => $runs->start(
                DuplicateRun::class,
                id: 1,
            ),
            'takes the cancellation more than once' => static fn(Runs $runs) => $runs->start(
                DuplicateCancellationRun::class,
                id: 1,
            ),
            'cannot be a run' => static fn(Runs $runs) => $runs->start('Nowhere'),
        ] as $expected => $start) {
            try {
                $start($this->runs);
                self::fail("Started though it should have said: {$expected}");
            } catch (\LogicException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        self::assertSame(0, $this->db->table('runs')->count());
    }

    /**
     * Runs every job that is due now, as a worker would; false when there was none.
     */
    /**
     * Takes every job off the queue without running it.
     */
    private function lose(): void
    {
        while (($message = $this->store->reserve($this->store->queues(), PHP_INT_MAX, 0)) !== null) {
            $this->store->complete($message);
        }
    }

    private function work(): bool
    {
        $any = false;
        while ($this->consumer->runNext()) {
            $any = true;
        }
        return $any;
    }
}
