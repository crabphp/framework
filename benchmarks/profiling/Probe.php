<?php

declare(strict_types=1);

namespace CrabProfile;

final class Probe
{
    private static array $stacks = [];
    private static array $totals = [];
    private static array $edges = [];
    private static int $bookkeeping = 0;
    private static int $started;
    private static array $usage;
    private static array $reads = ['calls' => 0, 'empty' => 0, 'nonempty' => 0, 'short_nonempty' => 0, 'full' => 0, 'failed' => 0, 'bytes' => 0];

    public static function start(): void
    {
        self::$started = hrtime(true);
        self::$usage = getrusage();
        register_shutdown_function(self::report(...));
    }

    public static function enter(string $label): int
    {
        $entered = hrtime(true);
        $fiber = \Fiber::getCurrent();
        $id = $fiber === null ? 0 : spl_object_id($fiber);
        self::$stacks[$id][] = [$label, 0, 0, $entered];
        $index = count(self::$stacks[$id]) - 1;
        self::$stacks[$id][$index][1] = hrtime(true);
        return $id;
    }

    public static function leave(int $id): void
    {
        $finished = hrtime(true);
        [$label, $started, $children, $entered] = array_pop(self::$stacks[$id]);
        $inclusive = $finished - $started;
        $exclusive = max(0, $inclusive - $children);
        if (!isset(self::$totals[$label])) {
            self::$totals[$label] = [0, 0, 0, 0];
        }
        ++self::$totals[$label][0];
        self::$totals[$label][1] += $inclusive;
        self::$totals[$label][2] += $exclusive;
        self::$totals[$label][3] = max(self::$totals[$label][3], $inclusive);
        $parent = count(self::$stacks[$id]) - 1;
        if ($parent >= 0) {
            $edge = self::$stacks[$id][$parent][0] . ' -> ' . $label;
            if (!isset(self::$edges[$edge])) {
                self::$edges[$edge] = [0, 0];
            }
            ++self::$edges[$edge][0];
            self::$edges[$edge][1] += $inclusive;
        }
        $ended = hrtime(true);
        self::$bookkeeping += ($started - $entered) + ($ended - $finished);
        if ($parent >= 0) {
            // Remove the entire nested span, including its measured bookkeeping,
            // from parent self time. Inclusive spans intentionally still overlap.
            self::$stacks[$id][$parent][2] += $ended - $entered;
        }
    }

    public static function generatorValid(\Generator $generator): bool
    {
        $id = self::enter('ResponseEncoder.generator.valid');
        try {
            return $generator->valid();
        } finally {
            self::leave($id);
        }
    }

    public static function beginReason(string $message): string
    {
        self::enter('Cancellation.reason.construct');
        return $message;
    }

    public static function finishReason(\Crab\Engine\Async\CancelledException $reason): \Crab\Engine\Async\CancelledException
    {
        $fiber = \Fiber::getCurrent();
        self::leave($fiber === null ? 0 : spl_object_id($fiber));
        return $reason;
    }

    public static function readResult(string|false $bytes, int $requested): void
    {
        ++self::$reads['calls'];
        if ($bytes === false) {
            ++self::$reads['failed'];
        } elseif ($bytes === '') {
            ++self::$reads['empty'];
        } else {
            ++self::$reads['nonempty'];
            self::$reads['bytes'] += strlen($bytes);
            ++self::$reads[strlen($bytes) < $requested ? 'short_nonempty' : 'full'];
        }
    }

    public static function generatorCurrent(\Generator $generator): mixed
    {
        $id = self::enter('ResponseEncoder.generator.current');
        try {
            return $generator->current();
        } finally {
            self::leave($id);
        }
    }

    public static function generatorNext(\Generator $generator): void
    {
        $id = self::enter('ResponseEncoder.generator.next');
        try {
            $generator->next();
        } finally {
            self::leave($id);
        }
    }

