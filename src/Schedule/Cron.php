<?php

declare(strict_types=1);

namespace Crab\Schedule;

/**
 * A cron expression of five fields: minute, hour, day of the month,
 * month, day of the week. As in cron, when both the day of the month and
 * the day of the week are restricted, a day that fits either one fits.
 */
final readonly class Cron
{
    /**
     * Each field's name, least value, greatest value, and names for values.
     */
    private const FIELDS = [
        ['minute', 0, 59, []],
        ['hour', 0, 23, []],
        ['day of the month', 1, 31, []],
        ['month', 1, 12, ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12]],
        ['day of the week', 0, 7, ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6]],
    ];

    /**
     * The values each field allows, as value => true.
     *
     * @var list<array<int, true>>
     */
    private array $allowed;
    private bool $anyDay;
    private bool $anyWeekday;

    public function __construct(public string $expression)
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($fields) !== 5) {
            throw new \InvalidArgumentException("\"{$expression}\" is not a cron expression: it has " . count($fields) . ' fields and needs five, which are minute, hour, day of the month, month and day of the week. Every day at 07:30 is "30 7 * * *".');
        }
        $allowed = [];
        foreach ($fields as $position => $field) {
            $allowed[] = self::parse($expression, $field, ...self::FIELDS[$position]);
        }
        if (isset($allowed[4][7])) {
            // Sunday is 0 and also 7.
            unset($allowed[4][7]);
            $allowed[4][0] = true;
        }
        $this->allowed = $allowed;
        $this->anyDay = $fields[2] === '*';
        $this->anyWeekday = $fields[4] === '*';
    }

    /**
     * Whether the expression fits the minute this time is in.
     */
    public function matches(\DateTimeInterface $time): bool
    {
        return isset($this->allowed[0][(int) $time->format('i')])
            && isset($this->allowed[1][(int) $time->format('G')])
            && isset($this->allowed[3][(int) $time->format('n')])
            && $this->matchesDay($time);
    }

    /**
     * The first minute after this time that the expression fits, in the
     * same time zone.
     */
    public function next(\DateTimeImmutable $after): \DateTimeImmutable
    {
        $time = $after->setTime((int) $after->format('G'), (int) $after->format('i'))->modify('+1 minute');
        // Each turn moves on by at least a minute and at most to the next month: five years is ample.
        for ($turn = 0; $turn < 200_000; ++$turn) {
            if (!isset($this->allowed[3][(int) $time->format('n')])) {
                $time = $time->modify('first day of next month')->setTime(0, 0);
            } elseif (!$this->matchesDay($time)) {
                $time = $time->modify('+1 day')->setTime(0, 0);
            } elseif (!isset($this->allowed[1][(int) $time->format('G')])) {
                $time = $time->setTime((int) $time->format('G'), 0)->modify('+1 hour');
            } elseif (!isset($this->allowed[0][(int) $time->format('i')])) {
                $time = $time->modify('+1 minute');
            } else {
                return $time;
            }
            if ((int) $time->format('Y') > (int) $after->format('Y') + 5) {
                break;
            }
        }
        throw new \LogicException("The cron expression \"{$this->expression}\" names a time that never comes, such as the 31st of a month with 30 days.");
    }

    private function matchesDay(\DateTimeInterface $time): bool
    {
        $day = isset($this->allowed[2][(int) $time->format('j')]);
        $weekday = isset($this->allowed[4][(int) $time->format('w')]);
        if ($this->anyDay) {
            return $weekday;
        }
        if ($this->anyWeekday) {
            return $day;
        }
        return $day || $weekday;
    }

    /**
     * @param array<string, int> $names
     * @return array<int, true>
     */
    private static function parse(string $expression, string $field, string $what, int $least, int $greatest, array $names): array
    {
        $allowed = [];
        foreach (explode(',', $field) as $part) {
            [$range, $step] = explode('/', $part, 2) + [1 => '1'];
            if (!ctype_digit($step) || (int) $step < 1) {
                throw self::invalid($expression, $part, $what, $least, $greatest);
            }
            if ($range === '*') {
                [$from, $to] = [$least, $greatest];
            } else {
                [$from, $to] = explode('-', $range, 2) + [1 => null];
                $from = self::value($from, $names);
                // "5/10" means from 5 on, in steps; "5" alone means 5.
                $to = $to === null ? (str_contains($part, '/') ? $greatest : $from) : self::value($to, $names);
            }
            if ($from === null || $to === null || $from < $least || $to > $greatest || $from > $to) {
                throw self::invalid($expression, $part, $what, $least, $greatest);
            }
            for ($value = $from; $value <= $to; $value += (int) $step) {
                $allowed[$value] = true;
            }
        }
        return $allowed;
    }

    /**
     * @param array<string, int> $names
     */
    private static function value(string $text, array $names): ?int
    {
        if (ctype_digit($text)) {
            return (int) $text;
        }
        return $names[strtolower($text)] ?? null;
    }

    private static function invalid(string $expression, string $part, string $what, int $least, int $greatest): \InvalidArgumentException
    {
        return new \InvalidArgumentException("\"{$expression}\" is not a cron expression: \"{$part}\" cannot be its {$what}, which is a number from {$least} to {$greatest}, a list (1,15), a range (1-5), a step (*/10) or *.");
    }
}
