<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Column as ColumnContract;

/**
 * What a migration said about one column. It holds no SQL; a
 * SchemaGrammar writes it.
 */
final class Column implements ColumnContract
{
    private const ON_DELETE = ['cascade', 'set null', 'restrict', 'no action'];

    public private(set) bool $nullable = false;
    public private(set) bool $hasDefault = false;
    public private(set) string|int|float|bool|null $default = null;
    public private(set) bool $useCurrent = false;
    public private(set) bool $unsigned = false;
    public private(set) bool $unique = false;
    public private(set) bool $index = false;
    /**
     * The table and column this one points at, and what deleting that row does.
     *
     * @var array{table: string, column: string, onDelete: string|null}|null
     */
    public private(set) ?array $references = null;

    /**
     * @param string $type The Table method that made it: "string", "integer", "id", ...
     * @param int $length The characters of a string, or the digits of a decimal.
     * @param int $places The digits of a decimal after the point.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int $length = 0,
        public readonly int $places = 0,
    ) {}

    public function nullable(): static
    {
        $this->nullable = true;
        return $this;
    }

    public function default(string|int|float|bool|null $value): static
    {
        $this->hasDefault = true;
        $this->default = $value;
        return $this;
    }

    public function useCurrent(): static
    {
        $this->useCurrent = true;
        return $this;
    }

    public function unsigned(): static
    {
        $this->unsigned = true;
        return $this;
    }

    public function unique(): static
    {
        $this->unique = true;
        return $this;
    }

    public function index(): static
    {
        $this->index = true;
        return $this;
    }

    public function references(string $table, string $column = 'id', ?string $onDelete = null): static
    {
        if ($onDelete !== null) {
            $onDelete = strtolower($onDelete);
            if (!in_array($onDelete, self::ON_DELETE, true)) {
                throw new \InvalidArgumentException(
                    "\"{$onDelete}\" is not something a delete can do to \"{$this->name}\"; use one of: "
                    . implode(', ', self::ON_DELETE)
                    . '.',
                );
            }
        }
        $this->references = ['table' => $table, 'column' => $column, 'onDelete' => $onDelete];
        return $this;
    }
}
