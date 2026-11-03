<?php

declare(strict_types=1);

namespace Crab\Queue;

use Crab\Engine\Async\Cancellation;

/**
 * What a job's handle() takes, read once per class, and the check of the
 * data a job is pushed with against it. Runs use it too, for what a run is
 * started with: both are classes whose handle() takes their data by name,
 * kept as JSON, besides what the worker gives them itself.
 *
 * @internal
 */
final class Signature
{
    /**
     * @var array<string, self>
     */
    private static array $known = [];

    /**
     * @param list<string> $names The data parameters, in order.
     * @param list<string> $required Those without a default.
     * @param array<string, list<string>|null> $types The plain types each takes, null for any.
     * @param array<string, string> $given The parameter each type the worker gives is taken by.
     */
    private function __construct(
        public readonly string $class,
        private readonly Words $words,
        public readonly array $names,
        public readonly array $required,
        public readonly array $types,
        public readonly array $given,
    ) {}

    /**
     * What a job's handle() takes: its data, and the Cancellation the worker gives it.
     */
    public static function job(string $class): self
    {
        return self::$known["job {$class}"] ??= self::read(
            $class,
            new Words('job', 'pushed', 'push', 'push() and later() use for themselves'),
            ['job', 'delay'],
            [Cancellation::class],
        );
    }

    /**
     * What a class's handle() takes, for any kind of work like a job.
     *
     * @param list<string> $reserved Names the call takes for itself, which data could not be given under.
     * @param list<class-string> $given Types the worker gives, at most one parameter of each.
     */
    public static function of(string $class, Words $words, array $reserved, array $given): self
    {
        return self::$known["{$words->kind} {$class}"] ??= self::read($class, $words, $reserved, $given);
    }

    /**
     * Refuses data its handle() could not be called with, before it is kept.
     *
     * @param array<int|string, mixed> $data
     */
    public function check(array $data): void
    {
        $w = $this->words;
        foreach ($data as $name => $value) {
            if (is_int($name)) {
                throw new \LogicException(
                    "The data of {$this->class} was given by position. Give each value by the name of the parameter it is for, as in {$w->call}({$this->class}::class, "
                    . ($this->names[0] ?? 'name')
                    . ": ...), so that it still reaches the right one after the {$w->kind} changes.",
                );
            }
            if (!in_array($name, $this->names, true)) {
                $takes = $this->names === [] ? 'takes no data' : 'takes ' . implode(', ', $this->names);
                throw new \LogicException(
                    "{$this->class} was {$w->verb} with \"{$name}\", which its handle() has no parameter for; it {$takes}.",
                );
            }
            $this->assertPlain($name, $value);
            if ($this->types[$name] !== null && !self::fits($value, $this->types[$name])) {
                throw new \LogicException(
                    "{$this->class} was {$w->verb} with "
                    . get_debug_type($value)
                    . " as \"{$name}\", which its handle() takes as "
                    . implode('|', $this->types[$name])
                    . '.',
                );
            }
        }
        $missing = array_diff($this->required, array_keys($data));
        if ($missing !== []) {
            throw new \LogicException(
                "{$this->class} was {$w->verb} without " . implode(', ', $missing) . ', which its handle() requires.',
            );
        }
    }

    /**
     * @param list<string> $reserved
     * @param list<class-string> $given
     */
    private static function read(string $class, Words $w, array $reserved, array $given): self
    {
        if (!class_exists($class)) {
            throw new \LogicException("{$class} cannot be a {$w->kind}: there is no such class.");
        }
        if (!method_exists($class, 'handle')) {
            throw new \LogicException(
                "{$class} cannot be a {$w->kind}: it has no handle() method. A {$w->kind} is a class whose constructor takes services and whose handle() takes its data.",
            );
        }
        $handle = new \ReflectionMethod($class, 'handle');
        if (!$handle->isPublic() || $handle->isStatic() || $handle->isAbstract()) {
            throw new \LogicException(
                "{$class}::handle() must be a public method of an object, for a worker to call it.",
            );
        }
        $names = [];
        $required = [];
        $types = [];
        $takes = [];
        foreach ($handle->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : null;
            // Given by the worker that runs it, not by the data.
            if ($typeName !== null && in_array($typeName, $given, true)) {
                if (isset($takes[$typeName])) {
                    $what = $typeName === Cancellation::class ? 'the cancellation' : "the {$typeName}";
                    $short = substr(strrchr('\\' . $typeName, '\\'), 1);
                    throw new \LogicException(
                        "{$class}::handle() takes {$what} more than once; declare one {$short} parameter.",
                    );
                }
                $takes[$typeName] = $name;
                continue;
            }
            if (in_array($name, $reserved, true)) {
                throw new \LogicException(
                    "{$class}::handle() takes \${$name}, a name {$w->reserves}. Give the parameter another name.",
                );
            }
            if ($parameter->isVariadic()) {
                throw new \LogicException(
                    "{$class}::handle() takes a variable number of arguments (...\${$name}). Name each value a {$w->kind} takes, so that what is {$w->verb} can be checked.",
                );
            }
            $names[] = $name;
            if (!$parameter->isOptional()) {
                $required[] = $name;
            }
            $types[$name] = self::types($class, $w, $parameter);
        }
        return new self($class, $w, $names, $required, $types, $takes);
    }

    /**
     * The plain types a parameter takes, or null when it takes anything.
     * The data comes back from JSON, so a parameter typed as a class could
     * never be given its value.
     *
     * @return list<string>|null
     */
    private static function types(string $class, Words $w, \ReflectionParameter $parameter): ?array
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
                    "{$class}::handle() takes \${$parameter->getName()} as {$type}. A {$w->kind}'s data is stored as JSON, so a parameter is int, float, string, bool or array: take the id of a record and not the record.",
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
    private function assertPlain(string $name, mixed $value): void
    {
        $w = $this->words;
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertPlain($name, $item);
            }
            return;
        }
        if (is_float($value) && !is_finite($value)) {
            throw new \LogicException(
                "{$this->class} was {$w->verb} with a non-finite number as \"{$name}\". A {$w->kind}'s data is stored as JSON: give a finite number, text, boolean, null or an array of them.",
            );
        }
        if (is_string($value) && preg_match('//u', $value) !== 1) {
            throw new \LogicException(
                "{$this->class} was {$w->verb} with text that is not UTF-8 as \"{$name}\". A {$w->kind}'s data is stored as JSON, which keeps only UTF-8: encode bytes with base64_encode().",
            );
        }
        if ($value !== null && !is_scalar($value)) {
            throw new \LogicException(
                "{$this->class} was {$w->verb} with "
                . get_debug_type($value)
                . " as \"{$name}\". A {$w->kind}'s data is stored as JSON: give numbers, text, booleans, null or arrays of them, such as the id of a record and not the record.",
            );
        }
    }
}
