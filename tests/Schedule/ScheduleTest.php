<?php

declare(strict_types=1);

namespace Crab\Tests\Schedule;

use Crab\App;
use Crab\Cache\ArrayStore;
use Crab\Cache\Cache;
use Crab\Command;
use Crab\Config\Config;
use Crab\Console\Output;
use Crab\Container\Container;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output as OutputContract;
use Crab\Contracts\Queue\Store;
use Crab\Contracts\Schedule\Schedule as ScheduleContract;
use Crab\Contracts\Schedule\TaskRan;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\FailureReporter;
use Crab\Engine\Process\Worker;
use Crab\Events\Dispatcher;
use Crab\Queue\MemoryStore;
use Crab\Queue\QueueProvider;
use Crab\Schedule\Cron;
use Crab\Schedule\Schedule;
use Crab\Schedule\ScheduleProvider;
use Crab\Schedule\Scheduler;
use Crab\Testing\FrozenClock;
use Crab\Testing\WorkerHarness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;

final class Beat
{
    public static int $count = 0;

    public function handle(): void
    {
        ++self::$count;
    }
}

final class PruneJob
{
    public function handle(int $olderThanDays): void {}
}

final class Records extends AbstractLogger
{
    /**
     * @var list<array{string, string}>
     */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            (string) $level,
            strtr((string) $message, ['{task}' => $context['task'] ?? '', '{message}' => $context['message'] ?? '']),
        ];
    }
}

/**
 * A clock that runs, from a time of the test's choosing.
 */
final class RunningClock implements ClockInterface
{
    private float $started;

    public function __construct(
        private readonly \DateTimeImmutable $from,
    ) {
        $this->started = microtime(true);
    }

    public function now(): \DateTimeImmutable
    {
        $elapsed = (int) round((microtime(true) - $this->started) * 1_000_000);
        return $this->from->modify("+{$elapsed} microseconds");
    }
}

final class ScheduleTest extends TestCase
{
    private Records $log;

    protected function setUp(): void
    {
        $this->log = new Records();
        Beat::$count = 0;
    }

    public static function expressions(): array
    {
        return [
            'every minute' => ['* * * * *', '2026-03-10 14:07', true],
            'a minute' => ['30 * * * *', '2026-03-10 14:30', true],
            'another minute' => ['30 * * * *', '2026-03-10 14:31', false],
            'a list' => ['0,15,45 * * * *', '2026-03-10 14:45', true],
            'a range' => ['* 9-17 * * *', '2026-03-10 17:59', true],
            'outside a range' => ['* 9-17 * * *', '2026-03-10 18:00', false],
            'a step' => ['*/15 * * * *', '2026-03-10 14:30', true],
            'between steps' => ['*/15 * * * *', '2026-03-10 14:31', false],
            'a step from a value' => ['5/20 * * * *', '2026-03-10 14:45', true],
            'a stepped range' => ['10-30/10 * * * *', '2026-03-10 14:40', false],
            'a month by name' => ['0 0 1 MAR *', '2026-03-01 00:00', true],
            // 2026-03-10 is a Tuesday.
            'a weekday' => ['0 9 * * 2', '2026-03-10 09:00', true],
            'weekdays by name' => ['0 9 * * mon-fri', '2026-03-14 09:00', false],
            'sunday as 7' => ['0 9 * * 7', '2026-03-15 09:00', true],
            'sunday as 0' => ['0 9 * * 0', '2026-03-15 09:00', true],
            'a day of the month alone' => ['0 9 15 * *', '2026-03-10 09:00', false],
            // As in cron: with both restricted, either is enough.
            'day or weekday, by the weekday' => ['0 9 15 * 2', '2026-03-10 09:00', true],
            'day or weekday, by the day' => ['0 9 10 * 5', '2026-03-10 09:00', true],
            'day or weekday, by neither' => ['0 9 15 * 5', '2026-03-10 09:00', false],
        ];
    }

    #[DataProvider('expressions')]
    public function testACronExpressionFitsItsMinutes(string $expression, string $time, bool $fits): void
    {
        self::assertSame($fits, new Cron($expression)->matches(new \DateTimeImmutable($time)));
    }

