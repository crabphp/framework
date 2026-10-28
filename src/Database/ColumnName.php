<?php

declare(strict_types=1);

namespace Crab\Database;

/**
 * The column a record's property is read from and written to, when its
 * name is not the property's:
 *
 *     public function __construct(#[ColumnName('created_at')] public \DateTimeImmutable $createdAt) {}
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class ColumnName
{
    public function __construct(
        public string $name,
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new \InvalidArgumentException(
                "\"{$name}\" cannot be a column's name: use letters, digits and \"_\", as in #[ColumnName('created_at')].",
            );
        }
    }
}
