<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Cast;

/**
 * How a record class maps to a table's columns, read once from its
 * constructor: each promoted public property is a column of the same
 * name, or of the name #[ColumnName] gives it, and its type says how the
 * value crosses. Query::into() reads rows into records with it, and the
 * writing methods turn records back into columns.
 *
 *     final readonly class Order
 *     {
 *         public function __construct(
 *             public int $id,
 *             public OrderStatus $status,
 *             #[Json] public array $meta,
 *             #[ColumnName('created_at')] public \DateTimeImmutable $createdAt,
 *         ) {}
 *     }
 *
 * A value is never quietly made another: text that is not a number does
 * not become 0, and a value an enum does not have is refused.
 */
final class RecordMap
{
    /**
     * How a date is written: what both databases read as a date and time.
     */
    private const DATE = 'Y-m-d H:i:s';
    /**
     * The forms a stored date is read from, tried in turn.
     */
    private const DATE_FORMS = ['Y-m-d H:i:s', 'Y-m-d H:i:s.u', '!Y-m-d', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'];

    /**
     * Each class read so far. Derived from code alone, so one per process serves every worker and request.
     *
     * @var array<class-string, self>
     */
    private static array $read = [];

    /**
     * @param class-string $class
     * @param list<RecordField> $fields
     */
    private function __construct(
        public readonly string $class,
        public readonly array $fields,
    ) {}

    /**
     * The map of a record class, read on first use; a class that cannot be
     * a record is refused with what to change.
     *
     * @param class-string|string $class
     */
    public static function of(string $class): self
    {
        return self::$read[$class] ??= self::inspect($class);
    }

    /**
     * @return list<string> The columns of the record, in the order of its constructor.
     */
    public function columns(): array
    {
        return array_map(static fn(RecordField $field): string => $field->column, $this->fields);
    }

    /**
     * A record made from a row read from the database.
     *
     * @param array<string, mixed> $row
     */
    public function make(array $row): object
    {
        $arguments = [];
        foreach ($this->fields as $field) {
            if (!array_key_exists($field->column, $row)) {
                if ($field->hasDefault) {
                    continue;
                }
                throw new \LogicException(
                    "{$this->class}::\${$field->property} reads the column \"{$field->column}\", which the query did not select. Select it, or give the parameter a default.",
                );
            }
            $arguments[$field->property] = $this->readValue($field, $row[$field->column]);
        }
        return new $this->class(...$arguments);
    }

    /**
     * A record of this class as the columns to write.
     *
     * @return array<string, mixed>
     */
    public function write(object $record): array
    {
        if (!$record instanceof $this->class) {
            throw new \InvalidArgumentException(
                'A record of ' . $record::class . " was given where one of {$this->class} was expected.",
            );
        }
        $values = [];
        foreach ($this->fields as $field) {
            $values[$field->column] = $this->writeValue($field, $record->{$field->property});
        }
        return $values;
    }

    /**
     * Values given as column to value, each written as the record's field
     * for that column would be; a column the record does not have is left as it is.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function writeValues(array $values): array
    {
        foreach ($this->fields as $field) {
            if (!array_key_exists($field->column, $values)) {
                continue;
            }
            $values[$field->column] = $this->writeValue($field, $values[$field->column]);
        }
        return $values;
    }

    private function readValue(RecordField $field, mixed $value): mixed
    {
        if ($value === null) {
            if ($field->nullable || $field->type === RecordField::MIXED) {
                return null;
            }
            throw new \UnexpectedValueException(
                "{$this->class}::\${$field->property} cannot be null, and the column \"{$field->column}\" is null. Declare it nullable or select a value.",
            );
        }
        $read = match ($field->type) {
            RecordField::MIXED => $value,
            RecordField::STRING => is_string($value) || is_int($value) || is_float($value) ? (string) $value : null,
            RecordField::INT => self::integer($value),
            RecordField::FLOAT => self::float($value),
            RecordField::BOOL => match ($value) {
                true, 1, '1' => true,
                false, 0, '0' => false,
                default => null,
            },
            RecordField::ENUM => self::enum((string) $field->enum, $value),
            RecordField::DATE => is_string($value) ? self::date($value) : null,
            default => $this->cast($field, $value),
        };
        if ($read === null) {
            throw new \UnexpectedValueException(
                "{$this->class}::\${$field->property} takes "
                . ($field->enum ?? $field->type)
                . ", and the column \"{$field->column}\" held "
                . self::described($value)
                . ' that is not one.',
            );
        }
        return $read;
    }

    private function cast(RecordField $field, mixed $value): mixed
    {
        try {
            return ($field->cast ?? throw new \LogicException('A cast field has no cast.'))->read($value);
        } catch (\UnexpectedValueException $error) {
            throw new \UnexpectedValueException(
                "{$this->class}::\${$field->property} could not be read from the column \"{$field->column}\": {$error->getMessage()}.",
                previous: $error,
            );
        }
    }

    private function writeValue(RecordField $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match (true) {
            $field->cast !== null => $field->cast->write($value),
            $value instanceof \BackedEnum => $value->value,
            // In the zone dates are read in, so that a date written is the date read back.
            $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format(self::DATE),
            default => $value,
        };
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        // Past what an integer holds, the number would come back changed.
        return $integer === false ? null : $integer;
    }

    private static function float(mixed $value): ?float
    {
        $float = match (true) {
            is_int($value), is_float($value) => (float) $value,
            is_string($value) && is_numeric($value) => (float) $value,
            default => null,
        };
        return $float !== null && is_finite($float) ? $float : null;
    }

    /**
     * @param class-string<\BackedEnum>|string $enum
     */
    private static function enum(string $enum, mixed $value): ?\BackedEnum
    {
        $backing = (string) new \ReflectionEnum($enum)->getBackingType();
        $value = match (true) {
            $backing === 'int' => self::integer($value),
            is_string($value) => $value,
            default => null,
        };
        return $value === null ? null : $enum::tryFrom($value);
    }

    private static function date(string $value): ?\DateTimeImmutable
    {
        foreach (self::DATE_FORMS as $form) {
            $date = \DateTimeImmutable::createFromFormat($form, $value);
            // A date the calendar does not have, 2026-02-30, is a warning here, not a failure.
            if ($date !== false && \DateTimeImmutable::getLastErrors() === false) {
                return $date;
            }
        }
        return null;
    }

    /**
     * What a value is, for an error: its type, never the value, which may be a secret.
     */
    private static function described(mixed $value): string
    {
        return match (true) {
            is_string($value) => is_numeric($value) ? 'a number as text' : 'text',
            is_int($value) => 'an int',
            is_float($value) => 'a float',
            is_bool($value) => 'a bool',
            default => get_debug_type($value),
        };
    }

    private static function inspect(string $class): self
    {
        if (!class_exists($class)) {
            throw new \LogicException("{$class} cannot be a record: there is no such class.");
        }
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if (!$reflection->isInstantiable() || $constructor === null || $constructor->getParameters() === []) {
            throw new \LogicException(
                "{$class} cannot be a record: it needs a public constructor that declares each column, public function __construct(public int \$id, ...).",
            );
        }
        $fields = [];
        $columns = [];
        foreach ($constructor->getParameters() as $parameter) {
            $field = self::field($class, $parameter);
            if (isset($columns[$field->column])) {
                throw new \LogicException(
                    "{$class} cannot be a record: \${$columns[$field->column]} and \${$field->property} both map to the column \"{$field->column}\".",
                );
            }
            $columns[$field->column] = $field->property;
            $fields[] = $field;
        }
        return new self($class, $fields);
    }

    private static function field(string $class, \ReflectionParameter $parameter): RecordField
    {
        $name = $parameter->getName();
        $where = "{$class}::\${$name}";
        if (!$parameter->isPromoted() || !new \ReflectionProperty($class, $name)->isPublic()) {
            throw new \LogicException(
                "{$where} must be a public property declared in the constructor, public function __construct(public int \${$name}), so that it can be read and written.",
            );
        }
        if ($parameter->isVariadic()) {
            throw new \LogicException("{$where} is variadic; a record has one property per column.");
        }
        $column = $name;
        $cast = null;
        foreach ($parameter->getAttributes() as $attribute) {
            $attributeClass = $attribute->getName();
            if ($attributeClass === ColumnName::class) {
                $column = $attribute->newInstance()->name;
            } elseif (is_a($attributeClass, Cast::class, true)) {
                if ($cast !== null) {
                    throw new \LogicException("{$where} has two casts; give it one.");
                }
                $cast = $attribute->newInstance();
            }
        }
        $declared = $parameter->getType();
        $nullable = $declared === null || $declared->allowsNull();
        $hasDefault = $parameter->isDefaultValueAvailable();
        $default = $hasDefault ? $parameter->getDefaultValue() : null;
        if ($cast !== null) {
            return new RecordField($name, $column, RecordField::CAST, $nullable, $hasDefault, $default, cast: $cast);
        }
        if (!$declared instanceof \ReflectionNamedType) {
            throw new \LogicException(
                $declared === null
                    ? "{$where} has no type; declare one, such as int \${$name}, so that its column can be read."
                    : "{$where} has a union or intersection type; give it one type, or a cast that reads it.",
            );
        }
        $type = $declared->getName();
        $enum = null;
        $kind = match (true) {
            in_array($type, ['int', 'float', 'string', 'bool', 'mixed'], true) => $type,
            enum_exists($type) && is_subclass_of($type, \BackedEnum::class) => RecordField::ENUM,
            in_array($type, [\DateTimeImmutable::class, \DateTimeInterface::class], true) => RecordField::DATE,
            $type === 'array' => throw new \LogicException(
                "{$where} is an array, which a column does not hold by itself; mark a JSON column #[Json].",
            ),
            $type === \DateTime::class => throw new \LogicException(
                "{$where} is a DateTime; declare it DateTimeImmutable, as a record does not change.",
            ),
            default => throw new \LogicException(
                "{$where} is {$type}, which a column cannot be read as by itself; give it a cast, an attribute implementing "
                . Cast::class
                . '.',
            ),
        };
        if ($kind === RecordField::ENUM) {
            $enum = $type;
        }
        return new RecordField($name, $column, $kind, $nullable, $hasDefault, $default, $enum);
    }
}
