<?php

declare(strict_types=1);

namespace Crab\Runs;

use Crab\Contracts\Container\Container;
use Crab\Contracts\Database\Connection;
use Crab\Contracts\Queue\Queue;
use Crab\Contracts\Runs\Run;
use Crab\Contracts\Runs\Runs as RunsContract;
use Crab\Contracts\Runs\RunState;
use Crab\Contracts\Runs\Status;
use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Async\CancelledException;
use Crab\Engine\Async\Task;
use Crab\Engine\EventLoop;
use Crab\Queue\Signature;
use Crab\Queue\Words;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps runs and their steps in two tables, and has queue workers carry
 * them out: starting one stores it and pushes a ResumeRun job, and every
 * try, sleep's end and signal pushes another. A worker resumes a run by
 * running its handle() again from the top, the steps already done
 * returning what they returned before.
 *
 * Only one worker runs a run at a time: it holds a lease on it, renewed
 * while it runs, and a ResumeRun that finds the lease held does nothing.
 * A lease left by a worker that died runs out, and the queue's own retry
 * of that worker's job then resumes the run.
 */
final class Runs implements RunsContract
{
    /**
     * @param string $table The runs' table.
     * @param string $steps The steps' table.
     * @param int $tries How many times a run is tried before it is failed.
     * @param list<int> $backoff Seconds before the second try, the third, and so on; the last repeats.
     * @param int $lease Seconds a worker's hold on a run lasts unless renewed.
     * @param Connection|null $transaction The transaction a run is started as part of; see within().
     */
    public function __construct(
        private readonly Connection $db,
        private readonly Queue $queue,
        private readonly ?Container $container = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $table = 'runs',
        private readonly string $steps = 'run_steps',
        private readonly int $tries = 3,
        private readonly array $backoff = [10, 60],
        private readonly int $lease = 90,
        private readonly ?Connection $transaction = null,
    ) {
        if ($tries < 1 || $lease < 3) {
            throw new \InvalidArgumentException('Runs need at least one try and a lease of at least 3 seconds.');
        }
    }

