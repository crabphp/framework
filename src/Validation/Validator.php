<?php

declare(strict_types=1);

namespace Crab\Validation;

use Crab\Contracts\Validation\Rule;
use Crab\Contracts\Validation\Validator as ValidatorContract;

/**
 * Reads a class's constructor once and from then on builds objects of it
 * from loose data. A parameter's type says what is accepted:
 *
 *     int, float, string, bool   that value; a float also takes a whole number
 *     array                      any array, or with #[ListOf(...)] a list of that
 *     a backed enum              one of its values
 *     DateTimeImmutable          a date and time as text, ISO 8601
 *     another class              an object, validated the same way
 *     ?type                      also null, and the field may be left out
 *
 * A parameter with a default may be left out too. Fields the class does
 * not name are ignored.
 */
final class Validator implements ValidatorContract
{
    /**
     * What was read from each class, kept because a class does not change.
     *
     * @var array<class-string, list<Property>>
     */
    private array $classes = [];
    /**
     * The same for each function whose parameters were validated.
     *
     * @var \WeakMap<\ReflectionFunctionAbstract, array<string, Property>>
     */
    private \WeakMap $functions;

    public function __construct()
    {
        $this->functions = new \WeakMap();
    }

    public function validate(string $class, array $data, bool $coerce = false): object
    {
        $errors = [];
        $object = $this->build($class, $data, $coerce, '', $errors);
        if ($object === null) {
            throw new ValidationException($errors);
        }
        return $object;
    }

    public function schema(string $class): array
    {
        return $this->objectSchema($class, []);
    }

