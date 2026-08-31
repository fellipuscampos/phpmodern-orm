<?php

declare(strict_types=1);

namespace PhpModern\Orm;

use RuntimeException;

/**
 * Runs every seeder file in a directory, in filename order — unlike
 * MigrationRunner there's no "already applied" tracking table, since
 * seeding is meant to be re-run (rebuild demo/test data from scratch),
 * not applied exactly once.
 */
final class SeederRunner
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<string> names of the seeders that ran, in filename order */
    public function run(string $seedersDir): array
    {
        $names = $this->discover($seedersDir);

        foreach ($names as $name) {
            $this->load($seedersDir, $name)->run($this->connection);
        }

        return $names;
    }

    /** @return list<string> */
    private function discover(string $seedersDir): array
    {
        if (!is_dir($seedersDir)) {
            return [];
        }

        $files = glob($seedersDir . '/*.php') ?: [];
        $names = array_map(static fn (string $file): string => basename($file, '.php'), $files);
        sort($names);

        return $names;
    }

    private function load(string $seedersDir, string $name): Seeder
    {
        $seeder = require "{$seedersDir}/{$name}.php";

        if (!$seeder instanceof Seeder) {
            throw new RuntimeException(sprintf('Seeder file %s.php must return an instance of %s.', $name, Seeder::class));
        }

        return $seeder;
    }
}
