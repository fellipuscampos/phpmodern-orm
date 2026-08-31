<?php

declare(strict_types=1);

namespace PhpModern\Orm;

use InvalidArgumentException;

/**
 * A tiny query helper: parameterized values (never string-interpolated) and
 * identifier validation, so table/column names can never carry
 * attacker-controlled SQL even if a caller passes untrusted keys.
 *
 * findMany()/findManyWhereIn() exist specifically so Relations::hasMany()/
 * belongsTo() can eager-load in one extra query instead of one per row —
 * see Relations for the N+1 story.
 *
 * There is no dedicated "soft delete" method: a soft-deleted row is just a
 * row where a `deleted_at` column is set (via update()) instead of removed,
 * and callers exclude them the same way they filter on anything else — by
 * passing ['deleted_at' => null] to findOneBy()/findMany(), which compiles
 * to `deleted_at IS NULL` rather than the always-false `deleted_at = NULL`.
 */
final class QueryHelper
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, int|string|bool|null> $conditions
     * @return array<string, mixed>|null
     */
    public function findOneBy(string $table, array $conditions): ?array
    {
        self::assertValidIdentifier($table);

        [$where, $params] = self::buildWhere($conditions);

        $sql = sprintf('SELECT * FROM %s WHERE %s LIMIT 1', $table, implode(' AND ', $where));
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($params);

        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param array<string, int|string|bool|null> $conditions
     * @return list<array<string, mixed>>
     */
    public function findMany(string $table, array $conditions = []): array
    {
        self::assertValidIdentifier($table);

        if ($conditions === []) {
            $statement = $this->connection->pdo()->prepare(sprintf('SELECT * FROM %s', $table));
            $statement->execute();

            return array_values($statement->fetchAll());
        }

        [$where, $params] = self::buildWhere($conditions);

        $sql = sprintf('SELECT * FROM %s WHERE %s', $table, implode(' AND ', $where));
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($params);

        return array_values($statement->fetchAll());
    }

    /**
     * Fetches every row where $column is one of $values — a single query
     * regardless of how many values are passed, the building block that
     * lets Relations load a whole batch of related rows at once.
     *
     * @param list<int|string> $values
     * @return list<array<string, mixed>>
     */
    public function findManyWhereIn(string $table, string $column, array $values): array
    {
        self::assertValidIdentifier($table);
        self::assertValidIdentifier($column);

        if ($values === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $sql = sprintf('SELECT * FROM %s WHERE %s IN (%s)', $table, $column, $placeholders);
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($values);

        return array_values($statement->fetchAll());
    }

    /**
     * Fetches one page of $table, plus the total row count needed to know
     * how many pages exist — always two queries, never a single query that
     * silently gets slower as the table grows.
     *
     * @param array<string, int|string|bool|null> $conditions
     * @return Paginator<array<string, mixed>>
     */
    public function paginate(
        string $table,
        int $page,
        int $perPage,
        array $conditions = [],
        ?string $orderBy = null,
        string $direction = 'ASC',
    ): Paginator {
        self::assertValidIdentifier($table);

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        if ($conditions === []) {
            $whereSql = '';
            $params = [];
        } else {
            [$where, $params] = self::buildWhere($conditions);
            $whereSql = ' WHERE ' . implode(' AND ', $where);
        }

        $orderSql = '';
        if ($orderBy !== null) {
            self::assertValidIdentifier($orderBy);
            $direction = strtoupper($direction);
            if ($direction !== 'ASC' && $direction !== 'DESC') {
                throw new InvalidArgumentException("Invalid sort direction: {$direction}");
            }
            $orderSql = " ORDER BY {$orderBy} {$direction}";
        }

        $countStatement = $this->connection->pdo()->prepare(
            sprintf('SELECT COUNT(*) AS total FROM %s%s', $table, $whereSql),
        );
        $countStatement->execute($params);
        $total = (int) $countStatement->fetch()['total'];

        $itemsStatement = $this->connection->pdo()->prepare(
            sprintf('SELECT * FROM %s%s%s LIMIT :limit OFFSET :offset', $table, $whereSql, $orderSql),
        );
        foreach ($params as $key => $value) {
            $itemsStatement->bindValue($key, $value);
        }
        $itemsStatement->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $itemsStatement->bindValue('offset', ($page - 1) * $perPage, \PDO::PARAM_INT);
        $itemsStatement->execute();

        return new Paginator(array_values($itemsStatement->fetchAll()), $total, $page, $perPage);
    }

    /**
     * @param array<string, int|string|bool|null> $values
     * @param array<string, int|string|bool|null> $conditions
     */
    public function update(string $table, array $values, array $conditions): int
    {
        self::assertValidIdentifier($table);

        $set = [];
        $params = [];
        foreach ($values as $column => $value) {
            self::assertValidIdentifier($column);
            $set[] = "{$column} = :set_{$column}";
            $params["set_{$column}"] = $value;
        }

        [$where, $whereParams] = self::buildWhere($conditions, 'where_');
        $params = [...$params, ...$whereParams];

        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $where));
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    /**
     * Builds WHERE fragments and their bound params, treating a null value
     * as `column IS NULL` — SQL's `column = NULL` is always unknown/false,
     * so a plain equality would silently match nothing.
     *
     * @param array<string, int|string|bool|null> $conditions
     * @return array{0: list<string>, 1: array<string, int|string|bool|null>}
     */
    private static function buildWhere(array $conditions, string $paramPrefix = ''): array
    {
        $where = [];
        $params = [];
        foreach ($conditions as $column => $value) {
            self::assertValidIdentifier($column);

            if ($value === null) {
                $where[] = "{$column} IS NULL";

                continue;
            }

            $paramName = "{$paramPrefix}{$column}";
            $where[] = "{$column} = :{$paramName}";
            $params[$paramName] = $value;
        }

        return [$where, $params];
    }

    private static function assertValidIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Invalid table/column identifier: {$identifier}");
        }
    }
}
