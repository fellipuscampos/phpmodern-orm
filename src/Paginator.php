<?php

declare(strict_types=1);

namespace PhpModern\Orm;

/**
 * @template TRow of array<string, mixed>
 */
final class Paginator
{
    /**
     * @param list<TRow> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    public function lastPage(): int
    {
        if ($this->perPage <= 0 || $this->total <= 0) {
            return 1;
        }

        return (int) ceil($this->total / $this->perPage);
    }

    public function hasMorePages(): bool
    {
        return $this->page < $this->lastPage();
    }
}
