<?php

declare(strict_types=1);

namespace PhpModern\Orm;

use InvalidArgumentException;

/**
 * Deliberately tiny query helper for the v0.1 proof of concept: only
 * findOneBy() and update(), both parameterized (never string-interpolated
 * values) and with identifier validation so table/column names can never
 * carry attacker-controlled SQL even if a caller passes untrusted keys.
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
