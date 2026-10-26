<?php

declare(strict_types=1);

namespace Crab\Validation;

use Crab\Contracts\Validation\Rule;

/**
 * One constructor parameter of a validated class, as read from its
 * declaration.
 */
final readonly class Property
{
    public const INT = 'int';
    public const FLOAT = 'float';
    public const STRING = 'string';
    public const BOOL = 'bool';
    public const ARRAY = 'array';
    public const MIXED = 'mixed';
    public const ENUM = 'enum';
    public const DATE = 'date';
    public const OBJECT = 'object';
    /**
     * An object described field by field with #[Fields], which becomes a Data.
     */
    public const FIELDS = 'fields';
    /**
     * A file the request carries, as a PSR-7 uploaded file.
     */
    public const FILE = 'file';

    /**
     * @param string $type One of the constants above.
     * @param class-string|null $class The class of an enum, a date or a nested object.
     * @param bool $required Whether the data must hold it: neither nullable nor given a default.
     * @param bool $hasDefault Whether the constructor supplies a value when it is absent.
     * @param list<Rule> $rules
     * @param Property|null $items What each element of a list is, when the parameter says so with ListOf.
     * @param list<Property>|null $fields The fields of an object described with Fields.
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?string $class = null,
        public bool $nullable = false,
        public bool $required = true,
        public bool $hasDefault = false,
        public mixed $default = null,
        public array $rules = [],
        public ?Property $items = null,
        public ?array $fields = null,
    ) {}
}
