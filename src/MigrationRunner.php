<?php

declare(strict_types=1);

namespace PhpModern\Orm;

use RuntimeException;

/**
 * Applies migration files from a directory in filename order, tracking what
 * ran in a `phpmodern_migrations` table. Each migration file is a plain PHP
 * file that `return`s an anonymous class implementing Migration — no
 * separate registry, no autoload wiring per migration.
 */
final class MigrationRunner
{
    public function __construct(private readonly Connection $connection)
    {
        $this->connection->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS phpmodern_migrations (
                id INTEGER PRIMARY KEY,
                migration TEXT NOT NULL UNIQUE,
                applied_at TEXT NOT NULL
            )',
        );
    }

    /** @return list<string> migration names not yet applied, in filename order */
    public function pending(string $migrationsDir): array
    {
        return array_values(array_diff($this->discover($migrationsDir), $this->appliedNames()));
    }

    /** @return list<string> names of the migrations applied by this call */
    public function run(string $migrationsDir): array
    {
        $applied = [];

        foreach ($this->pending($migrationsDir) as $name) {
            $this->load($migrationsDir, $name)->up($this->connection);

            $statement = $this->connection->pdo()->prepare(
                'INSERT INTO phpmodern_migrations (migration, applied_at) VALUES (:migration, :applied_at)',
            );
            $statement->execute(['migration' => $name, 'applied_at' => date('Y-m-d H:i:s')]);

            $applied[] = $name;
        }

        return $applied;
    }

    /** @return string|null the name of the migration that was rolled back, or null if none had run */
    public function rollbackLast(string $migrationsDir): ?string
    {
        $statement = $this->connection->pdo()->query(
            'SELECT migration FROM phpmodern_migrations ORDER BY id DESC LIMIT 1',
        );
        $row = false;

        if ($statement !== false) {
            $row = $statement->fetch();
            $statement->closeCursor();
        }

        if ($row === false) {
            return null;
        }

        $name = $row['migration'];
        $this->load($migrationsDir, $name)->down($this->connection);

        $delete = $this->connection->pdo()->prepare('DELETE FROM phpmodern_migrations WHERE migration = :migration');
        $delete->execute(['migration' => $name]);

        return $name;
    }

    /** @return list<string> */
    private function discover(string $migrationsDir): array
    {
        if (!is_dir($migrationsDir)) {
            return [];
        }

        $files = glob($migrationsDir . '/*.php') ?: [];
        $names = array_map(static fn (string $file): string => basename($file, '.php'), $files);
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function appliedNames(): array
    {
        $statement = $this->connection->pdo()->query('SELECT migration FROM phpmodern_migrations');

        if ($statement === false) {
            return [];
        }

        /** @var list<string> */
        return array_column($statement->fetchAll(), 'migration');
    }

    private function load(string $migrationsDir, string $name): Migration
    {
        $migration = require "{$migrationsDir}/{$name}.php";

        if (!$migration instanceof Migration) {
            throw new RuntimeException(sprintf('Migration file %s.php must return an instance of %s.', $name, Migration::class));
        }

        return $migration;
    }
}
