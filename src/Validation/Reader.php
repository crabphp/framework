<?php

declare(strict_types=1);

namespace Crab\Validation;

use Crab\Contracts\Validation\ObjectRule;
use Crab\Contracts\Validation\Rule;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Reads what a class, or a function's parameters, take into the
 * Properties the Validator checks values against: each parameter's type,
 * its attributes, and the fields #[Fields] and #[ListOf] describe. A
 * mistake in the declaration is refused here, before any value is seen.
 *
 * @internal
 */
final class Reader
{
    /**
     * Whether a class refuses fields it does not declare, and the rules on it as a whole.
     *
     * @param class-string $class
     * @return array{bool, list<ObjectRule>}
     */
    public static function shape(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $rules = [];
        foreach ($reflection->getAttributes(ObjectRule::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            $rules[] = $attribute->newInstance();
        }
        return [$reflection->getAttributes(RejectUnknown::class) !== [], $rules];
    }

    /**
     * @return list<Property>
     */
    public static function read(string $class): array
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
    public static function property(string $where, \ReflectionParameter $parameter): Property
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