    public static function report(): void
    {
        $requests = self::$totals['Parser::complete'][0] ?? 0;
        $selfTotal = array_sum(array_column(self::$totals, 2));
        $activeSelf = $selfTotal - (self::$totals['IO.select.wait'][2] ?? 0);
        $spans = [];
        foreach (self::$totals as $label => [$calls, $inclusive, $exclusive, $max]) {
            $spans[] = ['name' => $label, 'calls' => $calls,
                'calls_per_request' => $requests ? $calls / $requests : null,
                'inclusive_ms' => $inclusive / 1e6, 'self_ms' => $exclusive / 1e6,
                'inclusive_us_per_call' => $inclusive / $calls / 1e3,
                'self_us_per_call' => $exclusive / $calls / 1e3,
                'self_us_per_request' => $requests ? $exclusive / $requests / 1e3 : null,
                'self_percent_of_observed_active' => $activeSelf && $label !== 'IO.select.wait' ? $exclusive / $activeSelf * 100 : null,
                'max_inclusive_ms' => $max / 1e6];
        }
        usort($spans, static fn(array $left, array $right): int => $right['self_ms'] <=> $left['self_ms']);
        $edges = [];
        foreach (self::$edges as $edge => [$calls, $inclusive]) {
            $edges[] = ['edge' => $edge, 'calls' => $calls, 'inclusive_ms' => $inclusive / 1e6];
        }
        usort($edges, static fn(array $left, array $right): int => $right['inclusive_ms'] <=> $left['inclusive_ms']);
        $usage = getrusage();
        $cpu = static fn(array $value, string $kind): float =>
            $value['ru_' . $kind . '.tv_sec'] + $value['ru_' . $kind . '.tv_usec'] / 1e6;
        $files = [];
        foreach (['Crab\\Engine\\Http\\HttpSession', 'Crab\\Engine\\Http\\Parser',
            'Crab\\Engine\\Network\\Connection', 'Revolt\\EventLoop\\Driver\\StreamSelectDriver',
            'Revolt\\EventLoop\\Internal\\AbstractDriver'] as $class) {
            if (class_exists($class, false)) {
                $files[$class] = (new \ReflectionClass($class))->getFileName();
            }
        }
        $report = ['pid' => getmypid(), 'requests_completed' => $requests,
            'io_reads' => self::$reads,
            'elapsed_seconds' => (hrtime(true) - self::$started) / 1e9,
            'process_cpu_user_seconds' => $cpu($usage, 'utime') - $cpu(self::$usage, 'utime'),
            'process_cpu_system_seconds' => $cpu($usage, 'stime') - $cpu(self::$usage, 'stime'),
            'observed_self_ms_including_select_wait' => $selfTotal / 1e6,
            'observed_active_self_ms' => $activeSelf / 1e6,
            'measured_probe_bookkeeping_ms' => self::$bookkeeping / 1e6,
            'open_span_count' => array_sum(array_map(count(...), self::$stacks)),
            'php' => PHP_VERSION, 'jit' => function_exists('opcache_get_status') ? (opcache_get_status(false)['jit'] ?? null) : null,
            'loaded_files' => $files, 'manifest' => __DIR__ . '/manifest.json',
            'notes' => [
                'Diagnostic instrumented copy, not a throughput benchmark; probes affect JIT and total cost.',
                'Wall-time spans cover startup, warmup, measurement, drain and shutdown. Normalize by completed requests.',
                'Inclusive spans overlap: never add them. Self removes complete directly nested spans on the same Fiber.',
                'Self includes uninstrumented work and residual call/timestamp overhead; bookkeeping excludes that residual.',
                'Select wait is wall waiting, not CPU, and is excluded from the observed-active denominator.',
                'Observed-active self is not complete CPU accounting: event-loop work outside selected methods remains unmeasured.',
                'The fixture handler does not suspend. Suspended user callbacks would make wall spans unsuitable as CPU estimates.',
                'Generator valid/current/next are timed separately, never across yielded suspension.',
            ], 'spans' => $spans, 'edges' => $edges];
        $path = getenv('CRAB_HTTP_PROFILE_OUTPUT') ?: __DIR__ . '/profile-' . getmypid() . '.json';
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
