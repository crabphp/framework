<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Field;

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
     * Each class read so far. Derived from code alone, so one per process serves every worker and request.
     *
     * @var array<class-string, self>
     */
    private static array $read = [];

    /**
     * @param class-string $class
     * @param list<Field> $fields
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
        return array_map(static fn(Field $field): string => $field->column, $this->fields);
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
            $arguments[$field->property] = $field->fromColumn($row[$field->column]);
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
            $values[$field->column] = $field->toColumn($record->{$field->property});
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
            $values[$field->column] = $field->toColumn($values[$field->column]);
        }
        return $values;
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

    private static function field(string $class, \ReflectionParameter $parameter): Field
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
        $attributes = $parameter->getAttributes(ColumnName::class);
        $column = $attributes === [] ? $name : $attributes[0]->newInstance()->name;
        $hasDefault = $parameter->isDefaultValueAvailable();
        return Field::of(
            new \ReflectionProperty($class, $name),
            $column,
            $hasDefault,
            $hasDefault ? $parameter->getDefaultValue() : null,
        );
    }
}
