<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Cast;

/**
 * One constructor parameter of a record class, as RecordMap read it: the
 * property, the column it maps to, and how a value crosses between them.
 *
 * @internal
 */
final readonly class RecordField
{
    public const INT = 'int';
    public const FLOAT = 'float';
    public const STRING = 'string';
    public const BOOL = 'bool';
    public const MIXED = 'mixed';
    public const ENUM = 'enum';
    public const DATE = 'date';
    /**
     * A type read and written by the Cast on the parameter.
     */
    public const CAST = 'cast';

    /**
     * @param string $type One of the constants above.
     * @param class-string<\BackedEnum>|null $enum The enum of an ENUM field.
     */
    public function __construct(
        public string $property,
        public string $column,
        public string $type,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $default = null,
        public ?string $enum = null,
        public ?Cast $cast = null,
    ) {}
}
