<?php

declare(strict_types=1);

namespace Crab\Engine;

/**
 * The one place the engine reports what it had to catch: a handler,
 * callback or task that threw, and what the supervisor notices about its
 * workers. Nothing the engine catches is dropped without passing here.
 *
 * A worker whose group has onFailure() callbacks sends failures to them.
 * Otherwise, and for everything the parent process reports, the report is
 * written to standard error.
 */
final class FailureReporter
{
    /**
     * @var (\Closure(string, ?\Throwable): (bool|void))|null
     */
    private static ?\Closure $handler = null;

    /**
     * Reports a failure, or with no error, a notice.
     *
     * @param string $what What was being done, for example "HTTP handler failed".
     */
    public static function report(string $what, ?\Throwable $error = null): void
    {
        $handler = self::$handler;
        if ($handler !== null) {
            try {
                if ($handler($what, $error) !== false) {
                    return;
                }
            } catch (\Throwable $reportingError) {
                self::write('Failure handler failed', $reportingError);
            }
        }
        self::write($what, $error);
    }

    /**
     * Sends reports to a handler instead of standard error; null restores
     * standard error. A handler that returns false leaves that report to
     * standard error. Returns the handler that was in place, so that it
     * can be put back.
     *
     * @param (\Closure(string, ?\Throwable): (bool|void))|null $handler
     * @return (\Closure(string, ?\Throwable): (bool|void))|null
     */
    public static function handleWith(?\Closure $handler): ?\Closure
    {
        $previous = self::$handler;
        self::$handler = $handler;
        return $previous;
    }

    /**
     * A failure and those before it, as PHP writes them, but with each call
     * named without its arguments: PHP's own text includes them unless
     * zend.exception_ignore_args is on, and an argument can be a password.
     */
    public static function describe(\Throwable $error): string
    {
        $chain = [];
        for ($each = $error; $each !== null; $each = $each->getPrevious()) {
            $chain[] = $each;
        }
        $parts = [];
        foreach (array_reverse($chain) as $each) {
            $parts[] =
                $each::class
                . ": {$each->getMessage()} in {$each->getFile()}:{$each->getLine()}\nStack trace:\n"
                . implode("\n", self::frames($each));
        }
        return implode("\n\nNext ", $parts);
    }

    /**
     * A failure's trace, one line per call as in PHP's own, without the
     * arguments, ending with "{main}".
     *
     * @return non-empty-list<string>
     */
    public static function frames(\Throwable $error): array
    {
        $lines = [];
        foreach ($error->getTrace() as $number => $frame) {
            $where = isset($frame['file']) ? "{$frame['file']}(" . ($frame['line'] ?? 0) . ')' : '[internal function]';
            $lines[] =
                "#{$number} {$where}: " . ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'] . '()';
        }
        $lines[] = '#' . count($lines) . ' {main}';
        return $lines;
    }

    private static function write(string $what, ?\Throwable $error): void
    {
        $line = '[Crab] ' . $what . ($error === null ? '' : ': ' . self::describe($error)) . "\n";
        // Opened each time, not kept: a process that went into the background has
        // pointed standard error at its log, and the STDERR constant is closed with it.
        $stream = @fopen('php://stderr', 'ab');
        if ($stream === false) {
            error_log(rtrim($line));
            return;
        }
        fwrite($stream, $line);
        fclose($stream);
    }
}
