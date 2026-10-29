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
        self::assertFalse($this->runs->retry($id), 'Only a failed run is retried.');
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
            'does not come back the same' => static fn(Runs $runs) => $runs->start(
                ResearchTopic::class,
                topic: 'x',
                depth: new \stdClass(),
            ),
            'takes no ' . Run::class => static fn(Runs $runs) => $runs->start(NoRun::class, id: 1),
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
    private function work(): bool
    {
        $any = false;
        while ($this->consumer->runNext()) {
            $any = true;
        }
        return $any;
    }
}
