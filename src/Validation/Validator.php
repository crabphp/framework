<?php

declare(strict_types=1);

namespace Crab\Validation;

use Crab\Contracts\Validation\ObjectRule;
use Crab\Contracts\Validation\Rule;
use Crab\Contracts\Validation\Validator as ValidatorContract;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Reads a class's constructor once and from then on builds objects of it
 * from loose data. A parameter's type says what is accepted:
 *
 *     int, float, string, bool   that value; a float also takes a whole number
 *     array                      any array, or with #[ListOf(...)] a list of that
 *     a backed enum              one of its values
 *     DateTimeImmutable          a date, or a date and time, as ISO 8601 text
 *     another class              an object, validated the same way
 *     UploadedFileInterface      a file the data holds, received whole
 *     bool|int|string            one of these scalar types, told by the value
 *     ?type                      also null
 *
 * A parameter with a default may be left out; one without must be sent,
 * even when it may be null, as PHP would have it passed. Fields the class
 * does not name are ignored, unless it is marked #[RejectUnknown]. A rule
 * on the class, an ObjectRule, checks the object once its fields are valid.
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
     * Whether each class refuses fields it does not declare, and the rules
     * on it as a whole.
     *
     * @var array<class-string, array{bool, list<ObjectRule>}>
     */
    private array $shapes = [];
    /**
     * The same for each function whose parameters were validated, by the
     * names of the parameters read.
     *
     * @var \WeakMap<\ReflectionFunctionAbstract, array<string, array<string, Property>>>
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

    public function inspect(string $class): void
    {
        $properties = $this->properties($class);
        $this->shape($class);
        $seen = [$class => true];
        $this->inspectAll($properties, $seen);
    }

    public function validateParameters(
        \ReflectionFunctionAbstract $function,
        array $names,
        array $data,
        bool $coerce = false,
    ): array {
        $errors = [];
        $values = $this->values($this->parameters($function, $names), $data, $coerce, '', $errors);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $values;
    }

    public function inspectParameters(\ReflectionFunctionAbstract $function, array $names): void
    {
        $seen = [];
        $this->inspectAll($this->parameters($function, $names), $seen);
    }

    public function schema(string $class): array
    {
        return new JsonSchema($this->properties(...), $this->shape(...))->ofClass($class);
    }

    public function parameterSchema(\ReflectionFunctionAbstract $function, array $names): array
    {
        return new JsonSchema($this->properties(...), $this->shape(...))->object($this->parameters($function, $names));
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
        $properties = $this->properties($class);
        [$strict, $rules] = $this->shape($class);
        $arguments = $this->values($properties, $data, $coerce, $path, $errors);
        if ($strict) {
            self::unknown($properties, $data, $path, $errors);
        }
        if (count($errors) > $found) {
            return null;
        }
        $object = new $class(...$arguments);
        return self::checked($object, $rules, $path, $errors) ? $object : null;
    }

    /**
     * Reports each field the data holds that is not one of the properties.
     *
     * @param iterable<Property> $properties
     * @param array<mixed> $data
     * @param array<string, list<string>> $errors
     */
    private static function unknown(iterable $properties, array $data, string $path, array &$errors): void
    {
        $known = [];
        foreach ($properties as $property) {
            $known[$property->name] = true;
        }
        foreach (array_keys($data) as $key) {
            if (isset($known[(string) $key])) {
                continue;
            }
            $errors[$path . $key][] = 'is not expected';
        }
    }

    /**
     * Runs the rules on an object as a whole, and reports what they find.
     *
     * @param list<ObjectRule> $rules
     * @param array<string, list<string>> $errors
     * @return bool Whether it passed them all.
     */
    private static function checked(object $object, array $rules, string $path, array &$errors): bool
    {
        $passed = true;
        foreach ($rules as $rule) {
            foreach ($rule->check($object) as $field => $problem) {
                if (!is_string($field) || $field === '' || !is_string($problem)) {
                    throw new \LogicException(
                        $rule::class
                        . '::check() must return what is wrong by the name of a field, as in [\'endsAt\' => \'must be after the start\'].',
                    );
                }
                $errors[$path . $field][] = $problem;
                $passed = false;
            }
        }
        return $passed;
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
            // A form's file input left empty is sent as a file that is not there.
            $sent =
                array_key_exists($property->name, $data)
                && !(
                    $data[$property->name] instanceof UploadedFileInterface
                    && $data[$property->name]->getError() === UPLOAD_ERR_NO_FILE
                );
            if (!$sent) {
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
        $text = $property->type === Property::STRING || in_array(Property::STRING, $property->types, true);
        if ($coerce && $value === '' && $property->nullable && !$text) {
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
            if ($rule instanceof Length && $property->type === Property::ARRAY && $property->items !== null) {
                // A typed list's size was checked before its items were built.
                continue;
            }
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
            case Property::FIELDS:
                if (!is_array($value) || $value !== [] && array_is_list($value)) {
                    $errors[$field][] = 'must be an object';
                    return null;
                }
                $found = count($errors);
                $values = $this->values($property->fields ?? [], $value, $coerce, $field . '.', $errors);
                if ($property->strict) {
                    self::unknown($property->fields ?? [], $value, $field . '.', $errors);
                }
                if (count($errors) > $found) {
                    return null;
                }
                // Only what was sent is kept, so that a field left out can be told from one sent as null.
                $data = new Data(
                    array_intersect_key($values, $value),
                    array_map(static fn(Property $described): string => $described->name, $property->fields ?? []),
                );
                return self::checked($data, $property->objectRules, $field . '.', $errors) ? $data : null;
            case Property::UNION:
                return self::union($property, $value, $coerce, $field, $errors);
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
                if ($coerce && is_string($value)) {
                    $integer = self::integer($value);
                    if ($integer !== null) {
                        return $integer;
                    }
                }
                $errors[$field][] = 'must be a whole number';
                return null;
            case Property::FLOAT:
                if (is_int($value) || is_float($value) || $coerce && is_string($value) && is_numeric($value)) {
                    $number = (float) $value;
                    if (is_finite($number)) {
                        return $number;
                    }
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
                if (!is_array($value) || $value !== [] && array_is_list($value)) {
                    $errors[$field][] = 'must be an object';
                    return null;
                }
                return $this->build($property->class, $value, $coerce, $field . '.', $errors);
            case Property::ARRAY:
                return $this->list($property, $value, $coerce, $field, $errors);
            case Property::FILE:
                return self::file($value, $field, $errors);
        }
        throw new \LogicException("A property has the unknown type \"{$property->type}\".");
    }

    /**
     * A value of one of a union's scalar types. JSON says which; text says
     * nothing, so it stays text where text is one of them, and is otherwise
     * read as the first of int, float and bool it can be.
     *
     * @param array<string, list<string>> $errors
     */
    private static function union(
        Property $property,
        mixed $value,
        bool $coerce,
        string $field,
        array &$errors,
    ): int|float|bool|string|null {
        $types = $property->types;
        if ($coerce && is_string($value)) {
            if (in_array(Property::STRING, $types, true)) {
                return $value;
            }
            foreach ($types as $type) {
                $read = match ($type) {
                    Property::INT => self::integer($value),
                    Property::FLOAT => is_numeric($value) && is_finite((float) $value) ? (float) $value : null,
                    default => in_array($value, ['true', '1', 'false', '0'], true)
                        ? $value === 'true' || $value === '1'
                        : null,
                };
                if ($read !== null) {
                    return $read;
                }
            }
        } elseif (is_int($value) && in_array(Property::INT, $types, true)) {
            return $value;
        } elseif (
            (is_int($value) || is_float($value))
            && in_array(Property::FLOAT, $types, true)
            && is_finite((float) $value)
        ) {
            return (float) $value;
        } elseif (is_bool($value) && in_array(Property::BOOL, $types, true)) {
            return $value;
        } elseif (is_string($value) && in_array(Property::STRING, $types, true)) {
            return $value;
        }
        $words = array_map(static fn(string $type): string => match ($type) {
            Property::INT => 'a whole number',
            Property::FLOAT => 'a number',
            Property::BOOL => 'true or false',
            default => 'text',
        }, $types);
        $last = array_pop($words);
        $errors[$field][] = 'must be ' . implode(', ', $words) . ' or ' . $last;
        return null;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private static function file(mixed $value, string $field, array &$errors): ?UploadedFileInterface
    {
        if (!$value instanceof UploadedFileInterface) {
            $errors[$field][] = 'must be a file, sent as multipart/form-data';
            return null;
        }
        $problem = match ($value->getError()) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'is larger than the server accepts',
            UPLOAD_ERR_PARTIAL => 'was not received whole',
            UPLOAD_ERR_NO_FILE => 'is required',
            default => 'could not be received',
        };
        if ($problem !== null) {
            $errors[$field][] = $problem;
            return null;
        }
        return $value;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function enum(Property $property, mixed $value, bool $coerce, string $field, array &$errors): ?\BackedEnum
    {
        $class = $property->class;
        $integers = is_int($class::cases()[0]->value ?? '');
        if ($integers && $coerce && is_string($value)) {
            $value = self::integer($value);
        }
        $validType = $integers ? is_int($value) : is_string($value);
        $case = $validType ? $class::tryFrom($value) : null;
        if ($case === null) {
            $errors[$field][] = 'must be one of: ' . implode(', ', array_column($class::cases(), 'value'));
        }
        return $case;
    }

    /**
     * Only accept text whose number survives conversion, for integers and enum backing values alike.
     */
    private static function integer(string $value): ?int
    {
        if (preg_match('/^-?\d+$/D', $value) !== 1) {
            return null;
        }
        $integer = (int) $value;
        return (string) $integer === preg_replace('/^(-?)0+(?=\d)/', '$1', $value) ? $integer : null;
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function date(Property $property, mixed $value, string $field, array &$errors): ?\DateTimeInterface
    {
        // A calendar date, or the guide's ISO 8601 timestamp with seconds and an offset; never a relative phrase.
        $format = match (true) {
            !is_string($value) => null,
            preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1 => '!Y-m-d',
            preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value) === 1
                => '!' . DATE_ATOM,
            default => null,
        };
        if ($format !== null) {
            $class = $property->class;
            $date = $class::createFromFormat($format, $value);
            // PHP reads February 31st as March 3rd and only notes that it did; such a date is refused.
            $doubts = \DateTimeImmutable::getLastErrors();
            if (
                $date !== false
                && ($doubts === false || $doubts['warning_count'] === 0 && $doubts['error_count'] === 0)
            ) {
                return $date;
            }
        }
        $errors[$field][] = 'must be a date or a date and time, such as 2026-01-31 or 2026-01-31T12:00:00Z';
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
        if ($property->items->type === Property::FILE) {
            // A form's multiple file input left empty sends one file that is not there: no files.
            $value = array_values(array_filter(
                $value,
                static fn(mixed $item): bool => !(
                    $item instanceof UploadedFileInterface
                    && $item->getError() === UPLOAD_ERR_NO_FILE
                ),
            ));
        }
        foreach ($property->rules as $rule) {
            if (!$rule instanceof Length) {
                continue;
            }
            $problem = $rule->check($value);
            if ($problem === null) {
                continue;
            }
            // Reject the list before constructing items or allocating one error per item.
            $errors[$field][] = $problem;
            return null;
        }
        $items = [];
        foreach ($value as $index => $item) {
            $items[] = $this->value($property->items, $item, $coerce, $field . '.' . $index, $errors);
        }
        return $items;
    }

    /**
     * Reads the class of every nested object, however deep.
     *
     * @param iterable<Property> $properties
     * @param array<class-string, true> $seen Classes already read, since a class may contain itself.
     */
    private function inspectAll(iterable $properties, array &$seen): void
    {
        foreach ($properties as $property) {
            // A list is inspected as what it holds.
            while ($property->items !== null) {
                $property = $property->items;
            }
            if ($property->type === Property::FIELDS) {
                $this->inspectAll($property->fields ?? [], $seen);
            }
            if ($property->type === Property::OBJECT && !isset($seen[$property->class])) {
                $seen[$property->class] = true;
                $properties = $this->properties($property->class);
                $this->shape($property->class);
                $this->inspectAll($properties, $seen);
            }
        }
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
     * Whether a class refuses fields it does not declare, and the rules on it as a whole.
     *
     * @param class-string $class
     * @return array{bool, list<ObjectRule>}
     */
    private function shape(string $class): array
    {
        if (isset($this->shapes[$class])) {
            return $this->shapes[$class];
        }
        $reflection = new \ReflectionClass($class);
        $rules = [];
        foreach ($reflection->getAttributes(ObjectRule::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $rules[] = $attribute->newInstance();
        }
        return $this->shapes[$class] = [$reflection->getAttributes(RejectUnknown::class) !== [], $rules];
    }

    /**
     * @param list<string> $names
     * @return array<string, Property>
     */
    private function parameters(\ReflectionFunctionAbstract $function, array $names): array
    {
        $key = implode(',', $names);
        if (!isset($this->functions[$function][$key])) {
            $where = $function instanceof \ReflectionMethod ? "{$function->class}::{$function->name}()" : 'A closure';
            $properties = [];
            foreach ($function->getParameters() as $parameter) {
                if (!in_array($parameter->getName(), $names, true)) {
                    continue;
                }

                $properties[$parameter->getName()] = self::property(
                    "{$where} takes \${$parameter->getName()}, which",
                    $parameter,
                );
            }
            $this->functions[$function] = [...($this->functions[$function] ?? []), $key => $properties];
        }
        return $this->functions[$function][$key];
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
        if ($declared === null) {
            throw new \LogicException("{$where} has no type; declare it with one type, such as string \${$name}.");
        }
        $union = [];
        if ($declared instanceof \ReflectionUnionType) {
            $union = self::unionTypes(array_map(static fn(\ReflectionType $member): string => $member
                instanceof \ReflectionNamedType
                    ? $member->getName()
                    : (string) $member, $declared->getTypes()), $where);
        } elseif (!$declared instanceof \ReflectionNamedType) {
            throw new \LogicException(
                "{$where} has an intersection type; give it one type so that it can be validated.",
            );
        }
        if ($parameter->isVariadic()) {
            throw new \LogicException("{$where} is variadic; declare an array with #[ListOf(...)] instead.");
        }
        $described = $declared instanceof \ReflectionNamedType && $declared->getName() === Data::class;
        [$type, $typeClass] = match (true) {
            $union !== [] => [Property::UNION, null],
            $described => [Property::FIELDS, null],
            default => self::type($declared->getName(), $where),
        };
        $items = null;
        $description = null;
        $strict = false;
        $objectRules = [];
        $rules = [];
        foreach ($parameter->getAttributes() as $attribute) {
            $attributeClass = $attribute->getName();
            if (!class_exists($attributeClass)) {
                throw new \LogicException(
                    "{$where} has an unknown attribute #[{$attributeClass}]; check its spelling and import the attribute class.",
                );
            }
            if ($attributeClass === ListOf::class) {
                if ($type !== Property::ARRAY) {
                    throw new \LogicException("{$where} has #[ListOf] but is not declared as an array.");
                }
                $of = $attribute->newInstance()->type;
                if (is_array($of)) {
                    // The fields of each item, described in place of a class.
                    $items = new Property(
                        $name,
                        Property::FIELDS,
                        fields: self::described($of, "{$where}, by its #[ListOf]"),
                    );
                } else {
                    [$itemType, $itemClass] = self::type($of, "{$where}, by its #[ListOf]");
                    $items = new Property($name, $itemType, $itemClass);
                }
            } elseif ($attributeClass === Fields::class) {
                if (!$described) {
                    throw new \LogicException(
                        "{$where} has #[Fields] but is not declared as "
                        . Data::class
                        . ', which is what the described data arrives as.',
                    );
                }
                $description = $attribute->newInstance()->fields;
            } elseif ($attributeClass === RejectUnknown::class || is_a($attributeClass, ObjectRule::class, true)) {
                if (!$described) {
                    $shown = $attributeClass === RejectUnknown::class ? 'RejectUnknown' : $attributeClass;
                    throw new \LogicException(
                        "{$where} has #[{$shown}], which is for an object: put it on the class, or on a "
                        . Data::class
                        . ' described with #[Fields].',
                    );
                }
                if ($attributeClass === RejectUnknown::class) {
                    $strict = true;
                } else {
                    $objectRules[] = $attribute->newInstance();
                }
            } elseif (is_a($attributeClass, Rule::class, true)) {
                $rules[] = $attribute->newInstance();
            }
        }
        $fields = $description === null ? null : self::described($description, "{$where}, by its #[Fields]", $strict);
        if ($items?->type === Property::FILE) {
            // A file's rules on a list of files are each file's: #[MediaType('image/png')] array $photos.
            $own = static fn(Rule $rule): bool => $rule instanceof FileSize || $rule instanceof MediaType;
            $items = new Property($name, Property::FILE, rules: array_values(array_filter($rules, $own)));
            $rules = array_values(array_filter($rules, static fn(Rule $rule): bool => !$own($rule)));
        }
        if ($described && $fields === null) {
            throw new \LogicException(
                "{$where} is declared as " . Data::class . ' and has no #[Fields] to say what its fields are.',
            );
        }
        $hasDefault = $parameter->isDefaultValueAvailable();
        // As PHP has it: a parameter without a default is passed, even one that may be null.
        return new Property(
            $name,
            $type,
            $typeClass,
            $declared->allowsNull(),
            !$hasDefault,
            $hasDefault,
            $hasDefault ? $parameter->getDefaultValue() : null,
            $rules,
            $items,
            $fields,
            $union,
            $strict,
            $objectRules,
        );
    }

    /**
     * The fields a #[Fields] or a #[ListOf] describes, as properties.
     *
     * @param array<mixed> $description Each field's name with its description; see Fields.
     * @param bool $strict Whether each object described refuses a field it does not describe.
     * @return list<Property>
     */
    private static function described(array $description, string $where, bool $strict = false): array
    {
        if ($description === [] || array_is_list($description)) {
            throw new \LogicException("{$where} must name each field with its description, as in ['id' => 'int'].");
        }
        $fields = [];
        foreach ($description as $name => $spec) {
            $name = (string) $name;
            // A name ending in "?" is a field that may be left out.
            $optional = str_ends_with($name, '?');
            $name = $optional ? substr($name, 0, -1) : $name;
            $fields[] = self::field($name, $spec, $optional, "{$where} describes \"{$name}\", which", $strict);
        }
        return $fields;
    }

    /**
     * One described field: a type, a type with rules, an object or a list.
     */
    private static function field(string $name, mixed $spec, bool $optional, string $where, bool $strict): Property
    {
        if (is_string($spec)) {
            $spec = [$spec];
        }
        if (!is_array($spec) || $spec === []) {
            throw new \LogicException(
                "{$where} must be a type such as 'int', a type with rules, or the fields of an object.",
            );
        }
        if (!array_is_list($spec)) {
            // Names with descriptions: an object.
            return new Property(
                $name,
                Property::FIELDS,
                required: !$optional,
                fields: self::described($spec, $where, $strict),
                strict: $strict,
            );
        }
        $rules = array_slice($spec, 1);
        foreach ($rules as $rule) {
            if (!$rule instanceof Rule) {
                throw new \LogicException(
                    "{$where} is followed by "
                    . get_debug_type($rule)
                    . ', where only rules such as new Range(min: 1) may follow.',
                );
            }
        }
        if (is_array($spec[0])) {
            // A description inside a list: a list of that, and the rules are the list's.
            $items = self::field($name, $spec[0], false, "{$where}, in each item,", $strict);
            return new Property($name, Property::ARRAY, required: !$optional, rules: $rules, items: $items);
        }
        if (!is_string($spec[0])) {
            throw new \LogicException("{$where} must start with a type such as 'int'.");
        }
        // '?int' may be null; a name ending in "?" may be left out. The two are apart, as in PHP.
        $members = explode('|', ltrim($spec[0], '?'));
        $nullable = str_starts_with($spec[0], '?') || in_array('null', $members, true);
        $members = array_values(array_diff($members, ['null']));
        if (count($members) > 1) {
            return new Property(
                $name,
                Property::UNION,
                nullable: $nullable,
                required: !$optional,
                rules: $rules,
                types: self::unionTypes($members, $where),
            );
        }
        [$type, $class] = self::type($members[0] ?? '', $where);
        return new Property($name, $type, $class, $nullable, !$optional, rules: $rules);
    }

    /**
     * The scalar types of a union, in the order text is tried against them.
     *
     * @param list<string> $members The union's types, as declared; "null" among them says only that it may be null.
     * @return list<string>
     */
    private static function unionTypes(array $members, string $where): array
    {
        $members = array_values(array_diff($members, ['null']));
        $order = [Property::INT, Property::FLOAT, Property::BOOL, Property::STRING];
        $unknown = array_diff($members, $order);
        if ($unknown !== [] || count($members) < 2) {
            throw new \LogicException(
                "{$where} is declared as "
                . implode('|', $members)
                . ', and a union can be validated only when it is of the scalar types int, float, bool and string; give it one type, or a class with a field for each kind.',
            );
        }
        return array_values(array_intersect($order, $members));
    }

    /**
     * @return array{string, class-string|null} The Property type for a declared type name, and its class when it has one.
     */
    private static function type(string $name, string $where): array
    {
        if (in_array(
            $name,
            [Property::INT, Property::FLOAT, Property::STRING, Property::BOOL, Property::ARRAY, Property::MIXED],
            true,
        )) {
            return [$name, null];
        }
        if ($name === \DateTimeInterface::class || $name === \DateTimeImmutable::class) {
            return [Property::DATE, \DateTimeImmutable::class];
        }
        if ($name === UploadedFileInterface::class) {
            return [Property::FILE, null];
        }
        if (enum_exists($name)) {
            if (!is_subclass_of($name, \BackedEnum::class)) {
                throw new \LogicException(
                    "{$where} is the enum {$name}, which has no values; give its cases values so that data can name one.",
                );
            }
            return [Property::ENUM, $name];
        }
        // A class of PHP's own, such as Closure or PDO, is not data.
        if (class_exists($name) && !new \ReflectionClass($name)->isInternal()) {
            return [Property::OBJECT, $name];
        }
        throw new \LogicException(
            "{$where} is declared as {$name}, which cannot be validated; use int, float, string, bool, array, an enum, DateTimeImmutable, "
            . UploadedFileInterface::class
            . ' or a class.',
        );
    }
}
