<?php

declare(strict_types=1);

namespace Crab\Schedule;

use Crab\Contracts\Schedule\Task as TaskContract;
use Psr\Container\ContainerInterface;

/**
 * One scheduled task. It is declared before the workers start and never
 * changed afterwards.
 */
final class Task implements TaskContract
{
    private Cron $cron;
    /**
     * Set when the task runs by an interval and not by the cron expression.
     */
    private ?float $seconds = null;
    private bool $oneServer = false;

    /**
     * @param string $name What the task is listed and logged as.
     * @param \Closure(?ContainerInterface): void $run Does the task's work.
     */
    public function __construct(
        private string $name,
        private readonly \Closure $run,
    ) {
        $this->cron = new Cron('* * * * *');
    }

    public function cron(string $expression): static
    {
        $this->cron = new Cron($expression);
        $this->seconds = null;
        return $this;
    }

    public function everySeconds(int|float $seconds): static
    {
        if ($seconds < 0.01) {
            throw new \InvalidArgumentException(
                "everySeconds({$seconds}) cannot be scheduled: give at least 0.01 of a second.",
            );
        }
        $this->seconds = (float) $seconds;
        return $this;
    }

    public function everyMinute(): static
    {
        return $this->cron('* * * * *');
    }

    public function everyMinutes(int $minutes): static
    {
        if ($minutes < 1 || $minutes > 59) {
            throw new \InvalidArgumentException(
                "everyMinutes({$minutes}) cannot be scheduled: give 1 to 59 minutes. For a longer interval use hourly(), daily() or cron().",
            );
        }
        return $this->cron("*/{$minutes} * * * *");
    }

    public function hourly(int $minute = 0): static
    {
        return $this->cron("{$minute} * * * *");
    }

    public function daily(string $at = '00:00'): static
    {
        [$hour, $minute] = self::time($at);
        return $this->cron("{$minute} {$hour} * * *");
    }

    public function weekly(int $weekday, string $at = '00:00'): static
    {
        [$hour, $minute] = self::time($at);
        return $this->cron("{$minute} {$hour} * * {$weekday}");
    }

    public function monthly(int $day = 1, string $at = '00:00'): static
    {
        [$hour, $minute] = self::time($at);
        return $this->cron("{$minute} {$hour} {$day} * *");
    }

    public function name(string $name): static
    {
        if ($name === '') {
            throw new \InvalidArgumentException('A scheduled task needs a name that is not empty.');
        }
        $this->name = $name;
        return $this;
    }

    public function onOneServer(): static
    {
        $this->oneServer = true;
        return $this;
    }

    public function named(): string
    {
        return $this->name;
    }

    /**
     * When it runs, as it is listed: its cron expression, or its interval.
     */
    public function expression(): string
    {
        return $this->seconds === null ? $this->cron->expression : "every {$this->seconds} s";
    }

    /**
     * The seconds between two runs of a task set with everySeconds(), and
     * null for a task that runs by the minute.
     */
    public function interval(): ?float
    {
        return $this->seconds;
    }

    public function runsOnOneServer(): bool
    {
        return $this->oneServer;
    }

    /**
     * Whether it is due in the minute this time is in. A task that runs by
     * an interval never is: a timer of its own runs it.
     */
    public function isDue(\DateTimeInterface $at): bool
    {
        return $this->seconds === null && $this->cron->matches($at);
    }

    /**
     * The next time it is due after the given one. For a task that runs by
     * an interval, that is one interval later.
     */
    public function next(\DateTimeImmutable $after): \DateTimeImmutable
    {
        if ($this->seconds === null) {
            return $this->cron->next($after);
        }
        $microseconds = (int) round($this->seconds * 1_000_000);
        return $after->modify("+{$microseconds} microseconds");
    }

    /**
     * Does the task's work, now.
     */
    public function run(?ContainerInterface $container): void
    {
        ($this->run)($container);
    }

    /**
     * @return array{int, int} The hour and the minute of a time written "07:30".
     */
    private static function time(string $at): array
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/D', $at, $match) !== 1) {
            throw new \InvalidArgumentException(
                "\"{$at}\" is not a time of day. Write the hour and the minute, as in \"07:30\" or \"18:00\".",
            );
        }
        return [(int) $match[1], (int) $match[2]];
    }
}
