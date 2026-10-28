<?php

declare(strict_types=1);

namespace Crab\Contracts\Database;

/**
 * One page of a query's rows, with where it stands among the rest. It can
 * be looped over and counted like the rows themselves, and returned as
 * JSON with the keys Laravel's paginator uses.
 *
 * @implements \IteratorAggregate<int, array<string, mixed>|object>
 */
final readonly class Page implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /**
     * The number of the last page, at least 1; null when the total was not counted.
     */
    public ?int $lastPage;
    /**
     * The position of this page's first row among all rows, from 1; null on an empty page.
     */
    public ?int $from;
    /**
     * The position of this page's last row; null on an empty page.
     */
    public ?int $to;

    /**
     * @param list<array<string, mixed>|object> $items The rows of this page, records after Query::into().
     * @param bool $hasMore Whether a page follows this one.
     * @param int|null $total Every row the query matches; null from simplePaginate(), which does not count them.
     */
    public function __construct(
        public array $items,
        public int $perPage,
        public int $currentPage,
        public bool $hasMore,
        public ?int $total = null,
    ) {
        $this->lastPage = $total === null ? null : max(1, (int) ceil($total / $perPage));
        $this->from = $items === [] ? null : (($currentPage - 1) * $perPage) + 1;
        $this->to = $items === [] ? null : $this->from + count($items) - 1;
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
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $json = [
            'data' => $this->items,
            'current_page' => $this->currentPage,
            'per_page' => $this->perPage,
            'from' => $this->from,
            'to' => $this->to,
            'has_more' => $this->hasMore,
        ];
        if ($this->total !== null) {
            $json['total'] = $this->total;
            $json['last_page'] = $this->lastPage;
        }
        return $json;
    }
}