    public function testTheNextTimeAnExpressionFits(): void
    {
        $from = new \DateTimeImmutable('2026-03-10 14:07:30');
        $next = static fn(string $expression): string => new Cron($expression)
            ->next($from)
            ->format('Y-m-d H:i:s');

        self::assertSame('2026-03-10 14:08:00', $next('* * * * *'));
        self::assertSame('2026-03-10 14:15:00', $next('*/15 * * * *'));
        self::assertSame('2026-03-10 15:00:00', $next('0 * * * *'));
        self::assertSame('2026-03-11 07:30:00', $next('30 7 * * *'));
        self::assertSame('2026-03-16 09:00:00', $next('0 9 * * mon'));
        self::assertSame('2026-04-01 00:00:00', $next('0 0 1 * *'));
        self::assertSame('2027-01-01 00:00:00', $next('0 0 1 jan *'));
        self::assertSame('2028-02-29 12:00:00', $next('0 12 29 2 *'));
        // A minute that fits is not its own next.
        self::assertSame(
            '2026-03-10 14:08:00',
            new Cron('* * * * *')
                ->next(new \DateTimeImmutable('2026-03-10 14:07:00'))
                ->format('Y-m-d H:i:s'),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('never comes');
        $next('0 0 31 2 *');
    }

    public function testAnExpressionThatCannotBeReadSaysWhichPart(): void
    {
        $mistakes = [
            '* * * *' => 'has 4 fields and needs five',
            '60 * * * *' => '"60" cannot be its minute',
            '* 24 * * *' => '"24" cannot be its hour',
            '* * 0 * *' => '"0" cannot be its day of the month',
            '* * * 13 *' => '"13" cannot be its month',
            '* * * * 8' => '"8" cannot be its day of the week',
            '*/0 * * * *' => '"*/0" cannot be its minute',
            '5-1 * * * *' => '"5-1" cannot be its minute',
            'soon * * * *' => '"soon" cannot be its minute',
        ];
        foreach ($mistakes as $expression => $says) {
            try {
                new Cron($expression);
                self::fail("\"{$expression}\" was accepted.");
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString($says, $error->getMessage());
            }
        }
    }

    public function testTheNamedTimesAreCronExpressions(): void
    {
        $schedule = new Schedule();
        $made = [
            $schedule->call(Beat::class),
            $schedule->call(Beat::class)->everyMinutes(5),
            $schedule->call(Beat::class)->hourly(),
            $schedule->call(Beat::class)->hourly(20),
            $schedule->call(Beat::class)->daily(),
            $schedule->call(Beat::class)->daily('7:05'),
            $schedule->call(Beat::class)->weekly(1, '18:00'),
            $schedule->call(Beat::class)->monthly(),
            $schedule->call(Beat::class)->monthly(15, '09:30'),
            $schedule->call(Beat::class)->daily('07:00')->cron('1 2 3 4 5')->everyMinute(),
        ];

        self::assertSame(
            [
                '* * * * *',
                '*/5 * * * *',
                '0 * * * *',
                '20 * * * *',
                '0 0 * * *',
                '5 7 * * *',
                '0 18 * * 1',
                '0 0 1 * *',
                '30 9 15 * *',
                '* * * * *',
            ],
            array_map(static fn($task): string => $task->expression(), $made),
        );

        $refused = 0;
        foreach ([
            static fn() => $schedule->call(Beat::class)->daily('25:00'),
            static fn() => $schedule->call(Beat::class)->daily('7am'),
            static fn() => $schedule->call(Beat::class)->everyMinutes(60),
            static fn() => $schedule->call(Beat::class)->weekly(9),
            static fn() => $schedule->call(Beat::class)->name(''),
        ] as $mistake) {
            try {
                $mistake();
            } catch (\InvalidArgumentException) {
                ++$refused;
            }
        }
        self::assertSame(5, $refused);
    }

    public function testTasksAreNamedAfterWhatTheyRun(): void
    {
        $schedule = new Schedule();
        $schedule->call(Beat::class);
        $schedule->call(static fn() => null);
        $schedule->command('reports:send', '--month=last');
        $schedule->job(PruneJob::class, olderThanDays: 30);
        $schedule->call(Beat::class)->name('heartbeat');

        $names = array_map(static fn($task): string => $task->named(), $schedule->tasks());

        self::assertSame(Beat::class, $names[0]);
        self::assertMatchesRegularExpression('/^closure at ScheduleTest\.php:\d+$/', $names[1]);
        self::assertSame(
            ['command reports:send --month=last', 'job ' . PruneJob::class, 'heartbeat'],
            array_slice($names, 2),
        );

        foreach ([
            static fn() => $schedule->call('App\\Missing'),
            static fn() => $schedule->job(\stdClass::class),
        ] as $mistake) {
            try {
                $mistake();
                self::fail('What cannot be run was scheduled.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('handle()', $error->getMessage());
            }
        }
    }

    public function testOnlyWhatIsDueRunsAndAFailureIsLoggedNotThrown(): void
    {
        $ran = [];
        $schedule = new Schedule();
        $schedule->call(static function () use (&$ran): void {
            $ran[] = 'every minute';
        });
        $schedule->call(static function () use (&$ran): void {
            $ran[] = 'at seven';
        })->daily('07:00');
        $schedule
            ->call(static fn() => throw new \RuntimeException('disk is full'))
            ->name('backup')
            ->daily('07:00');
        $schedule->call(Beat::class)->hourly(30);
        $scheduler = new Scheduler($schedule, new Container(), $this->log);

        $seven = new \DateTimeImmutable('2026-03-10 07:00:12');
        $results = array_map(static fn($task): bool => $scheduler->run($task, $seven), $scheduler->due($seven));

        self::assertSame([true, true, false], $results);
        self::assertSame(['every minute', 'at seven'], $ran);
        self::assertSame([['error', 'Scheduled task backup failed: disk is full']], $this->log->records);

        $half = new \DateTimeImmutable('2026-03-10 07:30:00');
        self::assertCount(2, $scheduler->due($half));
        $scheduler->run($scheduler->due($half)[1], $half);
        self::assertSame(1, Beat::$count);
    }

    public function testTheTimesAreInTheSchedulesZone(): void
    {
        $schedule = new Schedule();
        $schedule->call(Beat::class)->daily('09:00');
        $clock = new FrozenClock(new \DateTimeImmutable('2026-03-10 08:00:00', new \DateTimeZone('UTC')));

        $utc = new Scheduler($schedule, clock: $clock);
        $paris = new Scheduler($schedule, clock: $clock, timezone: new \DateTimeZone('Europe/Paris'));

        self::assertCount(0, $utc->due($utc->now()));
        self::assertCount(1, $paris->due($paris->now()));
    }

    public function testATaskStillRunningIsNotStartedAgainAndOtherTasksAreNotHeldUp(): void
    {
        $schedule = new Schedule();
        $started = 0;
        $order = [];
        $scheduler = new Scheduler($schedule, new Container(), $this->log);

        WorkerHarness::run([], static function (Worker $worker) use ($schedule, $scheduler, &$started, &$order): void {
            $schedule->call(static function () use ($worker, &$started, &$order): void {
                ++$started;
                Async::sleep($worker->loop, 0.03);
                $order[] = 'slow';
            })->name('slow');
            $schedule->call(static function () use (&$order): void {
                $order[] = 'quick';
            })->name('quick');

            self::assertSame(2, $scheduler->tick($worker, new \DateTimeImmutable('2026-03-10 07:00:00')));
            Async::sleep($worker->loop, 0.005);
            // The next minute comes while the slow one is still at it.
            $scheduler->tick($worker, new \DateTimeImmutable('2026-03-10 07:01:00'));
            Async::sleep($worker->loop, 0.06);
        });

        self::assertSame(1, $started);
        self::assertSame(['quick', 'quick', 'slow'], $order);
        self::assertSame('warning', $this->log->records[0][0]);
        self::assertStringContainsString('slow was due and is still running', $this->log->records[0][1]);
    }

    public function testOfSeveralServersOneRunsATaskMarkedForOneServer(): void
    {
        $schedule = new Schedule();
        $schedule->call(Beat::class)->onOneServer();
        $shared = new ArrayStore();
        $first = new Scheduler($schedule, cache: new Cache($shared));
        $second = new Scheduler($schedule, cache: new Cache($shared));
        $task = $schedule->tasks()[0];

        $minute = new \DateTimeImmutable('2026-03-10 07:00:00');
        self::assertSame([true, false], [$first->run($task, $minute), $second->run($task, $minute)]);
        // The next minute is another run, and either may take it.
        $minute = $minute->modify('+1 minute');
        self::assertSame([true, false], [$second->run($task, $minute), $first->run($task, $minute)]);
        self::assertSame(2, Beat::$count);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CacheProvider');
        new Scheduler($schedule)->check();
    }

    public function testTheSchedulerWakesAtTheMinuteAndStopsWhenCancelled(): void
    {
        $schedule = new Schedule();
        $schedule->call(Beat::class)->cron('1 7 * * *');
        $schedule->call(static fn() => throw new \LogicException('not due'))->cron('0 7 * * *');
        // Fifty milliseconds before 07:01.
        $clock = new RunningClock(new \DateTimeImmutable('2026-03-10 07:00:59.950'));
        $scheduler = new Scheduler($schedule, new Container(), $this->log, $clock);

        WorkerHarness::run([], static function (Worker $worker) use ($scheduler): void {
            $cancellation = new Cancellation();
            $task = $worker->spawn(static fn() => $scheduler->work($worker, $cancellation));
            Async::sleep($worker->loop, 0.15);
            $cancellation->cancel();
            $task->await();
        });

        self::assertSame(1, Beat::$count);
        self::assertSame([], $this->log->records);
    }

    public function testATaskSetBySecondsRunsByItsOwnTimerAndNotByTheMinute(): void
    {
        $schedule = new Schedule();
        $task = $schedule->call(Beat::class)->daily('07:00')->everySeconds(0.5);

        self::assertSame(0.5, $task->interval());
        self::assertSame('every 0.5 s', $task->expression());
        self::assertSame('every 5 s', $schedule->call(Beat::class)->everySeconds(5)->expression());
        self::assertFalse($task->isDue(new \DateTimeImmutable('2026-03-10 07:00:00')));
        self::assertSame(
            '07:00:00.500000',
            $task->next(new \DateTimeImmutable('2026-03-10 07:00:00'))->format('H:i:s.u'),
        );
        // Each way of saying when replaces the one before.
        self::assertNull($task->hourly()->interval());
        self::assertSame('0 * * * *', $task->expression());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 0.01');
        $schedule->call(Beat::class)->everySeconds(0);
    }

    public function testATimerThatFiresWhileTheWorkerStopsStartsNothingAndReportsNothing(): void
    {
        $schedule = new Schedule();
        $schedule->call(Beat::class)->everySeconds(0.02);
        $scheduler = new Scheduler($schedule, new Container(), $this->log);
        $reports = [];
        $previous = FailureReporter::handleWith(static function (string $what, ?\Throwable $error) use (
            &$reports,
        ): void {
            $reports[] = $what . ': ' . $error?->getMessage();
        });
        $stopped = 0;

        try {
            WorkerHarness::run([], static function (Worker $worker) use ($scheduler, &$stopped): void {
                $scheduler->repeat($worker);
                // Allow five timer intervals before checking that a task ran.
                Async::sleep($worker->loop, 0.1);
                // The worker stops while this task is still going, and the timers go on firing meanwhile.
                $worker->stop();
                $stopped = Beat::$count;
                Async::sleep($worker->loop, 0.1);
                self::assertSame(0, $scheduler->tick($worker, new \DateTimeImmutable('2026-03-10 07:00:00')));
            });
        } finally {
            FailureReporter::handleWith($previous);
        }

        self::assertGreaterThan(0, $stopped);
        self::assertSame($stopped, Beat::$count);
        self::assertSame([], $reports);
    }

    public function testTheSchedulerRunsATaskSeveralTimesASecondUntilCancelled(): void
    {
        $schedule = new Schedule();
        $schedule->call(Beat::class)->everySeconds(0.02);
        $slow = 0;
        $scheduler = new Scheduler($schedule, new Container(), $this->log);
        $after = 0;

        WorkerHarness::run([], static function (Worker $worker) use ($schedule, $scheduler, &$slow, &$after): void {
            $schedule
                ->call(static function () use ($worker, &$slow): void {
                    ++$slow;
                    Async::sleep($worker->loop, 0.1);
                })
                ->everySeconds(0.02)
                ->name('slow');
            $cancellation = new Cancellation();
            $task = $worker->spawn(static fn() => $scheduler->work($worker, $cancellation));
            Async::sleep($worker->loop, 0.17);
            $cancellation->cancel();
            $task->await();
            // A run the timer had already started may still be on its way.
            Async::sleep($worker->loop, 0.005);
            $after = Beat::$count;
            // The timers went with the scheduler.
            Async::sleep($worker->loop, 0.06);
        });

        self::assertGreaterThanOrEqual(5, $after);
        self::assertLessThanOrEqual(9, $after);
        self::assertSame($after, Beat::$count);
        // A run still going is passed over, and at this rate without a warning.
        self::assertLessThanOrEqual(2, $slow);
        self::assertSame([], $this->log->records);
    }

    public function testOneServerKeepsATaskSetBySecondsAndAnotherTakesOverWhenItStops(): void
    {
        $schedule = new Schedule();
        $task = $schedule->call(Beat::class)->everySeconds(0.5)->onOneServer();
        $shared = new ArrayStore();
        $clock = new FrozenClock('2026-03-10 07:00:00');
        $first = new Scheduler($schedule, cache: new Cache($shared, clock: $clock));
        $second = new Scheduler($schedule, cache: new Cache($shared, clock: $clock));

        self::assertSame([true, false, true, false], [
            $first->run($task, $clock->now()),
            $second->run($task, $clock->now()),
            $first->run($task, $clock->now()),
            $second->run($task, $clock->now()),
        ]);
        // Each run renews it, so the first still has it a second later.
        $clock->advance('+1 second');
        self::assertSame([true, false], [$first->run($task, $clock->now()), $second->run($task, $clock->now())]);
        // The first stops: three intervals on, at least two seconds, the task is free.
        $clock->advance('+2 seconds');
        self::assertSame([true, false], [$second->run($task, $clock->now()), $first->run($task, $clock->now())]);
        self::assertSame(4, Beat::$count);
    }

    public function testEachRunOfATaskIsToldToTheApplicationsListeners(): void
    {
        $schedule = new Schedule();
        $schedule->call(Beat::class)->name('beat');
        $schedule->call(static fn() => throw new \RuntimeException('disk full'))->name('backup');
        $events = new Dispatcher();
        $heard = [];
        $events->listen(TaskRan::class, static function (TaskRan $event) use (&$heard): void {
            $heard[] = [$event->task, $event->error?->getMessage(), $event->seconds >= 0.0];
        });
        $scheduler = new Scheduler($schedule, new Container(), $this->log, events: $events);

        $now = new \DateTimeImmutable('2026-03-10 07:00:00');
        $results = array_map(static fn($task): bool => $scheduler->run($task, $now), $schedule->tasks());

        self::assertSame([true, false], $results);
        self::assertSame(
            [
                ['beat',   null,        true],
                ['backup', 'disk full', true],
            ],
            $heard,
        );
        // The failure is still logged, once.
        self::assertCount(1, $this->log->records);
    }

    public function testTheProviderBindsTheScheduleAndItsCommandsAndJobs(): void
    {
        $app = new App(new Config(['queue' => ['store' => 'memory'], 'schedule' => ['timezone' => 'UTC']]));
        $app->register(QueueProvider::class, ScheduleProvider::class);
        $said = [];
        Command::add('reports:send', static function (Input $input, OutputContract $output) use (&$said): int {
            $said[] = $input->option('month');
            return $input->flag('fail') ? 3 : 0;
        });
        $schedule = $app->container->get(ScheduleContract::class);
        $schedule->command('reports:send', '--month=last')->daily('07:00');
        $schedule->command('reports:send', '--fail')->daily('07:00');
        $schedule->job(PruneJob::class, olderThanDays: 30)->daily('07:00');
        $schedule->call(Beat::class)->onOneServer();
        $schedule->call(Beat::class)->everySeconds(5)->name('pulse');
        $seven = new \DateTimeImmutable('2026-03-10 07:00:00');
        $scheduler = new Scheduler($schedule, $app->container, $this->log);
        $results = array_map(
            static fn($task): bool => $scheduler->run($task, $seven),
            array_slice($scheduler->due($seven), 0, 3),
        );

        self::assertSame([true, false, true], $results);
        self::assertSame(['last', null], $said);
        self::assertStringContainsString('ended with status 3', $this->log->records[0][1]);
        $waiting = $app->container->get(Store::class);
        self::assertInstanceOf(MemoryStore::class, $waiting);
        self::assertSame([PruneJob::class, ['olderThanDays' => 30]], [
            $waiting->waiting()[0]->job,
            $waiting->waiting()[0]->data,
        ]);

        [$output, $read] = $this->captured();
        self::assertSame(0, $app->console->run(['schedule:list'], $output));
        self::assertStringContainsString('0 7 * * *  command reports:send --month=last', $read());
        self::assertStringContainsString(Beat::class . ' (one server)', $read());
        self::assertMatchesRegularExpression('/every 5 s\s+pulse\s+while schedule:work runs/', $read());

        // A task for one server and no cache: refused before anything runs.
        [$output, $read] = $this->captured();
        self::assertSame(1, $app->console->run(['schedule:work', '--single'], $output));
        self::assertStringContainsString('CacheProvider', $read());
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
