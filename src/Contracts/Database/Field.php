<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

/**
 * One declared property and the conversion between it and its column.
 * No connection or component is needed to read or write a value.
 */
final readonly class Field
{
    /**
     * How a date is written: what both databases read as a date and time.
     */
    private const DATE_FORMAT = 'Y-m-d H:i:s';
    /**
     * The forms a stored date is read from, tried in turn.
     */
    private const DATE_FORMS = ['Y-m-d H:i:s', 'Y-m-d H:i:s.u', '!Y-m-d', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP'];

    public const INT = 'int';
    public const FLOAT = 'float';
    public const STRING = 'string';
    public const BOOL = 'bool';
    public const MIXED = 'mixed';
    public const ENUM = 'enum';
    public const DATE = 'date';
    public const CAST = 'cast';

    private function __construct(
        private string $class,
        public string $property,
        public string $column,
        public string $type,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $default = null,
        public ?string $enum = null,
        public ?Cast $cast = null,
    ) {}

    public static function of(
        \ReflectionProperty $property,
        string $column,
        bool $hasDefault,
        mixed $default = null,
        string $kind = 'record',
    ): self {
        $class = $property->getDeclaringClass()->getName();
        $name = $property->getName();
        $where = "{$class}::\${$name}";
        $cast = null;
        foreach ($property->getAttributes(Cast::class, \ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            if ($cast !== null) {
                throw new \LogicException("{$where} has two casts; give it one.");
            }
            // A promoted parameter also has property attributes in reflection.
            // Keep parameter-only casts written before property casts were supported.
            $flags = new \ReflectionClass($attribute->getName())->getAttributes(
                \Attribute::class,
            )[0]->newInstance()->flags;
            if ($property->isPromoted() && !($flags & \Attribute::TARGET_PROPERTY)) {
                $parameter = new \ReflectionParameter([$class, '__construct'], $name);
                $attribute = $parameter->getAttributes($attribute->getName())[0];
            }
            $cast = $attribute->newInstance();
        }
        $declared = $property->getType();
        $nullable = $declared === null || $declared->allowsNull();
        if ($cast !== null) {
            return new self($class, $name, $column, self::CAST, $nullable, $hasDefault, $default, cast: $cast);
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
        $typeKind = match (true) {
            in_array($type, ['int', 'float', 'string', 'bool', 'mixed'], true) => $type,
            enum_exists($type) && is_subclass_of($type, \BackedEnum::class) => self::ENUM,
            in_array($type, [\DateTimeImmutable::class, \DateTimeInterface::class], true) => self::DATE,
            $type === 'array' => throw new \LogicException(
                "{$where} is an array, which a column does not hold by itself; mark a JSON column #[Json].",
            ),
            $type === \DateTime::class => throw new \LogicException(
                "{$where} is a DateTime; declare it DateTimeImmutable, as a {$kind} does not change.",
            ),
            default => throw new \LogicException(
                "{$where} is {$type}, which a column cannot be read as by itself; give it a cast, an attribute implementing "
                . Cast::class
                . '.',
            ),
        };
        if ($typeKind === self::ENUM) {
            $enum = $type;
        }
        return new self($class, $name, $column, $typeKind, $nullable, $hasDefault, $default, $enum);
    }

    public function fromColumn(mixed $value): mixed
    {
        if ($value === null) {
            if ($this->nullable || $this->type === self::MIXED) {
                return null;
            }
            throw new \UnexpectedValueException(
                "{$this->class}::\${$this->property} cannot be null, and the column \"{$this->column}\" is null. Declare it nullable or select a value.",
            );
        }
        $read = match ($this->type) {
            self::MIXED => $value,
            self::STRING => is_string($value) || is_int($value) || is_float($value) ? (string) $value : null,
            self::INT => self::integer($value),
            self::FLOAT => self::float($value),
            self::BOOL => match ($value) {
                true, 1, '1' => true,
                false, 0, '0' => false,
                default => null,
            },
            self::ENUM => self::enum((string) $this->enum, $value),
            self::DATE => is_string($value) ? self::date($value) : null,
            default => $this->castValue($value),
        };
        if ($read === null) {
            throw new \UnexpectedValueException(
                "{$this->class}::\${$this->property} takes "
                . ($this->enum ?? $this->type)
                . ", and the column \"{$this->column}\" held "
                . self::described($value)
                . ' that is not one.',
            );
        }
        return $read;
    }

    private function castValue(mixed $value): mixed
    {
        try {
            return ($this->cast ?? throw new \LogicException('A cast field has no cast.'))->read($value);
        } catch (\UnexpectedValueException $error) {
            throw new \UnexpectedValueException(
                "{$this->class}::\${$this->property} could not be read from the column \"{$this->column}\": {$error->getMessage()}.",
                previous: $error,
            );
        }
    }

    public function toColumn(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match (true) {
            $this->cast !== null => $this->cast->write($value),
            $value instanceof \BackedEnum => $value->value,
            // In the zone dates are read in, so that a date written is the date read back.
            $value instanceof \DateTimeInterface => \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format(self::DATE_FORMAT),
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
}
