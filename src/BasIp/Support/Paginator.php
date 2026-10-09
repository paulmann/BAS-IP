<?php

declare(strict_types=1);

namespace BasIp\Support;

use Countable;
use IteratorAggregate;
use ArrayIterator;
use Traversable;

/**
 * Offset paginator with a sliding window of page numbers.
 *
 * @implements IteratorAggregate<int, array<string, mixed>>
 */
final class Paginator implements IteratorAggregate, Countable
{
    private readonly int $perPage;
    private readonly int $lastPage;
    private readonly int $currentPage;

    /** @param array<int, mixed> $items */
    public function __construct(
        private readonly array $items,
        private readonly int $total,
        int $perPage,
        int $currentPage,
        private readonly string $pageName = 'page',
        private readonly int $onEachSide = 2,
        private readonly int $onEachEnd = 1,
    ) {
        // Clamped once here, then frozen: `readonly` cannot be reassigned after
        // promotion, so these three are initialised explicitly.
        $this->perPage     = max(1, $perPage);
        $this->lastPage    = max(1, (int) ceil($this->total / $this->perPage));
        $this->currentPage = max(1, min(max(1, $currentPage), $this->lastPage));
    }

    /** @return array<int, mixed> */
    public function items(): array
    {
        return $this->items;
    }

    public function total(): int       { return $this->total; }
    public function perPage(): int     { return $this->perPage; }
    public function currentPage(): int { return $this->currentPage; }
    public function lastPage(): int    { return $this->lastPage; }
    public function pageName(): string { return $this->pageName; }
    public function firstItem(): int   { return $this->total === 0 ? 0 : ($this->currentPage - 1) * $this->perPage + 1; }
    public function lastItem(): int    { return min($this->total, $this->firstItem() + count($this->items) - 1); }
    public function hasPages(): bool   { return $this->lastPage > 1; }
    public function hasMore(): bool    { return $this->currentPage < $this->lastPage; }
    public function hasPrevious(): bool { return $this->currentPage > 1; }
    public function isEmpty(): bool    { return $this->items === []; }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Page numbers with `null` marking an ellipsis gap.
     *
     * @return array<int, int|null>
     */
    public function pages(): array
    {
        $window = $this->onEachSide * 2;
        if ($this->lastPage <= $window + 4 + $this->onEachEnd * 2) {
            return range(1, $this->lastPage);
        }

        $pages = [];
        for ($i = 1; $i <= $this->onEachEnd; $i++) {
            $pages[] = $i;
        }
        $from = max($this->onEachEnd + 2, $this->currentPage - $this->onEachSide);
        $to   = min($this->lastPage - $this->onEachEnd - 1, $this->currentPage + $this->onEachSide);

        if ($from > $this->onEachEnd + 1) {
            $pages[] = null;
        }
        for ($i = $from; $i <= $to; $i++) {
            $pages[] = $i;
        }
        if ($to < $this->lastPage - $this->onEachEnd - 1) {
            $pages[] = null;
        }
        for ($i = $this->lastPage - $this->onEachEnd + 1; $i <= $this->lastPage; $i++) {
            if ($i > $this->onEachEnd) {
                $pages[] = $i;
            }
        }

        return array_values(array_unique($pages, SORT_REGULAR));
    }

    /**
     * Build a query string for a specific page, preserving current filters.
     *
     * @param array<string, mixed> $query
     */
    public function url(int $page, array $query = []): string
    {
        $query[$this->pageName] = $page;
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '' && $v !== []);

        return '?' . http_build_query($query);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items'        => $this->items,
            'total'        => $this->total,
            'per_page'     => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page'    => $this->lastPage,
            'from'         => $this->firstItem(),
            'to'           => $this->lastItem(),
        ];
    }
}
