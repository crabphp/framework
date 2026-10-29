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
     * For each run class already looked at: its data parameters, the
     * required ones, and the names its Run and Cancellation are given by.
     *
     * @var array<string, array{list<string>, list<string>, ?string, ?string}>
     */
    private static array $signatures = [];

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
        if ($kept) {
            // A run that is running finds it at its await(), and one that is not is woken.
            $this->wake($id);
        }
        return $kept;
    }

    public function find(int|string $id): ?RunState
    {
        $row = $this->db->table($this->table)->find($id);
        if ($row === null) {
            return null;
        }
        $steps = $this->db->table($this->steps)->where('run_id', $id)->orderBy('id')->pluck('name');
        return new RunState(
            $row['id'],
            (string) $row['run'],
            (array) self::decode((string) $row['data']),
            Status::from((string) $row['status']),
            $row['result'] === null ? null : self::decode((string) $row['result'])['value'] ?? null,
            $row['error'] === null ? null : (string) $row['error'],
            (int) $row['attempts'],
            array_map(strval(...), $steps),
            new \DateTimeImmutable('@' . $row['created_at']),
            new \DateTimeImmutable('@' . $row['updated_at']),
        );
    }

    public function retry(int|string $id): bool
    {
        $now = $this->now();
        $retried = $this->db
            ->table($this->table)
            ->where('id', $id)
            ->where(static function ($query) use ($now): void {
                // Failed, or left running by a worker that is gone and whose job the queue gave up on.
                $query
                    ->where('status', Status::Failed->value)
                    ->orWhere(static function ($query) use ($now): void {
                        $query->where('status', Status::Running->value)->where('lease_until', '<', $now);
                    });
            })
            ->update([
                'status' => Status::Pending->value,
                'attempts' => 0,
                'error' => null,
                'lease_until' => null,
                'updated_at' => $now,
            ]) === 1;
        if ($retried) {
            $this->queue->push(ResumeRun::class, run: $id);
        }
        return $retried;
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
        $states = [];
        foreach ($query->pluck('id') as $id) {
            $state = $this->find($id);
            if ($state !== null) {
                $states[] = $state;
            }
        }
        return $states;
    }

    /**
     * Runs a run's handle() from the top, as far as it gets: to its end, a
     * sleep, an await or a failure. A queue worker calls this for each
     * ResumeRun; a test may call it to carry a run forward.
     *
     * @param Cancellation|null $cancellation Stops the run where it waits, when the worker must.
     */
    public function resume(int|string $id, ?Cancellation $cancellation = null): void
    {
        $now = $this->now();
        $claimed = $this->db
            ->table($this->table)
            ->where('id', $id)
            ->where(static function ($query) use ($now): void {
                $query
                    ->whereIn('status', [Status::Pending->value, Status::Sleeping->value, Status::Waiting->value])
                    ->orWhere(static function ($query) use ($now): void {
                        $query->where('status', Status::Running->value)->where('lease_until', '<', $now);
                    });
            })
            ->update([
                'status' => Status::Running->value,
                'lease_until' => $now + $this->lease,
                'wake_at' => null,
                'updated_at' => $now,
            ]);
        if ($claimed !== 1) {
            $held = $this->db
                ->table($this->table)
                ->where('id', $id)
                ->where('status', Status::Running->value)
                ->value('lease_until');
            if ($held !== null) {
                // Another worker has it, or had it and died: look again once its lease would have run out.
                $this->queue->later(max(1, (int) $held - $now + 1), ResumeRun::class, run: $id);
            }
            // Otherwise it has ended or is gone.
            return;
        }
        $row = $this->db->table($this->table)->find($id) ?? throw new \LogicException(
            "Run {$id} was claimed and is gone.",
        );
        $class = (string) $row['run'];
        $ended = new Cancellation();
        $renewal = $this->renew($id, $ended);
        $run = new Execution($id, $this->db, $this->steps, $this->clock, $this->loadSteps($id));
        try {
            [, , $given, $cancellationName] = self::$signatures[$class] ??= self::signature($class);
            $job = $this->container?->has($class) ? $this->container->get($class) : new $class();
            $arguments = (array) self::decode((string) $row['data']);
            $arguments[(string) $given] = $run;
            if ($cancellationName !== null) {
                $arguments[$cancellationName] = $cancellation ?? new Cancellation();
            }
            $result = $job->handle(...$arguments);
        } catch (Suspended $suspended) {
            $ended->cancel();
            $renewal?->cancel();
            $this->suspend($id, $suspended, $run);
            return;
        } catch (\Throwable $error) {
            $ended->cancel();
            $renewal?->cancel();
            if ($error instanceof CancelledException && $cancellation?->isCancelled() === true) {
                // The worker was told to stop, or lost the job: the run is let go for whoever resumes it next.
                $this->release($id);
                throw $error;
            }
            $this->fail($id, $class, (int) $row['attempts'] + 1, $error);
            return;
        }
        $ended->cancel();
        $renewal?->cancel();
        $this->db
            ->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => Status::Completed->value,
                'result' => self::encode(['value' => self::plain($result)]),
                'error' => null,
                'lease_until' => null,
                'updated_at' => $this->now(),
            ]);
    }

    /**
     * Puts a run aside in a sleep or an await, and arranges its waking.
     */
    private function suspend(int|string $id, Suspended $suspended, Execution $run): void
    {
        $status = $suspended->wakeAt === null ? Status::Waiting : Status::Sleeping;
        $this->db
            ->table($this->table)
            ->where('id', $id)
            ->where('status', Status::Running->value)
            ->update([
                'status' => $status->value,
                'wake_at' => $suspended->wakeAt,
                'lease_until' => null,
                'updated_at' => $this->now(),
            ]);
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
    private function fail(int|string $id, string $class, int $attempts, \Throwable $error): void
    {
        $failed = $attempts >= $this->tries;
        $this->db
            ->table($this->table)
            ->where('id', $id)
            ->update([
                'status' => $failed ? Status::Failed->value : Status::Pending->value,
                'attempts' => $attempts,
                'error' => self::describe($error),
                'lease_until' => null,
                'updated_at' => $this->now(),
            ]);
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
        $wait = $this->backoff === [] ? 0 : $this->backoff[min($attempts, count($this->backoff)) - 1];
        $this->logger?->warning('Run {run} {id} failed on try {attempt} of {tries} and will be tried again in {wait} seconds: {message}', [
            ...$context,
            'wait' => $wait,
        ]);
        $this->queue->later($wait, ResumeRun::class, run: $id);
    }

    private function release(int|string $id): void
    {
        $this->db
            ->table($this->table)
            ->where('id', $id)
            ->where('status', Status::Running->value)
            ->update([
                'status' => Status::Pending->value,
                'lease_until' => null,
                'updated_at' => $this->now(),
            ]);
    }

    /**
     * Pushes a ResumeRun for a run that waits, so that one only.
     */
    private function wake(int|string $id): void
    {
        $woken = $this->db
            ->table($this->table)
            ->where('id', $id)
            ->where('status', Status::Waiting->value)
            ->update(['status' => Status::Pending->value, 'updated_at' => $this->now()]);
        if ($woken === 1) {
            $this->queue->push(ResumeRun::class, run: $id);
        }
    }

    /**
     * Keeps the lease on a run while it runs, renewing it every third of its length.
     */
    private function renew(int|string $id, Cancellation $ended): ?Task
    {
        $loop = EventLoop::current();
        return Task::spawn($loop, function () use ($loop, $id, $ended): void {
            while (true) {
                try {
                    Async::sleep($loop, max(1, intdiv($this->lease, 3)), $ended);
                } catch (CancelledException) {
                    return;
                }
                try {
                    $this->db
                        ->table($this->table)
                        ->where('id', $id)
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
        [$names, $required] = self::$signatures[$run] ??= self::signature($run);
        foreach ($data as $name => $value) {
            if (is_int($name)) {
                throw new \LogicException(
                    "The data of {$run} was given by position. Give each value by the name of the parameter it is for, as in start({$run}::class, "
                    . ($names[0] ?? 'name')
                    . ': ...).',
                );
            }
            if (!in_array($name, $names, true)) {
                $takes = $names === [] ? 'takes no data' : 'takes ' . implode(', ', $names);
                throw new \LogicException(
                    "{$run} was started with \"{$name}\", which its handle() has no parameter for; it {$takes}.",
                );
            }
            if (self::plain($value) !== $value) {
                throw new \LogicException(
                    "{$run} was started with \"{$name}\" as "
                    . get_debug_type($value)
                    . ', which does not come back the same from JSON. Give numbers, text, booleans, null and arrays of them: an id, not the object.',
                );
            }
        }
        $missing = array_diff($required, array_keys($data));
        if ($missing !== []) {
            throw new \LogicException(
                "{$run} was started without " . implode(', ', $missing) . ', which its handle() requires.',
            );
        }
    }

    /**
     * @return array{list<string>, list<string>, ?string, ?string}
     */
    private static function signature(string $run): array
    {
        if (!class_exists($run) || !method_exists($run, 'handle')) {
            throw new \LogicException(
                "{$run} cannot be a run: a run is a class whose constructor takes services and whose handle(Run \$run, ...) takes its data.",
            );
        }
        $handle = new \ReflectionMethod($run, 'handle');
        if (!$handle->isPublic() || $handle->isStatic()) {
            throw new \LogicException(
                "{$run}::handle() must be a public method of an object, for a worker to call it.",
            );
        }
        $names = [];
        $required = [];
        $given = null;
        $cancellation = null;
        foreach ($handle->getParameters() as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            if ($typeName === Run::class) {
                $given = $parameter->getName();
                continue;
            }
            if ($typeName === Cancellation::class) {
                $cancellation = $parameter->getName();
                continue;
            }
            if ($parameter->isVariadic()) {
                throw new \LogicException(
                    "{$run}::handle() takes a variable number of arguments; name each value a run takes.",
                );
            }
            $names[] = $parameter->getName();
            if (!$parameter->isOptional()) {
                $required[] = $parameter->getName();
            }
        }
        if ($given === null) {
            throw new \LogicException(
                "{$run}::handle() takes no "
                . Run::class
                . ', which is how it keeps its steps. Declare handle(Run $run, ...).',
            );
        }
        return [$names, $required, $given, $cancellation];
    }
}
