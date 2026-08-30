<?php

declare(strict_types=1);

namespace PhpModern\Orm;

/**
 * Eager-loading for hasMany/belongsTo, attached onto plain arrays (this ORM
 * hydrates rows as arrays, not objects — see QueryHelper) under a chosen
 * key. Each call issues exactly one extra query, no matter how many parent
 * rows are passed in: it collects the distinct keys first, then does a
 * single findManyWhereIn() and groups the results in PHP. That single query
 * is the whole fix for the classic N+1 — one query per parent row — that a
 * naive "loop and query for each row" implementation would have.
 */
final class Relations
{
    /**
     * @param list<array<string, mixed>> $parents
     * @return list<array<string, mixed>>
     */
    public static function hasMany(
        QueryHelper $queryHelper,
        array $parents,
        string $parentKey,
        string $relatedTable,
        string $foreignKey,
        string $as,
    ): array {
        $ids = self::distinctKeys($parents, $parentKey);
        $related = $queryHelper->findManyWhereIn($relatedTable, $foreignKey, $ids);

        $grouped = [];
        foreach ($related as $row) {
            $grouped[$row[$foreignKey]][] = $row;
        }

        foreach ($parents as &$parent) {
            $parent[$as] = $grouped[$parent[$parentKey]] ?? [];
        }
        unset($parent);

        return $parents;
    }

    /**
     * @param list<array<string, mixed>> $children
     * @return list<array<string, mixed>>
     */
    public static function belongsTo(
        QueryHelper $queryHelper,
        array $children,
        string $foreignKey,
        string $relatedTable,
        string $ownerKey,
        string $as,
    ): array {
        $ids = self::distinctKeys($children, $foreignKey);
        $related = $queryHelper->findManyWhereIn($relatedTable, $ownerKey, $ids);

        $indexed = [];
        foreach ($related as $row) {
            $indexed[$row[$ownerKey]] = $row;
        }

        foreach ($children as &$child) {
            $child[$as] = $indexed[$child[$foreignKey]] ?? null;
        }
        unset($child);

        return $children;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int|string>
     */
    private static function distinctKeys(array $rows, string $key): array
    {
        /** @var list<int|string> */
        return array_values(array_unique(array_column($rows, $key)));
    }
}
