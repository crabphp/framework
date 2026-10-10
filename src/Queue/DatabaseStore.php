<?php

declare(strict_types=1);

namespace Crab\Queue;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Database\Query;
use Crab\Contracts\Queue\Message;
use Crab\Contracts\Queue\Store;

/**
 * Jobs kept in a table of the application's database, which every worker
 * on every machine takes from. The `queue:install` command writes the
 * table's migration.
 *
 * A worker takes a job by marking its row, and only the worker whose
 * update changed the row has it, so no lock is held while a job runs and
 * the same statements serve MySQL and SQLite. A job's data is JSON in a
 * `text` column: on MySQL that is 64 kB for one job.
 */
final readonly class DatabaseStore implements Store
{
    /**
     * How many waiting jobs to look at when others may be taking them too.
     */
    private const CANDIDATES = 5;

    public function __construct(private Connection $connection, private string $table = 'jobs')
    {
    }

    public function push(string $queue, string $job, array $data, int $availableAt, int $now): int|string
    {
        return $this->connection->table($this->table)->insert([
            'queue' => $queue,
            'job' => $job,
            'payload' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'attempts' => 0,
            'available_at' => $availableAt,
            'created_at' => $now,
        ]);
    }

    public function reserve(array $queues, int $now, int $retryAfter): ?Message
    {
        $stale = $now - $retryAfter;
        $free = static fn (Query $query): Query => $query->whereNull('reserved_at')->orWhere('reserved_at', '<=', $stale);
        foreach ($queues as $queue) {
            $ids = $this->connection->table($this->table)
                ->where('queue', $queue)
                ->whereNull('failed_at')
                ->where('available_at', '<=', $now)
                ->where($free)
                ->orderBy('id')
                ->limit(self::CANDIDATES)
                ->pluck('id');
            foreach ($ids as $id) {
                // Whoever changes the row has the job; anyone else finds it taken and moves on.
                $taken = $this->connection->table($this->table)
                    ->where('id', $id)
                    ->whereNull('failed_at')
                    ->where($free)
                    ->increment('attempts', 1, ['reserved_at' => $now]);
                if ($taken === 1) {
                    $row = $this->connection->table($this->table)->find($id);
                    if ($row !== null) {
                        return self::message($row);
                    }
                }
            }
        }
        return null;
    }

    public function complete(Message $message): void
    {
        $this->connection->table($this->table)->where('id', $message->id)->delete();
    }

    public function release(Message $message, int $availableAt): void
    {
        $this->connection->table($this->table)->where('id', $message->id)->update([
            'reserved_at' => null,
            'available_at' => $availableAt,
        ]);
    }

    public function fail(Message $message, string $error, int $now): void
    {
        $this->connection->table($this->table)->where('id', $message->id)->update([
            'reserved_at' => null,
            'failed_at' => $now,
            // Enough to see what went wrong; the whole trace is in the log.
            'error' => mb_strcut($error, 0, 4000),
        ]);
    }

    public function failed(): array
    {
        $rows = $this->connection->table($this->table)->whereNotNull('failed_at')->orderBy('id')->get();
        return array_map(self::message(...), $rows);
    }

    public function retry(int|string|null $id, int $now): int
    {
        return $this->failedRows($id)->update([
            'failed_at' => null,
            'error' => null,
            'reserved_at' => null,
            'attempts' => 0,
            'available_at' => $now,
        ]);
    }

    public function forget(int|string|null $id): int
    {
        return $this->failedRows($id)->delete();
    }

    public function size(string $queue): int
    {
        return $this->connection->table($this->table)->where('queue', $queue)->whereNull('failed_at')->count();
    }

    private function failedRows(int|string|null $id): Query
    {
        $query = $this->connection->table($this->table)->whereNotNull('failed_at');
        return $id === null ? $query : $query->where('id', $id);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function message(array $row): Message
    {
        $data = json_decode((string) $row['payload'], true);
        return new Message(
            is_numeric($row['id']) ? (int) $row['id'] : (string) $row['id'],
            (string) $row['queue'],
            (string) $row['job'],
            is_array($data) ? $data : [],
            (int) $row['attempts'],
            $row['error'] === null ? null : (string) $row['error'],
            $row['failed_at'] === null ? null : (int) $row['failed_at'],
        );
    }
}
