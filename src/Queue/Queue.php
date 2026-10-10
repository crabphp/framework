<?php

declare(strict_types=1);

namespace Crab\Queue;

use Crab\Contracts\Queue\Queue as QueueContract;
use Crab\Contracts\Queue\Store;
use Psr\Clock\ClockInterface;

/**
 * Puts jobs into a store. What is pushed is checked against the job's
 * handle() there and then, so a wrong name or a missing argument is
 * reported by the line that pushed it, not minutes later by a worker.
 */
final class Queue implements QueueContract
{
    /**
     * For each job class already looked at: its parameters' names, and
     * which of them must be given.
     *
     * @var array<string, array{list<string>, list<string>}>
     */
    private static array $signatures = [];

    /**
     * @param string $queue The name jobs are pushed under.
     * @param ClockInterface|null $clock The real time when left out.
     */
    public function __construct(
        private readonly Store $store,
        private readonly string $queue = 'default',
        private readonly ?ClockInterface $clock = null,
    ) {
        if ($queue === '') {
            throw new \InvalidArgumentException('A queue needs a name.');
        }
    }

    public function push(string $job, mixed ...$data): int|string
    {
        return $this->add(0, $job, $data);
    }

    public function later(int|\DateInterval $delay, string $job, mixed ...$data): int|string
    {
        return $this->add($delay, $job, $data);
    }

    public function on(string $queue): QueueContract
    {
        return new self($this->store, $queue, $this->clock);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function add(int|\DateInterval $delay, string $job, array $data): int|string
    {
        self::check($job, $data);
        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        $available = $delay instanceof \DateInterval ? $now->add($delay) : $now->modify("+{$delay} seconds");
        return $this->store->push($this->queue, $job, $data, max($available->getTimestamp(), $now->getTimestamp()), $now->getTimestamp());
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private static function check(string $job, array $data): void
    {
        [$names, $required] = self::$signatures[$job] ??= self::signature($job);
        foreach ($data as $name => $value) {
            if (is_int($name)) {
                throw new \LogicException("The data of {$job} was given by position. Give each value by the name of the parameter it is for, as in push({$job}::class, " . ($names[0] ?? 'name') . ': ...), so that it still reaches the right one after the job changes.');
            }
            if (!in_array($name, $names, true)) {
                $takes = $names === [] ? 'takes no data' : 'takes ' . implode(', ', $names);
                throw new \LogicException("{$job} was pushed with \"{$name}\", which its handle() has no parameter for; it {$takes}.");
            }
            self::assertPlain($job, $name, $value);
        }
        $missing = array_diff($required, array_keys($data));
        if ($missing !== []) {
            throw new \LogicException("{$job} was pushed without " . implode(', ', $missing) . ', which its handle() requires.');
        }
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private static function signature(string $job): array
    {
        if (!class_exists($job)) {
            throw new \LogicException("{$job} was pushed as a job, and there is no such class.");
        }
        if (!method_exists($job, 'handle')) {
            throw new \LogicException("{$job} was pushed as a job, and it has no handle() method. A job is a class whose constructor takes services and whose handle() takes the job's data.");
        }
        $names = [];
        $required = [];
        foreach ((new \ReflectionMethod($job, 'handle'))->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                throw new \LogicException("{$job}::handle() takes a variable number of arguments (...\${$parameter->getName()}). Name each value a job takes, so that what is pushed can be checked.");
            }
            $names[] = $parameter->getName();
            if (!$parameter->isOptional()) {
                $required[] = $parameter->getName();
            }
        }
        return [$names, $required];
    }

    /**
     * Refuses what would not come back from JSON as it went in.
     */
    private static function assertPlain(string $job, string $name, mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertPlain($job, $name, $item);
            }
            return;
        }
        if ($value !== null && !is_scalar($value)) {
            throw new \LogicException("{$job} was pushed with " . get_debug_type($value) . " as \"{$name}\". A job's data is stored as JSON: give numbers, text, booleans, null or arrays of them, such as the id of a record and not the record.");
        }
    }
}
