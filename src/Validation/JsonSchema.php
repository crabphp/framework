<?php

declare(strict_types=1);

namespace Crab\Validation;

use Crab\Contracts\Validation\Rule;

/**
 * Writes what the Validator reads from a declaration as JSON Schema, so
 * that what a route takes can be read by a program as well as a person.
 * A rule JSON Schema has no word for is named under "x-rules".
 *
 * @internal
 */
final class JsonSchema
{
    /**
     * Classes being written, so that a class containing itself ends.
     *
     * @var array<class-string, true>
     */
    private array $writing = [];

    /**
     * @param \Closure(class-string): list<Property> $properties Reads a class's properties.
     */
    public function __construct(
        private readonly \Closure $properties,
    ) {}

    /**
     * @param iterable<Property> $properties
     * @return array<string, mixed>
     */
    public function object(iterable $properties): array
    {
        $schema = ['type' => 'object', 'properties' => []];
        $required = [];
        foreach ($properties as $property) {
            $schema['properties'][$property->name] = $this->property($property);
            if ($property->required) {
                $required[] = $property->name;
            }
        }
        if ($schema['properties'] === []) {
            $schema['properties'] = new \stdClass();
        }
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    public function property(Property $property): array
    {
        $schema = match ($property->type) {
            Property::INT => ['type' => 'integer'],
            Property::FLOAT => ['type' => 'number'],
            Property::STRING => ['type' => 'string'],
            Property::BOOL => ['type' => 'boolean'],
            Property::ARRAY => $property->items === null
                ? ['type' => ['array', 'object']]
                : ['type' => 'array', 'items' => $this->property($property->items)],
            Property::ENUM => self::enum((string) $property->class),
            Property::DATE => ['type' => 'string', 'format' => 'date-time'],
            Property::OBJECT => $this->ofClass((string) $property->class),
            Property::FIELDS => $this->object($property->fields ?? []),
            default => [],
        };
        $schema = [...$schema, ...self::rules($property->rules, $property->items !== null)];
        if ($property->nullable && isset($schema['type'])) {
            $schema['type'] = [...((array) $schema['type']), 'null'];
        }
        if ($property->hasDefault) {
            $schema['default'] = $property->default instanceof \BackedEnum
                ? $property->default->value
                : $property->default;
        }
        return $schema;
    }

    /**
     * What a rule says, in JSON Schema's words where it has them.
     *
     * @param list<Rule> $rules
     * @return array<string, mixed>
     */
    public static function rules(array $rules, bool $list = false): array
    {
        $schema = [];
        foreach ($rules as $rule) {
            $schema = [
                ...$schema,
                ...match (true) {
                    $rule instanceof Range => array_filter(
                        ['minimum' => $rule->min, 'maximum' => $rule->max],
                        static fn($limit): bool => $limit !== null,
                    ),
                    $rule instanceof Length => array_filter(
                        $list
                            ? ['minItems' => $rule->min, 'maxItems' => $rule->max]
                            : ['minLength' => $rule->min, 'maxLength' => $rule->max],
                        static fn($limit): bool => $limit !== null,
                    ),
                    $rule instanceof Email => ['format' => 'email'],
                    $rule instanceof Url => ['format' => 'uri'],
                    $rule instanceof Pattern => ['pattern' => $rule->expression],
                    $rule instanceof OneOf => ['enum' => $rule->values],
                    default => ['x-rules' => [...($schema['x-rules'] ?? []), $rule::class]],
                },
            ];
        }
        return $schema;
    }

    /**
     * @param class-string $class
     * @return array<string, mixed>
     */
    public function ofClass(string $class): array
    {
        if (isset($this->writing[$class])) {
            return ['type' => 'object', 'x-class' => $class];
        }
        $this->writing[$class] = true;
        try {
            return ['x-class' => $class, ...$this->object(($this->properties)($class))];
        } finally {
            unset($this->writing[$class]);
        }
    }

    /**
     * @param string $class A backed enum.
     * @return array<string, mixed>
     */
    private static function enum(string $class): array
    {
        $values = array_map(static fn(\BackedEnum $case): int|string => $case->value, $class::cases());
        return [
            'type' => is_int($values[0] ?? '') ? 'integer' : 'string',
            'enum' => $values,
            'x-class' => $class,
        ];
    }
}
