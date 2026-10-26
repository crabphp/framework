<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

/**
 * One page of a query's rows read from a cursor, and the cursor of the
 * page after it. It can be looped over and counted like the rows
 * themselves, and returned as JSON.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>>
 */
final readonly class CursorPage implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /**
     * Whether a page follows this one.
     */
    public bool $hasMore;

    /**
     * @param list<array<string, mixed>> $items The rows of this page.
     * @param string|null $nextCursor What reads the page after this one; null when this is the last.
     */
    public function __construct(
        public array $items,
        public int $perPage,
        public ?string $nextCursor,
    ) {
        $this->hasMore = $nextCursor !== null;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return array{data: list<array<string, mixed>>, per_page: int, next_cursor: string|null, has_more: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'data' => $this->items,
            'per_page' => $this->perPage,
            'next_cursor' => $this->nextCursor,
            'has_more' => $this->hasMore,
        ];
    }
}
