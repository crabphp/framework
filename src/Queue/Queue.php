<?php

declare(strict_types=1);

namespace Crab\Queue;

use Crab\Contracts\Database\Connection;
use Crab\Contracts\Queue\Queue as QueueContract;
use Crab\Contracts\Queue\Store;
use Crab\Contracts\Queue\TransactionalStore;
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
     * @param Connection|null $transaction The transaction a job is pushed as part of; see within().
     */
    public function __construct(
        private readonly Store $store,
        private readonly string $queue = 'default',
        private readonly ?ClockInterface $clock = null,
        private readonly ?Connection $transaction = null,
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
        return new self($this->store, $queue, $this->clock, $this->transaction);
    }

    public function within(Connection $transaction): QueueContract
    {
        return new self($this->store, $this->queue, $this->clock, $transaction);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function add(int|\DateInterval $delay, string $job, array $data): int|string
    {
        self::check($job, $data);
        $now = $this->clock?->now() ?? new \DateTimeImmutable();
        $available = $delay instanceof \DateInterval ? $now->add($delay) : $now->modify("+{$delay} seconds");
        $push = fn(Store $store): int|string => $store->push(
            $this->queue,
            $job,
            $data,
            max($available->getTimestamp(), $now->getTimestamp()),
            $now->getTimestamp(),
        );
        if ($this->transaction === null) {
            return $push($this->store);
        }
        $joined = $this->store instanceof TransactionalStore ? $this->store->through($this->transaction) : null;
        if ($joined !== null) {
            return $push($joined);
        }
        // Kept elsewhere than the transaction writes to: it can only follow the commit.
        $this->transaction->afterCommit(fn() => $push($this->store));
        return '';
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private static function check(string $job, array $data): void
    {
        [$names, $required, $types] = self::$signatures[$job] ??= self::signature($job);
        foreach ($data as $name => $value) {
            if (is_int($name)) {
                throw new \LogicException(
                    "The data of {$job} was given by position. Give each value by the name of the parameter it is for, as in push({$job}::class, "
                    . ($names[0] ?? 'name')
                    . ': ...), so that it still reaches the right one after the job changes.',
                );
            }
            if (!in_array($name, $names, true)) {
                $takes = $names === [] ? 'takes no data' : 'takes ' . implode(', ', $names);
                throw new \LogicException(
                    "{$job} was pushed with \"{$name}\", which its handle() has no parameter for; it {$takes}.",
                );
            }
            self::assertPlain($job, $name, $value);
            if ($types[$name] !== null && !self::fits($value, $types[$name])) {
                throw new \LogicException(
                    "{$job} was pushed with "
                    . get_debug_type($value)
                    . " as \"{$name}\", which its handle() takes as "
                    . implode('|', $types[$name])
                    . '.',
                );
            }
        }
        $missing = array_diff($required, array_keys($data));
        if ($missing !== []) {
            throw new \LogicException(
                "{$job} was pushed without " . implode(', ', $missing) . ', which its handle() requires.',
            );
        }
    }

    /**
     * @return array{list<string>, list<string>, array<string, list<string>|null>} The parameters' names, the required ones, and the types each takes, null for any.
     */
    private static function signature(string $job): array
    {
        if (!class_exists($job)) {
            throw new \LogicException("{$job} was pushed as a job, and there is no such class.");
        }
        if (!method_exists($job, 'handle')) {
            throw new \LogicException(
                "{$job} was pushed as a job, and it has no handle() method. A job is a class whose constructor takes services and whose handle() takes the job's data.",
            );
        }
        Retry::for($job);
        $handle = new \ReflectionMethod($job, 'handle');
        if (!$handle->isPublic() || $handle->isStatic() || $handle->isAbstract()) {
            throw new \LogicException(
                "{$job}::handle() must be a public method of an object, for a worker to call it.",
            );
        }
        $names = [];
        $required = [];
        $types = [];
        foreach ($handle->getParameters() as $parameter) {
            // push() and later() take these names themselves, so a value could not be given under them.
            if (in_array($parameter->getName(), ['job', 'delay'], true)) {
                throw new \LogicException(
                    "{$job}::handle() takes \${$parameter->getName()}, a name push() and later() use for themselves. Give the parameter another name.",
                );
            }
            if ($parameter->isVariadic()) {
                throw new \LogicException(
                    "{$job}::handle() takes a variable number of arguments (...\${$parameter->getName()}). Name each value a job takes, so that what is pushed can be checked.",
                );
            }
            $names[] = $parameter->getName();
            if (!$parameter->isOptional()) {
                $required[] = $parameter->getName();
            }
            $types[$parameter->getName()] = self::types($job, $parameter);
        }
        return [$names, $required, $types];
    }

    /**
     * The plain types a parameter takes, or null when it takes anything.
     * A job's data comes back from JSON, so a parameter typed as a class
     * could never be given its value.
     *
     * @return list<string>|null
     */
    private static function types(string $job, \ReflectionParameter $parameter): ?array
    {
        $type = $parameter->getType();
        if ($type === null) {
            return null;
        }
        $types = [];
        if ($type instanceof \ReflectionNamedType) {
            $members = [$type];
        } else {
            $members = $type instanceof \ReflectionUnionType ? $type->getTypes() : [];
        }
        foreach ($members as $one) {
            $name = $one instanceof \ReflectionNamedType ? $one->getName() : '';
            if ($name === 'mixed') {
                return null;
            }
            if (!in_array(
                $name,
                ['int', 'float', 'string', 'bool', 'array', 'null', 'true', 'false', 'iterable'],
                true,
            )) {
                throw new \LogicException(
                    "{$job}::handle() takes \${$parameter->getName()} as {$type}. A job's data is stored as JSON, so a parameter is int, float, string, bool or array: take the id of a record and not the record.",
                );
            }
            $types[] = $name;
        }
        if ($type->allowsNull() && !in_array('null', $types, true)) {
            $types[] = 'null';
        }
        return $types;
    }

    /**
     * Whether a value is one of the types, as PHP itself will judge when
     * the worker calls handle(): a whole number is taken for a float, and
     * nothing else is converted.
     *
     * @param list<string> $types
     */
    private static function fits(mixed $value, array $types): bool
    {
        foreach ($types as $type) {
            $fits = match ($type) {
                'int' => is_int($value),
                'float' => is_int($value) || is_float($value),
                'string' => is_string($value),
                'bool' => is_bool($value),
                'array', 'iterable' => is_array($value),
                'null' => $value === null,
                'true' => $value === true,
                'false' => $value === false,
                default => false,
            };
            if ($fits) {
                return true;
            }
        }
        return false;
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
            throw new \LogicException(
                "{$job} was pushed with "
                . get_debug_type($value)
                . " as \"{$name}\". A job's data is stored as JSON: give numbers, text, booleans, null or arrays of them, such as the id of a record and not the record.",
            );
        }
    }
}
