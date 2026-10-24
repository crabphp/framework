<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

/**
 * Where a running server says how it is doing: one JSON file for each
 * server, named after the process that runs it and written by that
 * process about once a second, so that a command run beside it can show
 * its workers and what they have counted. The file is replaced in one
 * step, so a reader never sees it half written, and removed when the
 * server stops.
 *
 *     {"pid": 4120, "identity": "…", "started": 1761300000.5, "updated": 1761300042.1,
 *      "about": {…}, "state": "running",
 *      "workers": [{"group": "http", "index": 0, "pid": 4121, "state": "ready", "started": …,
 *                   "restarts": 0, "reported": …, "connections": 3, "tasks": 0,
 *                   "memory": 4194304, "counts": {"requests": 1200}}]}
 */
final class StatsFile
{
    public readonly string $path;
    private ?string $identity = null;
    private float $started;

    /**
     * @param array<string, mixed> $about What the server is, as whoever starts it describes it: an address, a mode.
     */
    public function __construct(
        public readonly string $directory,
        private readonly array $about = [],
    ) {
        $this->path = rtrim($directory, '/') . '/' . getmypid() . '.json';
        $this->started = microtime(true);
    }

    /**
     * Replaces the file with the server as it is now. A file that cannot be
     * written is left as it was: what a server says of itself never stops it.
     *
     * @param string $state starting, running, reloading or stopping.
     * @param list<array<string, mixed>> $workers Each as worker() makes it.
     */
    public function write(string $state, array $workers): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            return;
        }
        $this->identity ??= PidFile::identity((int) getmypid()) ?? '';
        $json = json_encode(
            [
                'pid' => getmypid(),
                'identity' => $this->identity,
                'started' => $this->started,
                'updated' => microtime(true),
                'about' => (object) $this->about,
                'state' => $state,
                'workers' => $workers,
            ],
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        $temporary = $this->path . '.tmp';
        if (@file_put_contents($temporary, $json . "\n") === false || !@rename($temporary, $this->path)) {
            @unlink($temporary);
        }
    }

    public function remove(): void
    {
        @unlink($this->path);
    }

    /**
     * One worker for write(), from what the worker reported of itself.
     *
     * @param array<string, mixed> $stats What Worker::stats() gave, or [] before its first report.
     * @param string $state starting, ready, retiring, restarting or stopped.
     * @return array<string, mixed>
     */
    public static function worker(
        string $group,
        int $index,
        ?int $pid,
        string $state,
        array $stats = [],
        int $restarts = 0,
        ?float $reported = null,
    ): array {
        return [
            'group' => $group,
            'index' => $index,
            'pid' => $pid,
            'state' => $state,
            'started' => $stats['started'] ?? null,
            'restarts' => $restarts,
            'reported' => $reported,
            'connections' => $stats['connections'] ?? 0,
            'tasks' => $stats['tasks'] ?? 0,
            'memory' => $stats['memoryBytes'] ?? 0,
            'counts' => (object) ($stats['counts'] ?? []),
        ];
    }

    /**
     * The servers running now, from the files in a directory, oldest
     * first. A file whose process has gone, or whose id now belongs to
     * another process, is left from a server that was killed and is
     * removed.
     *
     * @return list<array<string, mixed>>
     */
    public static function read(string $directory): array
    {
        $servers = [];
        foreach (glob(rtrim($directory, '/') . '/*.json') ?: [] as $file) {
            $server = json_decode((string) @file_get_contents($file), true);
            $pid = (int) basename($file, '.json');
            if (!is_array($server) || ($server['pid'] ?? null) !== $pid) {
                continue;
            }
            $running = PidFile::running($pid);
            $identity = $running ? PidFile::identity($pid) : null;
            $recorded = $server['identity'] ?? '';
            if (!$running || $identity !== null && $recorded !== '' && $identity !== $recorded) {
                @unlink($file);
                continue;
            }
            $servers[] = $server;
        }
        usort($servers, static fn(array $a, array $b): int => $a['started'] <=> $b['started']);
        return $servers;
    }
}