    public function start(string $run, mixed ...$data): int|string
    {
        self::check($run, $data);
        $now = $this->now();
        $store = function (Connection $db) use ($run, $data, $now): int|string {
            $id = $db->table($this->table)->insert([
                'run' => $run,
                'data' => self::encode($data),
                'status' => Status::Pending->value,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->queue->within($db)->push(ResumeRun::class, run: $id);
            return $id;
        };
        return $this->transaction !== null ? $store($this->transaction) : $this->db->transaction($store);
    }

    public function within(Connection $transaction): RunsContract
    {
        return new self(
            $this->db,
            $this->queue,
            $this->container,
            $this->clock,
            $this->logger,
            $this->table,
            $this->steps,
            $this->tries,
            $this->backoff,
            $this->lease,
            $transaction,
        );
    }

    public function signal(int|string $id, string $name, mixed $value = null): bool
    {
        self::assertName($name);
        $row = $this->db->table($this->table)->find($id);
        if ($row === null || in_array($row['status'], [Status::Completed->value, Status::Failed->value], true)) {
            return false;
        }
        $kept = $this->db
            ->table($this->steps)
            ->insertOrIgnore([
                'run_id' => $id,
                'name' => $name,
                'kind' => 'signal',
                'result' => self::encode(['value' => self::plain($value)]),
                'created_at' => $this->now(),
            ]) === 1;
        // A run that is running finds it at its await(), and one that is not is woken. A signal
        // kept before wakes a run still waiting too: its waking may not have reached the queue.
        $this->wake($id);
        return $kept;
    }

    public function find(int|string $id): ?RunState
    {
        $row = $this->db->table($this->table)->find($id);
        if ($row === null) {
            return null;
        }
        $steps = $this->db->table($this->steps)->where('run_id', $id)->orderBy('id')->pluck('name');
        return $this->state($row, array_map(strval(...), $steps));
    }

    /**
     * A run's row and the names of its steps as the state it is in.
     *
     * @param array<string, mixed> $row
     * @param list<string> $steps
     */
    private function state(array $row, array $steps): RunState
    {
        return new RunState(
            $row['id'],
            (string) $row['run'],
            (array) self::decode((string) $row['data']),
            Status::from((string) $row['status']),
            $row['result'] === null ? null : self::decode((string) $row['result'])['value'] ?? null,
            $row['error'] === null ? null : (string) $row['error'],
            (int) $row['attempts'],
            $steps,
            new \DateTimeImmutable('@' . $row['created_at']),
            new \DateTimeImmutable('@' . $row['updated_at']),
        );
    }

    public function retry(int|string $id): bool
    {
        $row = $this->retryable($id);
        if ($row === null) {
            return false;
        }
        // Nothing is changed here: the worker that takes this job claims the run from failed, in one
        // update. A push that fails, is lost, or is never made leaves the run failed, to retry again.
        // The job names the claim the run ended under, so it restarts that failure and no later one: a
        // second retry asked meanwhile finds the run claimed afresh, or failed again, and does nothing.
        $this->queue->push(ResumeRun::class, run: $id, retry: (string) ($row['claim'] ?? ''));
        return true;
    }

    /**
     * Pushes a job for each run whose own was lost: one pending or sleeping
     * whose time came more than a lease ago, untouched since. A process that
     * died between keeping a run's state and pushing its job, or a queue
     * that lost the job, leaves a run so; schedule `runs:recover` to find
     * them. A job that is only late, behind others in its queue, is pushed
     * again at most once a lease, and runs the run once: the first to
     * claim it does, and the others find it taken, or not due yet. A run
     * left running by a worker that died, with no job to take it up, is
     * counted a failed try.
     *
     * @return int How many runs it pushed a job for.
     */
    public function recover(int $limit = 1000): int
    {
        $now = $this->now();
        $before = $now - $this->lease;
        $rows = $this->db
            ->table($this->table)
            ->select('id', 'status', 'updated_at')
            ->whereIn('status', [Status::Pending->value, Status::Sleeping->value])
            ->where('updated_at', '<', $before)
            ->where(static function ($query) use ($before): void {
                $query->whereNull('wake_at')->orWhere('wake_at', '<', $before);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $pushed = $this->recoverAbandoned($now, $before, $limit);
        foreach ($rows as $row) {
            // Touched first, so the next look leaves it a lease longer; a worker that took it meanwhile wins.
            $touched = $this->db
                ->table($this->table)
                ->where('id', $row['id'])
                ->where('status', $row['status'])
                ->where('updated_at', $row['updated_at'])
                ->update(['updated_at' => $now]);
            if ($touched !== 1) {
                continue;
            }
            $this->queue->push(ResumeRun::class, run: $row['id']);
            ++$pushed;
        }
        if ($pushed > 0) {
            $this->logger?->warning('{count} runs had no job to carry them on, and were given one.', [
                'count' => $pushed,
            ]);
        }
        return $pushed;
    }

    /**
     * Counts a failed try for each run left running by a worker that died,
     * whose lease ran out more than a lease ago with no job taking it up:
     * one whose job the queue gave up on, or a retry's whose job was done
     * with when its worker died. The run is tried again after its backoff,
     * or failed when it has no try left, so one whose code kills its worker
     * each time ends.
     *
     * @return int How many runs it pushed a job for.
     */
    private function recoverAbandoned(int $now, int $before, int $limit): int
    {
        $rows = $this->db
            ->table($this->table)
            ->select('id', 'run', 'claim', 'attempts', 'lease_until')
            ->where('status', Status::Running->value)
            ->where('lease_until', '<', $before)
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $pushed = 0;
        foreach ($rows as $row) {
            $attempts = (int) $row['attempts'] + 1;
            $failed = $attempts >= $this->tries;
            $wait = $this->backoff === [] ? 0 : $this->backoff[min($attempts, count($this->backoff)) - 1];
            $error = 'The worker running it stopped before it ended, and no job took it up.';
            // Only as the dead worker left it: one that took it over meanwhile has it.
            $counted = $this->db
                ->table($this->table)
                ->where('id', $row['id'])
                ->where('status', Status::Running->value)
                ->where('lease_until', $row['lease_until'])
                ->where(static function ($query) use ($row): void {
                    $row['claim'] === null ? $query->whereNull('claim') : $query->where('claim', $row['claim']);
                })
                ->update([
                    'status' => $failed ? Status::Failed->value : Status::Pending->value,
                    'attempts' => $attempts,
                    'error' => $error,
                    'lease_until' => null,
                    // As fail() leaves it: a failed run keeps its claim, for a retry to name.
                    'claim' => $failed ? $row['claim'] : null,
                    'wake_at' => $failed ? null : $now + $wait,
                    'updated_at' => $now,
                ]);
            if ($counted !== 1) {
                continue;
            }
            $context = ['run' => $row['run'], 'id' => $row['id'], 'attempt' => $attempts, 'tries' => $this->tries];
            if ($failed) {
                $this->logger?->error(
                    'Run {run} {id} was left running by a worker that stopped, on try {attempt} of {tries}, and is given up on.',
                    $context,
                );
                continue;
            }
            $this->logger?->warning(
                'Run {run} {id} was left running by a worker that stopped, on try {attempt} of {tries}, and will be tried again.',
                $context,
            );
            $this->queue->later($wait, ResumeRun::class, run: $row['id']);
            ++$pushed;
        }
        return $pushed;
    }

    /**
     * Whether a run can be tried again: failed, or left running by a worker
     * that is gone and whose job the queue gave up on. Its row if so.
     *
     * @return array<string, mixed>|null
     */
    private function retryable(int|string $id): ?array
    {
        $row = $this->db->table($this->table)->find($id);
        if ($row === null) {
            return null;
        }
        $retryable =
            $row['status'] === Status::Failed->value
            || $row['status'] === Status::Running->value && (int) $row['lease_until'] < $this->now();
        return $retryable ? $row : null;
    }

    /**
     * The latest runs, the newest first; of one status when given.
     *
     * @return list<RunState>
     */
    public function latest(?Status $status = null, int $limit = 50): array
    {
        $query = $this->db->table($this->table)->orderByDesc('id')->limit($limit);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $rows = $query->get();
        if ($rows === []) {
            return [];
        }
        // Every run's steps in one query, not one each.
        $steps = [];
        $ids = array_column($rows, 'id');
        foreach ($this->db->table($this->steps)->whereIn('run_id', $ids)->orderBy('id')->get() as $step) {
            $steps[$step['run_id']][] = (string) $step['name'];
        }
        return array_map(fn(array $row): RunState => $this->state($row, $steps[$row['id']] ?? []), $rows);
    }

    /**
     * Runs a run's handle() from the top, as far as it gets: to its end, a
     * sleep, an await or a failure. A queue worker calls this for each
     * ResumeRun; a test may call it to carry a run forward.
     *
     * @param Cancellation|null $cancellation Stops the run where it waits, when the worker must.
     * @param string|null $retry For a retry, the claim the run ended under, '' for none; null to resume it.
     */
    public function resume(int|string $id, ?Cancellation $cancellation = null, ?string $retry = null): void
    {
        $now = $this->now();
        // This claim's own token: a worker that outlives its lease and is overtaken writes nothing after.
        $claim = bin2hex(random_bytes(16));
        // A retry takes a failed run, or one whose worker is gone, afresh: its tries start again.
        $claimed = $retry !== null
            ? $this->db
                ->table($this->table)
                ->where('id', $id)
                ->where(static function ($query) use ($retry): void {
                    // The failure it was asked for, not one since.
                    $retry === '' ? $query->whereNull('claim') : $query->where('claim', $retry);
                })
                ->where(static function ($query) use ($now): void {
                    $query
                        ->where('status', Status::Failed->value)
                        ->orWhere(static function ($query) use ($now): void {
                            $query->where('status', Status::Running->value)->where('lease_until', '<', $now);
                        });
                })
                ->update([
                    'status' => Status::Running->value,
                    'attempts' => 0,
                    'error' => null,
                    'lease_until' => $now + $this->lease,
                    'claim' => $claim,
                    'wake_at' => null,
                    'updated_at' => $now,
                ])
            : 0;
        if ($retry !== null && $claimed !== 1) {
            // Its failure was retried already, by another job asked for it, and what came of that is
            // that job's to carry on. This one is not an ordinary resumption, to run it ahead of time.
            return;
        }
        $claimed = $claimed === 1
            ? 1
            : $this->db
                ->table($this->table)
                ->where('id', $id)
                ->where(static function ($query) use ($now): void {
                    $query->where(static function ($query) use ($now): void {
                        // Not before its time: a job that comes early, as a duplicate may, must not take
                        // a try that waits out its backoff, nor wake a sleep.
                        $query
                            ->whereIn('status', [
                                Status::Pending->value,
                                Status::Sleeping->value,
                                Status::Waiting->value,
                            ])
                            ->where(static function ($query) use ($now): void {
                                $query->whereNull('wake_at')->orWhere('wake_at', '<=', $now);
                            });
                    })->orWhere(static function ($query) use ($now): void {
                        $query->where('status', Status::Running->value)->where('lease_until', '<', $now);
                    });
                })
                ->update([
                    'status' => Status::Running->value,
                    'lease_until' => $now + $this->lease,
                    'claim' => $claim,
                    'wake_at' => null,
                    'updated_at' => $now,
                ]);
        if ($claimed !== 1) {
            $row = $this->db->table($this->table)->select('status', 'lease_until', 'wake_at')->find($id);
            $status = $row['status'] ?? null;
            if ($status === Status::Running->value) {
                // Another worker has it, or had it and died: look again once its lease would have run out.
                $this->queue->later(max(1, (int) $row['lease_until'] - $now + 1), ResumeRun::class, run: $id);
            } elseif (in_array($status, [Status::Pending->value, Status::Sleeping->value], true)) {
                // Early: come back when it is due, should this be the job that was to carry it on.
                $this->queue->later(max(1, (int) $row['wake_at'] - $now), ResumeRun::class, run: $id);
            }
            // Otherwise it has ended, waits for a signal, or is gone.
            return;
        }
        $row = $this->db->table($this->table)->find($id) ?? throw new \LogicException(
            "Run {$id} was claimed and is gone.",
        );
        $class = (string) $row['run'];
        $ended = new Cancellation();
        $renewal = $this->renew($id, $claim, $ended);
        $run = new Execution($id, $this->db, $this->steps, $this->clock, $this->loadSteps($id), $this->table, $claim);
        try {
            $signature = self::signature($class);
            $given = $signature->given[Run::class];
            $cancellationName = $signature->given[Cancellation::class] ?? null;
            $job = $this->container?->has($class) ? $this->container->get($class) : new $class();
            $arguments = (array) self::decode((string) $row['data']);
            $arguments[$given] = $run;
            if ($cancellationName !== null) {
                $arguments[$cancellationName] = $cancellation ?? new Cancellation();
            }
            $result = $job->handle(...$arguments);
        } catch (Overtaken) {
            // Another worker has it now: this one keeps nothing more, and tells nothing.
            $ended->cancel();
            $renewal?->cancel();
            $this->overtaken($id, 'stopped');
            return;
        } catch (Suspended $suspended) {
            $ended->cancel();
            $renewal?->cancel();
            $this->suspend($id, $claim, $suspended, $run);
            return;
        } catch (\Throwable $error) {
            $ended->cancel();
            $renewal?->cancel();
            if ($error instanceof CancelledException && $cancellation?->isCancelled() === true) {
                // The worker was told to stop, or lost the job: the run is let go for whoever resumes it next.
                $this->release($id, $claim);
                throw $error;
            }
            $this->fail($id, $claim, $class, (int) $row['attempts'] + 1, $error);
            return;
        }
        $ended->cancel();
        $renewal?->cancel();
        $completed = $this->held($id, $claim)->update([
            'status' => Status::Completed->value,
            'result' => self::encode(['value' => self::plain($result)]),
            'error' => null,
            'lease_until' => null,
            'claim' => null,
            'updated_at' => $this->now(),
        ]);
        if ($completed !== 1) {
            $this->overtaken($id, 'completed');
        }
    }

    /**
     * The run as long as this claim still holds it.
     */
    private function held(int|string $id, string $claim): \Crab\Contracts\Database\Query
    {
        return $this->db->table($this->table)->where('id', $id)->where('claim', $claim);
    }

    /**
     * Says that a run was taken over while this worker ran it: its lease ran
     * out, and another claimed it. What this one came to is let go.
     */
    private function overtaken(int|string $id, string $outcome): void
    {
        $this->logger?->warning(
            'Run {id} {outcome} on a worker that no longer held it: its lease ran out and another took it over. That outcome is dropped.',
            ['id' => $id, 'outcome' => $outcome],
        );
    }

    /**
     * Puts a run aside in a sleep or an await, and arranges its waking.
     */
    private function suspend(int|string $id, string $claim, Suspended $suspended, Execution $run): void
    {
        $status = $suspended->wakeAt === null ? Status::Waiting : Status::Sleeping;
        $put = $this
            ->held($id, $claim)
            ->where('status', Status::Running->value)
            ->update([
                'status' => $status->value,
                'wake_at' => $suspended->wakeAt,
                'lease_until' => null,
                'claim' => null,
                'updated_at' => $this->now(),
            ]);
        if ($put !== 1) {
            $this->overtaken($id, $status === Status::Waiting ? 'waited' : 'slept');
            return;
        }
        if ($suspended->wakeAt !== null) {
            $this->queue->later(max(0, $suspended->wakeAt - $this->now()), ResumeRun::class, run: $id);
            return;
        }
        // A signal that came while the run was still running found no one waiting: wake it now.
        if ($run->signalled($suspended->name)) {
            $this->wake($id);
        }
    }

    /**
     * Counts a failed try, and tries again after the backoff or fails the run.
     */
    private function fail(int|string $id, string $claim, string $class, int $attempts, \Throwable $error): void
    {
        $failed = $attempts >= $this->tries;
        $wait = $this->backoff === [] ? 0 : $this->backoff[min($attempts, count($this->backoff)) - 1];
        $counted = $this->held($id, $claim)->update([
            'status' => $failed ? Status::Failed->value : Status::Pending->value,
            'attempts' => $attempts,
            // When its next try is due, by which recover() knows its job is late.
            'wake_at' => $failed ? null : $this->now() + $wait,
            'error' => self::describe($error),
            'lease_until' => null,
            // A failed run keeps the claim it ended under, which a retry names to restart this failure.
            'claim' => $failed ? $claim : null,
            'updated_at' => $this->now(),
        ]);
        if ($counted !== 1) {
            // Not this worker's to count, nor to try again: the run is another's now.
            $this->overtaken($id, 'failed');
            return;
        }
        $context = [
            'run' => $class,
            'id' => $id,
            'attempt' => $attempts,
            'tries' => $this->tries,
            'message' => $error->getMessage(),
            'exception' => $error,
        ];
        if ($failed) {
            $this->logger?->error(
                'Run {run} {id} failed on try {attempt} of {tries} and is given up on: {message}',
                $context,
            );
            return;
        }
        $this->logger?->warning('Run {run} {id} failed on try {attempt} of {tries} and will be tried again in {wait} seconds: {message}', [
            ...$context,
            'wait' => $wait,
        ]);
        $this->queue->later($wait, ResumeRun::class, run: $id);
    }

    private function release(int|string $id, string $claim): void
    {
        $this
            ->held($id, $claim)
            ->where('status', Status::Running->value)
            ->update([
                'status' => Status::Pending->value,
                'lease_until' => null,
                'claim' => null,
                'updated_at' => $this->now(),
            ]);
    }

    /**
     * Pushes a ResumeRun for a run that waits, and changes nothing else: the
     * worker that takes it claims the waiting run. A late status change could
     * overwrite a newer wait the run had come to meanwhile. Another ResumeRun
     * for the same run, as for a signal sent twice, finds it held or ended.
     */
    private function wake(int|string $id): void
    {
        if ($this->db->table($this->table)->where('id', $id)->value('status') === Status::Waiting->value) {
            $this->queue->push(ResumeRun::class, run: $id);
        }
    }

    /**
     * Keeps the lease on a run while it runs, renewing it every third of its length.
     */
    private function renew(int|string $id, string $claim, Cancellation $ended): ?Task
    {
        $loop = EventLoop::current();
        return Task::spawn($loop, function () use ($loop, $id, $claim, $ended): void {
            while (true) {
                try {
                    Async::sleep($loop, max(1, intdiv($this->lease, 3)), $ended);
                } catch (CancelledException) {
                    return;
                }
                try {
                    // Only while this claim holds it: one that was overtaken must not extend another's lease.
                    $this
                        ->held($id, $claim)
                        ->where('status', Status::Running->value)
                        ->update([
                            'lease_until' => $this->now() + $this->lease,
                        ]);
                } catch (\Throwable $error) {
                    $this->logger?->warning('The lease on run {id} could not be renewed: {message}', [
                        'id' => $id,
                        'message' => $error->getMessage(),
                        'exception' => $error,
                    ]);
                }
            }
        });
    }

    /**
     * @return array<string, array{kind: string, result: ?string, wake_at: ?int}>
     */
    private function loadSteps(int|string $id): array
    {
        $steps = [];
        foreach ($this->db->table($this->steps)->where('run_id', $id)->get() as $row) {
            $steps[(string) $row['name']] = [
                'kind' => (string) $row['kind'],
                'result' => $row['result'] === null ? null : (string) $row['result'],
                'wake_at' => $row['wake_at'] === null ? null : (int) $row['wake_at'],
            ];
        }
        return $steps;
    }

    private function now(): int
    {
        return $this->clock?->now()->getTimestamp() ?? time();
    }

    private static function describe(\Throwable $error): string
    {
        return mb_strimwidth($error::class . ': ' . $error->getMessage(), 0, 2000, '...');
    }

    /**
     * @internal Shared with Execution.
     */
    public static function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @internal Shared with Execution.
     */
    public static function decode(string $json): mixed
    {
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * A value as JSON keeps it: what a run is given back for it, the first time too.
     *
     * @internal Shared with Execution.
     */
    public static function plain(mixed $value): mixed
    {
        try {
            return self::decode(self::encode($value));
        } catch (\JsonException $error) {
            throw new \LogicException(
                'A run keeps what it is given and what its steps return as JSON, and '
                    . get_debug_type($value)
                    . ' cannot be: '
                    . $error->getMessage(),
                previous: $error,
            );
        }
    }

    /**
     * @internal Shared with Execution.
     */
    public static function assertName(string $name): void
    {
        if ($name === '' || strlen($name) > 191) {
            throw new \InvalidArgumentException('A step, sleep or signal needs a name of 1 to 191 bytes.');
        }
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private static function check(string $run, array $data): void
    {
        self::signature($run)->check($data);
    }

    /**
     * What a run's handle() takes: its data, the Run that keeps its steps,
     * and the Cancellation, as a job's does.
     */
    private static function signature(string $run): Signature
    {
        $signature = Signature::of(
            $run,
            new Words('run', 'started', 'start', 'start() uses for itself'),
            ['run'],
            [Run::class, Cancellation::class],
        );
        if (!isset($signature->given[Run::class])) {
            throw new \LogicException(
                "{$run}::handle() takes no "
                . Run::class
                . ', which is how it keeps its steps. Declare handle(Run $run, ...).',
            );
        }
        return $signature;
    }
}
