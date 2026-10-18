<?php

declare(strict_types=1);

namespace Crab\Contracts\Validation;

/**
 * Turns loose data, such as a decoded JSON body, into an object of a
 * class, checking it against the types and rules the class declares on
 * its constructor. The class is the one description of the data: what is
 * accepted and what the code receives.
 */
interface Validator
{
    /**
     * Builds the object, or throws the component's ValidationException
     * with every problem found, by field.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<mixed> $data
     * @param bool $coerce Whether values that arrive as text, as a query string's do, may be read as numbers and booleans.
     * @return T
     */
    public function validate(string $class, array $data, bool $coerce = false): object;

    /**
     * Reads the class now, and every class inside it, and throws a
     * LogicException saying why when one cannot be validated. validate()
     * reads a class on first use; this is for learning it at startup.
     *
     * @param class-string $class
     */
    public function inspect(string $class): void;

    /**
     * The same for a function whose parameters are the fields: checks the
     * data against the named parameters' types and rules, or throws.
     *
     * @param list<string> $names The parameters that are fields; the function may have others.
     * @param array<mixed> $data
     * @return array<string, mixed> The values by parameter name. A parameter the data leaves out is left out here too, so that its default applies when the function is called with these as named arguments.
     */
    public function validateParameters(
        \ReflectionFunctionAbstract $function,
        array $names,
        array $data,
        bool $coerce = false,
    ): array;

    /**
     * The same as inspect() for a function's parameters.
     *
     * @param list<string> $names
     */
    public function inspectParameters(\ReflectionFunctionAbstract $function, array $names): void;
}
