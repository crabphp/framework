<?php

declare(strict_types=1);

namespace Crab\Commands;

use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Server;
use Crab\ServerFiles;

/**
 * Shows every server of the application that is running, in the
 * foreground or the background, from the file each keeps of itself: its
 * workers, how long they have been up, what they have handled, and
 * whether any has had to be started again. It needs only those files,
 * and runs when the application cannot be loaded; see Cua.
 */
final readonly class Status implements Command
{
    /**
     * Seconds after which a server that has not rewritten its file is said to be silent; it writes every second.
     */
    private const SILENT = 5.0;

    public function __construct(
        private ServerFiles $files,
    ) {}

    public function description(): string
    {
        return <<<'TEXT'
            Show the servers that are running, their workers and what they have handled

            Options:
              --json                   Print what each server says of itself, as JSON
              --pid=storage/crab.pid   The server whose process id file this is; default "server.pid"

            Lists the servers in the foreground and the background, `queue:work`
            and `schedule:work` too. The exit status is 0 when one is running
            and 1 when none is. It runs when the application cannot be loaded, as
            after a deploy of broken code, from the files the servers keep in storage/.
            TEXT;
    }

    public function handle(Input $input, Output $output): int
    {
        $files = Stop::files($this->files, $input);
        $background = $files->running();
        $servers = $files->stats();
        if ($input->flag('json')) {
            $output->line((string) json_encode(
                ['background' => $background, 'servers' => $servers],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            ));
            return $background !== null || $servers !== [] ? 0 : 1;
        }
        if ($servers === []) {
            if ($background === null) {
                $output->line('No server is running.');
                return 1;
            }
            // Started by a version that kept no stats, or with nowhere to keep them.
            $output->line("A server is running in the background, pid {$background}.");
            $output->line("Its output goes to {$files->log}.");
            return 0;
        }
        $now = microtime(true);
        foreach ($servers as $i => $running) {
            if ($i > 0) {
                $output->line();
            }
            $output->line($this->heading($files, $running, $now));
            $output->line();
            $output->table(self::rows($running['workers'] ?? [], $now), '  ');
            if (($running['about']['mode'] ?? null) === 'background') {
                $output->line();
                $output->line("  Its output goes to {$files->log}.");
            }
        }
        return 0;
    }

    /**
     * "In the background on http://127.0.0.1:8080, pid 4120, up 2h 5m".
     *
     * @param array<string, mixed> $running
     */
    private function heading(ServerFiles $files, array $running, float $now): string
    {
        $about = $running['about'] ?? [];
        $mode = $about['mode'] ?? 'foreground';
        $where = match ($mode) {
            'background' => 'In the background',
            'single' => 'In one process',
            default => 'In the foreground',
        };
        if (isset($about['address'])) {
            $where .= ' on ' . Server::describe((string) $about['address']);
        }
        $pid = $running['pid'];
        $started = (float) $running['started'];
        $reloaded = null;
        if ($mode === 'background' && is_int($about['keeper'] ?? null)) {
            // The keeper's pid is the one `stop` and `reload` act on, and it was started with the service;
            // this process is the latest a reload put under it.
            $pid = $about['keeper'];
            $kept = $files->pidFile()->started($pid);
            if ($kept !== null && ($started - $kept) > 1.0) {
                $reloaded = $started;
                $started = $kept;
            }
        }
        $line = "{$where}, pid {$pid}, up " . self::duration($now - $started);
        if ($reloaded !== null) {
            $line .= ', reloaded ' . self::duration($now - $reloaded) . ' ago';
        }
        if (($running['state'] ?? 'running') !== 'running') {
            $line .= ", {$running['state']}";
        }
        $silent = $now - (float) ($running['updated'] ?? $now);
        if ($silent >= self::SILENT) {
            $line .= '; not heard from for ' . self::duration($silent);
        }
        return $line;
    }

    /**
     * @param list<array<string, mixed>> $workers
     * @return list<list<string>>
     */
    private static function rows(array $workers, float $now): array
    {
        $rows = [['Worker', 'Pid', 'Up', 'Handled', 'Connections', 'Memory', 'State']];
        foreach ($workers as $worker) {
            $counts = [];
            foreach ($worker['counts'] ?? [] as $name => $count) {
                $counts[] = number_format((int) $count) . " {$name}";
            }
            $state = (string) $worker['state'];
            $restarts = (int) ($worker['restarts'] ?? 0);
            if ($restarts > 0) {
                $state .= ', restarted ' . ($restarts === 1 ? 'once' : "{$restarts} times");
            }
            $rows[] = [
                "{$worker['group']} #{$worker['index']}",
                (string) ($worker['pid'] ?? '-'),
                isset($worker['started']) ? self::duration($now - (float) $worker['started']) : '-',
                $counts === [] ? '-' : implode(', ', $counts),
                $worker['pid'] === null ? '-' : (string) $worker['connections'],
                $worker['memory'] > 0 ? self::bytes((int) $worker['memory']) : '-',
                $state,
            ];
        }
        return Start::ruled($rows);
    }

    /**
     * "45s", "12m 5s", "3h 20m", "2d 4h": the two largest units.
     */
    private static function duration(float $seconds): string
    {
        $seconds = max(0, (int) $seconds);
        $parts = [];
        foreach (['d' => 86_400, 'h' => 3600, 'm' => 60, 's' => 1] as $unit => $size) {
            if ($seconds < $size && $parts === [] && $unit !== 's') {
                continue;
            }
            $parts[] = intdiv($seconds, $size) . $unit;
            $seconds %= $size;
            if (count($parts) === 2) {
                break;
            }
        }
        return implode(' ', $parts);
    }

    private static function bytes(int $bytes): string
    {
        return $bytes >= 1_048_576 ? round($bytes / 1_048_576) . ' MB' : round($bytes / 1024) . ' KB';
    }
}
