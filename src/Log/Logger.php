<?php

declare(strict_types=1);

namespace Crab\Log;

use Crab\Contracts\Log\Logger as LoggerContract;
use Crab\Engine\FailureReporter;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * A PSR-3 logger that writes one line per record to a stream or file:
 * time, level, message, then the remaining context as JSON. An exception in
 * the context follows on its own lines with its trace.
 *
 * The trace is written without the arguments of each call, which PHP's own
 * text of an exception includes unless zend.exception_ignore_args is on: a
 * failed login would otherwise leave its password in the log.
 */
final class Logger extends AbstractLogger implements LoggerContract
{
    private const LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /**
     * @var resource|null
     */
    private mixed $stream = null;
    private int $minimum;

    /**
     * @param string|resource $target A path or stream URL, opened for appending on the first record, or an open stream.
     * @param string $level The least severe level that is written.
     * @param ClockInterface|null $clock Supplies record timestamps; the real time when left out.
     */
    public function __construct(
        private readonly mixed $target = 'php://stderr',
        string $level = LogLevel::DEBUG,
        private readonly ?ClockInterface $clock = null,
    ) {
        if (!is_string($target) && !is_resource($target)) {
            throw new \InvalidArgumentException('A log target is a path or an open stream.');
        }
        $this->minimum = self::severity($level);
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        if (self::severity($level) < $this->minimum) {
            return;
        }
        $message = (string) $message;
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            unset($context['exception']);
        } else {
            $exception = null;
        }
        if ($context !== [] && str_contains($message, '{')) {
            $replacements = [];
            foreach ($context as $key => $value) {
                if (
                    !((is_scalar($value) || $value instanceof \Stringable) && str_contains($message, '{' . $key . '}'))
                ) {
                    continue;
                }

                $replacements['{' . $key . '}'] = (string) $value;
                unset($context[$key]);
            }
            // One pass, so a placeholder inside a value is left as text.
            $message = strtr($message, $replacements);
        }
        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        $line = $now->format('Y-m-d\TH:i:s.vP') . ' ' . strtoupper($level) . ' ' . $message;
        if ($context !== []) {
            $line .=
                ' '
                . json_encode(
                    $context,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PARTIAL_OUTPUT_ON_ERROR
                    | JSON_INVALID_UTF8_SUBSTITUTE,
                );
        }
        if ($exception !== null) {
            $line .= "\n" . FailureReporter::describe($exception);
        }
        fwrite($this->stream ??= $this->open(), $line . "\n");
    }

    /**
     * @return resource
     */
    private function open(): mixed
    {
        if (is_resource($this->target)) {
            return $this->target;
        }
        // A log that cannot be opened must not take the request down with it.
        return @fopen($this->target, 'ab') ?: fopen('php://stderr', 'ab');
    }

    private static function severity(mixed $level): int
    {
        if (!is_string($level) || !isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException(
                '"'
                . (is_scalar($level) ? (string) $level : get_debug_type($level))
                . '" is not a log level. Set "log.level" to one of '
                . implode(', ', array_keys(self::LEVELS))
                . '.',
            );
        }
        return self::LEVELS[$level];
    }
}
