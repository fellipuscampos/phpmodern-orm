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

        $where = [];
        foreach (array_keys($conditions) as $column) {
            self::assertValidIdentifier($column);
            $where[] = "{$column} = :{$column}";
        }

        $sql = sprintf('SELECT * FROM %s WHERE %s LIMIT 1', $table, implode(' AND ', $where));
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($conditions);

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

            return $statement->fetchAll();
        }

        $where = [];
        foreach (array_keys($conditions) as $column) {
            self::assertValidIdentifier($column);
            $where[] = "{$column} = :{$column}";
        }

        $sql = sprintf('SELECT * FROM %s WHERE %s', $table, implode(' AND ', $where));
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($conditions);

        return $statement->fetchAll();
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
        $statement->execute(array_values($values));

        return $statement->fetchAll();
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

        $where = [];
        foreach ($conditions as $column => $value) {
            self::assertValidIdentifier($column);
            $where[] = "{$column} = :where_{$column}";
            $params["where_{$column}"] = $value;
        }

        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $where));
        $statement = $this->connection->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    private static function assertValidIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Invalid table/column identifier: {$identifier}");
        }
    }
}