    public function validateParameters(\ReflectionFunctionAbstract $function, array $names, array $data, bool $coerce = false): array
    {
        $errors = [];
        $values = $this->values($this->parameters($function, $names), $data, $coerce, '', $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $values;
    }

    public function parametersSchema(\ReflectionFunctionAbstract $function, array $names): array
    {
        return $this->propertiesSchema($this->parameters($function, $names), []);
    }

    /**
     * @param class-string $class
     * @param array<mixed> $data
     * @param string $path The name of the field this object is, with a trailing dot; empty at the top.
     * @param array<string, list<string>> $errors
     */
    private function build(string $class, array $data, bool $coerce, string $path, array &$errors): ?object
    {
        $found = count($errors);
        $arguments = $this->values($this->properties($class), $data, $coerce, $path, $errors);
        if (count($errors) > $found) {
            return null;
        }
        return new $class(...$arguments);
    }

    /**
     * The value of each property the data holds, by name. One the data
     * leaves out is left out here too when it has a default, so that the
     * default applies where these are passed on as named arguments.
     *
     * @param iterable<Property> $properties
     * @param array<mixed> $data
     * @param array<string, list<string>> $errors
     * @return array<string, mixed>
     */
    private function values(iterable $properties, array $data, bool $coerce, string $path, array &$errors): array
    {
        $values = [];
        foreach ($properties as $property) {
            $field = $path . $property->name;
            if (!array_key_exists($property->name, $data)) {
                if ($property->required) {
                    $errors[$field][] = 'is required';
                } elseif (!$property->hasDefault) {
                    $values[$property->name] = null;
                }
                continue;
            }
            $values[$property->name] = $this->value($property, $data[$property->name], $coerce, $field, $errors);
        }
        return $values;
    }

    /**
     * One value as its property's type, with the rules applied; a problem
     * is added to $errors under the field's name.
     *
     * @param array<string, list<string>> $errors
     */
    private function value(Property $property, mixed $value, bool $coerce, string $field, array &$errors): mixed
    {
        if ($coerce && $value === '' && $property->nullable && $property->type !== Property::STRING) {
            // "?age=" in a query string is an age that was not given.
            $value = null;
        }
        if ($value === null) {
            if (!$property->nullable) {
                $errors[$field][] = 'must not be null';
            }
            return null;
        }
        $found = count($errors);
        $value = $this->typed($property, $value, $coerce, $field, $errors);
        if (count($errors) > $found) {
            return null;
        }
        foreach ($property->rules as $rule) {
            $problem = $rule->check($value);
            if ($problem !== null) {
                $errors[$field][] = $problem;
            }
        }
        return $value;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function typed(Property $property, mixed $value, bool $coerce, string $field, array &$errors): mixed
    {
        switch ($property->type) {
            case Property::MIXED:
                return $value;
            case Property::STRING:
                if (is_string($value)) {
                    return $value;
                }
                $errors[$field][] = 'must be text';
                return null;
            case Property::INT:
                if (is_int($value)) {
                    return $value;
                }
                if ($coerce && is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
                    return (int) $value;
                }
                $errors[$field][] = 'must be a whole number';
                return null;
            case Property::FLOAT:
                if (is_int($value) || is_float($value)) {
                    return (float) $value;
                }
                if ($coerce && is_string($value) && is_numeric($value)) {
                    return (float) $value;
                }
                $errors[$field][] = 'must be a number';
                return null;
            case Property::BOOL:
                if (is_bool($value)) {
                    return $value;
                }
                if ($coerce && in_array($value, ['true', '1', 'false', '0'], true)) {
                    return $value === 'true' || $value === '1';
                }
                $errors[$field][] = 'must be true or false';
                return null;
            case Property::ENUM:
                return $this->enum($property, $value, $coerce, $field, $errors);
            case Property::DATE:
                return $this->date($property, $value, $field, $errors);
            case Property::OBJECT:
                if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                    $errors[$field][] = 'must be an object';
                    return null;
                }
                return $this->build($property->class, $value, $coerce, $field . '.', $errors);
            case Property::ARRAY:
                return $this->list($property, $value, $coerce, $field, $errors);
        }
        throw new \LogicException("A property has the unknown type \"{$property->type}\".");
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function enum(Property $property, mixed $value, bool $coerce, string $field, array &$errors): ?\BackedEnum
    {
        $class = $property->class;
        $integers = is_int($class::cases()[0]->value ?? '');
        if ($integers && $coerce && is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $value = (int) $value;
        }
        $case = ($integers ? is_int($value) : is_string($value)) ? $class::tryFrom($value) : null;
        if ($case === null) {
            $errors[$field][] = 'must be one of: ' . implode(', ', array_column($class::cases(), 'value'));
        }
        return $case;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function date(Property $property, mixed $value, string $field, array &$errors): ?\DateTimeInterface
    {
        if (is_string($value) && $value !== '') {
            try {
                return new ($property->class)($value);
            } catch (\Exception) {
                // Reported below, with what was expected.
            }
        }
        $errors[$field][] = 'must be a date and time, such as 2026-01-31T12:00:00Z';
        return null;
    }

    /**
     * @param array<string, list<string>> $errors
     * @return array<mixed>|null
     */
    private function list(Property $property, mixed $value, bool $coerce, string $field, array &$errors): ?array
    {
        if (!is_array($value)) {
            $errors[$field][] = $property->items === null ? 'must be a list or an object' : 'must be a list';
            return null;
        }
        if ($property->items === null) {
            return $value;
        }
        if (!array_is_list($value)) {
            $errors[$field][] = 'must be a list';
            return null;
        }
        $items = [];
        foreach ($value as $index => $item) {
            $items[] = $this->value($property->items, $item, $coerce, $field . '.' . $index, $errors);
        }
        return $items;
    }

    /**
     * @param class-string $class
     * @param list<class-string> $inside The classes this one is nested in, to refuse one that contains itself.
     * @return array<string, mixed>
     */
    private function objectSchema(string $class, array $inside): array
    {
        if (in_array($class, $inside, true)) {
            throw new \LogicException("{$class} contains itself, which cannot be written as one JSON Schema.");
        }
        $inside[] = $class;
        return $this->propertiesSchema($this->properties($class), $inside);
    }

    /**
     * @param iterable<Property> $fields
     * @param list<class-string> $inside
     * @return array<string, mixed>
     */
    private function propertiesSchema(iterable $fields, array $inside): array
    {
        $properties = [];
        $required = [];
        foreach ($fields as $property) {
            $properties[$property->name] = $this->propertySchema($property, $inside);
            if ($property->required) {
                $required[] = $property->name;
            }
        }
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /**
     * @param list<class-string> $inside
     * @return array<string, mixed>
     */
    private function propertySchema(Property $property, array $inside): array
    {
        $schema = match ($property->type) {
            Property::INT => ['type' => 'integer'],
            Property::FLOAT => ['type' => 'number'],
            Property::STRING => ['type' => 'string'],
            Property::BOOL => ['type' => 'boolean'],
            Property::MIXED => [],
            Property::DATE => ['type' => 'string', 'format' => 'date-time'],
            Property::ENUM => $this->enumSchema($property->class),
            Property::OBJECT => $this->objectSchema($property->class, $inside),
            Property::ARRAY => $property->items === null
                ? ['type' => ['array', 'object']]
                : ['type' => 'array', 'items' => $this->propertySchema($property->items, $inside)],
        };
        $type = $schema['type'] ?? '';
        foreach ($property->rules as $rule) {
            $schema = [...$schema, ...$rule->schema(is_string($type) ? $type : 'array')];
        }
        if ($property->nullable && $type !== '') {
            $schema['type'] = [...(array) $type, 'null'];
        }
        if ($property->hasDefault && (is_scalar($property->default) || $property->default === null)) {
            $schema['default'] = $property->default;
        }
        return $schema;
    }

    /**
     * @param class-string<\BackedEnum> $class
     * @return array<string, mixed>
     */
    private function enumSchema(string $class): array
    {
        $values = array_column($class::cases(), 'value');
        return ['type' => is_int($values[0] ?? '') ? 'integer' : 'string', 'enum' => $values];
    }

    /**
     * @param class-string $class
     * @return list<Property>
     */
    private function properties(string $class): array
    {
        return $this->classes[$class] ??= self::read($class);
    }

    /**
     * @param list<string> $names
     * @return array<string, Property>
     */
    private function parameters(\ReflectionFunctionAbstract $function, array $names): array
    {
        if (!isset($this->functions[$function])) {
            $where = $function instanceof \ReflectionMethod ? "{$function->class}::{$function->name}()" : 'A closure';
            $properties = [];
            foreach ($function->getParameters() as $parameter) {
                if (in_array($parameter->getName(), $names, true)) {
                    $properties[$parameter->getName()] = self::property("{$where} takes \${$parameter->getName()}, which", $parameter);
                }
            }
            $this->functions[$function] = $properties;
        }
        return $this->functions[$function];
    }

    /**
     * @return list<Property>
     */
    private static function read(string $class): array
    {
        if (!class_exists($class)) {
            throw new \LogicException("{$class} cannot be validated: there is no such class.");
        }
        $reflection = new \ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new \LogicException("{$class} cannot be validated: it cannot be constructed.");
        }
        $properties = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $properties[] = self::property("{$class}::\${$parameter->getName()}", $parameter);
        }
        return $properties;
    }

    /**
     * @param string $where What to call the parameter in an error: "Class::$name".
     */
    private static function property(string $where, \ReflectionParameter $parameter): Property
    {
        $name = $parameter->getName();
        $declared = $parameter->getType();
        if ($declared !== null && !$declared instanceof \ReflectionNamedType) {
            throw new \LogicException("{$where} has a union or intersection type; give it one type so that it can be validated.");
        }
        if ($parameter->isVariadic()) {
            throw new \LogicException("{$where} is variadic; declare an array with #[ListOf(...)] instead.");
        }
        [$type, $typeClass] = self::type($declared?->getName() ?? 'mixed', $where);
        $items = null;
        $rules = [];
        foreach ($parameter->getAttributes() as $attribute) {
            $attributeClass = $attribute->getName();
            if ($attributeClass === ListOf::class) {
                if ($type !== Property::ARRAY) {
                    throw new \LogicException("{$where} has #[ListOf] but is not declared as an array.");
                }
                $of = $attribute->newInstance()->type;
                [$itemType, $itemClass] = self::type($of, "{$where}, by its #[ListOf]");
                $items = new Property($name, $itemType, $itemClass);
            } elseif (is_a($attributeClass, Rule::class, true)) {
                $rules[] = $attribute->newInstance();
            }
        }
        $hasDefault = $parameter->isDefaultValueAvailable();
        $nullable = $declared === null || $declared->allowsNull();
        return new Property(
            $name,
            $type,
            $typeClass,
            $nullable,
            !$hasDefault && !$nullable,
            $hasDefault,
            $hasDefault ? $parameter->getDefaultValue() : null,
            $rules,
            $items,
        );
    }

    /**
     * @return array{string, class-string|null} The Property type for a declared type name, and its class when it has one.
     */
    private static function type(string $name, string $where): array
    {
        if (in_array($name, [Property::INT, Property::FLOAT, Property::STRING, Property::BOOL, Property::ARRAY, Property::MIXED], true)) {
            return [$name, null];
        }
        if ($name === \DateTimeInterface::class || $name === \DateTimeImmutable::class) {
            return [Property::DATE, \DateTimeImmutable::class];
        }
        if (enum_exists($name)) {
            if (!is_subclass_of($name, \BackedEnum::class)) {
                throw new \LogicException("{$where} is the enum {$name}, which has no values; give its cases values so that data can name one.");
            }
            return [Property::ENUM, $name];
        }
        // A class of PHP's own, such as Closure or PDO, is not data.
        if (class_exists($name) && !(new \ReflectionClass($name))->isInternal()) {
            return [Property::OBJECT, $name];
        }
        throw new \LogicException("{$where} is declared as {$name}, which cannot be validated; use int, float, string, bool, array, an enum, DateTimeImmutable or a class.");
    }
}
