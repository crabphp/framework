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

    private static function write(string $what, ?\Throwable $error): void
    {
        $line = '[Crab] ' . $what . ($error === null ? '' : ': ' . $error) . "\n";
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
