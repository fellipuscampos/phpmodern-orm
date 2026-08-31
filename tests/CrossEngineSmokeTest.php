<?php

declare(strict_types=1);

namespace PhpModern\Orm\Tests;

use PDO;
use PhpModern\Orm\Comparison;
use PhpModern\Orm\Connection;
use PhpModern\Orm\QueryHelper;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exercises the ORM's core operations against whatever database
 * PHPMODERN_TEST_DSN points at — SQLite in-memory when unset, so this suite
 * runs everywhere by default, but CI also runs it against real PostgreSQL
 * and MySQL service containers (see .github/workflows/ci.yml) to catch
 * identifier/DDL/transaction behavior SQLite's forgiving type system
 * hides. Deliberately a separate, small smoke test rather than retrofitting
 * every existing ORM test to be multi-engine — those stay SQLite-only, and
 * fast.
 */
final class CrossEngineSmokeTest extends TestCase
{
    private Connection $connection;
    private QueryHelper $queryHelper;

    protected function setUp(): void
    {
        $dsn = getenv('PHPMODERN_TEST_DSN') ?: 'sqlite::memory:';
        $username = (string) (getenv('PHPMODERN_TEST_DB_USER') ?: '');
        $password = (string) (getenv('PHPMODERN_TEST_DB_PASS') ?: '');

        $this->connection = new Connection($dsn, $username, $password);
        $this->queryHelper = new QueryHelper($this->connection);

        $pdo = $this->connection->pdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $pdo->exec('DROP TABLE IF EXISTS smoke_widgets');
        $pdo->exec(match ($driver) {
            'mysql' => 'CREATE TABLE smoke_widgets (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                quantity INTEGER NOT NULL,
                created_at DATETIME NULL,
                updated_at DATETIME NULL
            )',
            'pgsql' => 'CREATE TABLE smoke_widgets (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                quantity INTEGER NOT NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )',
            'sqlite' => 'CREATE TABLE smoke_widgets (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                quantity INTEGER NOT NULL,
                created_at TEXT,
                updated_at TEXT
            )',
            default => throw new RuntimeException("Unsupported PDO driver for this smoke test: {$driver}"),
        });
    }

    protected function tearDown(): void
    {
        $this->connection->pdo()->exec('DROP TABLE IF EXISTS smoke_widgets');
    }

    public function test_insert_find_and_update_round_trip_with_timestamps(): void
    {
        $id = $this->queryHelper->insert('smoke_widgets', ['name' => 'gear', 'quantity' => 3], timestamps: true);
        self::assertGreaterThan(0, $id);

        $row = $this->queryHelper->findOneBy('smoke_widgets', ['id' => $id]);
        self::assertNotNull($row);
        self::assertSame('gear', $row['name']);
        self::assertNotNull($row['created_at']);

        $this->queryHelper->update('smoke_widgets', ['quantity' => 10], ['id' => $id], timestamps: true);

        $updated = $this->queryHelper->findOneBy('smoke_widgets', ['id' => $id]);
        self::assertNotNull($updated);
        self::assertSame(10, (int) $updated['quantity']);
    }

    public function test_comparison_and_order_by(): void
    {
        $this->queryHelper->insert('smoke_widgets', ['name' => 'a', 'quantity' => 3]);
        $this->queryHelper->insert('smoke_widgets', ['name' => 'b', 'quantity' => 10]);
        $this->queryHelper->insert('smoke_widgets', ['name' => 'c', 'quantity' => 25]);

        $rows = $this->queryHelper->findMany(
            'smoke_widgets',
            ['quantity' => Comparison::greaterThanOrEqual(10)],
            'quantity',
        );

        self::assertSame([10, 25], array_map(static fn (array $r): int => (int) $r['quantity'], $rows));
    }

    public function test_pagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->queryHelper->insert('smoke_widgets', ['name' => "item{$i}", 'quantity' => $i]);
        }

        $page = $this->queryHelper->paginate('smoke_widgets', 1, 2, [], 'quantity', 'ASC');

        self::assertCount(2, $page->items);
        self::assertSame(5, $page->total);
        self::assertSame(3, $page->lastPage());
    }

    public function test_transaction_commits_and_rolls_back(): void
    {
        $this->connection->transaction(function (): void {
            $this->queryHelper->insert('smoke_widgets', ['name' => 'committed', 'quantity' => 1]);
        });
        self::assertNotNull($this->queryHelper->findOneBy('smoke_widgets', ['name' => 'committed']));

        try {
            $this->connection->transaction(function (): void {
                $this->queryHelper->insert('smoke_widgets', ['name' => 'rolled-back', 'quantity' => 1]);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected — asserting the rollback below is the actual point
        }

        self::assertNull($this->queryHelper->findOneBy('smoke_widgets', ['name' => 'rolled-back']));
    }
}
