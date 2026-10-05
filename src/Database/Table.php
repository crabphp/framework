<?php

declare(strict_types=1);

namespace Crab\Database;

use Crab\Contracts\Database\Table as TableContract;

/**
 * What a migration said about one table: the columns and indexes to add
 * and, when changing a table, what to rename and drop. It holds no SQL; a
 * SchemaGrammar writes it.
 */
final class Table implements TableContract
{
    /**
     * @var list<Column>
     */
    public private(set) array $columns = [];
    /**
     * Indexes declared on the table itself; a column's own unique() and
     * index() are on the column.
     *
     * @var list<array{unique: bool, columns: list<string>, name: string}>
     */
    public private(set) array $indexes = [];
    /**
     * @var list<string>
     */
    public private(set) array $primary = [];
    /**
     * @var list<string>
     */
    public private(set) array $droppedColumns = [];
    /**
     * @var array<string, string> New names by old name.
     */
    public private(set) array $renamedColumns = [];
    /**
     * @var list<string> Names of the indexes to drop.
     */
    public private(set) array $droppedIndexes = [];

    public function __construct(public readonly string $name)
    {
    }

    public function id(string $name = 'id'): Column
    {
        return $this->columns[] = new Column($name, 'id');
    }

    public function foreignId(string $name): Column
    {
        return $this->columns[] = new Column($name, 'foreignId');
    }

    public function integer(string $name): Column
    {
        return $this->columns[] = new Column($name, 'integer');
    }

    public function bigInteger(string $name): Column
    {
        return $this->columns[] = new Column($name, 'bigInteger');
    }

    public function string(string $name, int $length = 255): Column
    {
        if ($length < 1) {
            throw new \InvalidArgumentException("The string column \"{$name}\" needs a length of at least 1.");
        }
        return $this->columns[] = new Column($name, 'string', $length);
    }

    public function text(string $name): Column
    {
        return $this->columns[] = new Column($name, 'text');
    }

    public function boolean(string $name): Column
    {
        return $this->columns[] = new Column($name, 'boolean');
    }

    public function decimal(string $name, int $total = 10, int $places = 2): Column
    {
        if ($total < 1 || $places < 0 || $places > $total) {
            throw new \InvalidArgumentException("The decimal column \"{$name}\" needs at least one digit, and no more after the point than it has.");
        }
        return $this->columns[] = new Column($name, 'decimal', $total, $places);
    }

    public function float(string $name): Column
    {
        return $this->columns[] = new Column($name, 'float');
    }

    public function date(string $name): Column
    {
        return $this->columns[] = new Column($name, 'date');
    }

    public function dateTime(string $name): Column
    {
        return $this->columns[] = new Column($name, 'dateTime');
    }

    public function json(string $name): Column
    {
        return $this->columns[] = new Column($name, 'json');
    }

    public function binary(string $name): Column
    {
        return $this->columns[] = new Column($name, 'binary');
    }

    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    public function index(string|array $columns, ?string $name = null): void
    {
        $columns = self::columns($columns);
        $this->indexes[] = ['unique' => false, 'columns' => $columns, 'name' => $name ?? $this->indexName($columns, 'index')];
    }

    public function unique(string|array $columns, ?string $name = null): void
    {
        $columns = self::columns($columns);
        $this->indexes[] = ['unique' => true, 'columns' => $columns, 'name' => $name ?? $this->indexName($columns, 'unique')];
    }

    public function primary(array $columns): void
    {
        $this->primary = self::columns($columns);
    }

    public function dropColumn(string ...$columns): void
    {
        $this->droppedColumns = [...$this->droppedColumns, ...array_values($columns)];
    }

    public function renameColumn(string $from, string $to): void
    {
        $this->renamedColumns[$from] = $to;
    }

    public function dropIndex(string|array $columns): void
    {
        $this->droppedIndexes[] = is_string($columns) ? $columns : $this->indexName(self::columns($columns), 'index');
    }

    public function dropUnique(string|array $columns): void
    {
        $this->droppedIndexes[] = is_string($columns) ? $columns : $this->indexName(self::columns($columns), 'unique');
    }

    /**
     * The name an index gets when it is not given one: users_email_unique.
     *
     * @param list<string> $columns
     */
    public function indexName(array $columns, string $kind): string
    {
        return $this->name . '_' . implode('_', $columns) . '_' . $kind;
    }

    /**
     * @param string|array<mixed> $columns
     * @return non-empty-list<string>
     */
    private static function columns(string|array $columns): array
    {
        $columns = array_values((array) $columns);
        if ($columns === [] || array_filter($columns, is_string(...)) !== $columns) {
            throw new \InvalidArgumentException('An index or key is over one column name or a list of them.');
        }
        return $columns;
    }
}
