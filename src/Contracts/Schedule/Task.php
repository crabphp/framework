<?php

declare(strict_types=1);

namespace Crab\Contracts\Schedule;

/**
 * One scheduled task: when it runs, and how. Each method that says when
 * replaces what was said before. Times are in the schedule's time zone,
 * "schedule.timezone", which is PHP's unless set.
 */
interface Task
{
    /**
     * By a cron expression of five fields: minute, hour, day of the month,
     * month, day of the week. Each is a number, a list (1,15), a range
     * (1-5), a range or a star with a step (0-59/10), a three-letter name
     * (mon, jan) or a star for every value:
     *
     *     ->cron('30 2 * * mon-fri')    at 02:30 on working days
     */
    public function cron(string $expression): static;

    /**
     * Every so many seconds, or parts of one, counted from when the
     * scheduler starts and not from the clock: everySeconds(5),
     * everySeconds(0.5). The least is 0.01.
     */
    public function everySeconds(int|float $seconds): static;

    public function everyMinute(): static;

    /**
     * Every so many minutes of the hour, from minute 0: everyMinutes(15)
     * runs at :00, :15, :30 and :45.
     */
    public function everyMinutes(int $minutes): static;

    /**
     * Once an hour, at this minute past it.
     */
    public function hourly(int $minute = 0): static;

    /**
     * Once a day, at this time: daily('07:30').
     */
    public function daily(string $at = '00:00'): static;

    /**
     * Once a week, on this day, 0 for Sunday to 6 for Saturday, at this time.
     */
    public function weekly(int $weekday, string $at = '00:00'): static;

    /**
     * Once a month, on this day of it, at this time. A day a month does
     * not have is a month the task does not run in.
     */
    public function monthly(int $day = 1, string $at = '00:00'): static;

    /**
     * The name the task is listed and logged under, in place of the one
     * made from what it runs.
     */
    public function name(string $name): static;

    /**
     * When several machines run `schedule:work`, lets only one of them run
     * the task each time it is due. They agree through the cache, which
     * must then be one they share: the database store, not files.
     *
     * For a task set with everySeconds() the servers ask the cache at
     * every run, so the store should be a fast one. One server keeps the
     * task while it runs it, and another takes over a few intervals after
     * it stops.
     */
    public function onOneServer(): static;
}
