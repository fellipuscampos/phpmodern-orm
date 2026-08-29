<?php

declare(strict_types=1);

namespace PhpModern\Orm\Tests;

use PDOException;
use PhpModern\Orm\Connection;
use PhpModern\Orm\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class MigrationRunnerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpmodern-migrations-test-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->tmpDir);
    }

    public function test_runs_pending_migrations_in_order_and_records_them(): void
    {
        $this->writeMigration('20260101000000_create_widgets', <<<'PHP'
            <?php
            use PhpModern\Orm\Connection;
            use PhpModern\Orm\Migration;
            return new class implements Migration {
                public function up(Connection $connection): void {
                    $connection->pdo()->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
                }
                public function down(Connection $connection): void {
                    $connection->pdo()->exec('DROP TABLE widgets');
                }
            };
            PHP);

        $this->writeMigration('20260101000001_seed_widget', <<<'PHP'
            <?php
            use PhpModern\Orm\Connection;
            use PhpModern\Orm\Migration;
            return new class implements Migration {
                public function up(Connection $connection): void {
                    $connection->pdo()->exec("INSERT INTO widgets (name) VALUES ('gear')");
                }
                public function down(Connection $connection): void {
                    $connection->pdo()->exec("DELETE FROM widgets WHERE name = 'gear'");
                }
            };
            PHP);

        $connection = Connection::sqlite(':memory:');
        $runner = new MigrationRunner($connection);

        $applied = $runner->run($this->tmpDir);

        self::assertSame(['20260101000000_create_widgets', '20260101000001_seed_widget'], $applied);

        $count = $connection->pdo()->query('SELECT COUNT(*) FROM widgets')->fetchColumn();
        self::assertSame('1', (string) $count);

        // Running again is a no-op: everything is already applied.
        self::assertSame([], $runner->run($this->tmpDir));
        self::assertSame([], $runner->pending($this->tmpDir));
    }

    public function test_rollback_last_reverts_only_the_most_recent_migration(): void
    {
        $this->writeMigration('20260101000000_create_widgets', <<<'PHP'
            <?php
            use PhpModern\Orm\Connection;
            use PhpModern\Orm\Migration;
            return new class implements Migration {
                public function up(Connection $connection): void {
                    $connection->pdo()->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY)');
                }
                public function down(Connection $connection): void {
                    $connection->pdo()->exec('DROP TABLE widgets');
                }
            };
            PHP);

        $this->writeMigration('20260101000001_create_gadgets', <<<'PHP'
            <?php
            use PhpModern\Orm\Connection;
            use PhpModern\Orm\Migration;
            return new class implements Migration {
                public function up(Connection $connection): void {
                    $connection->pdo()->exec('CREATE TABLE gadgets (id INTEGER PRIMARY KEY)');
                }
                public function down(Connection $connection): void {
                    $connection->pdo()->exec('DROP TABLE gadgets');
                }
            };
            PHP);

        $connection = Connection::sqlite(':memory:');
        $runner = new MigrationRunner($connection);
        $runner->run($this->tmpDir);

        $rolledBack = $runner->rollbackLast($this->tmpDir);

        self::assertSame('20260101000001_create_gadgets', $rolledBack);
        self::assertSame(['20260101000001_create_gadgets'], $runner->pending($this->tmpDir));

        // widgets (the earlier migration) must survive the rollback of gadgets.
        $connection->pdo()->exec('SELECT 1 FROM widgets');

        $this->expectException(PDOException::class);
        $connection->pdo()->exec('SELECT 1 FROM gadgets');
    }

    public function test_rollback_with_nothing_applied_returns_null(): void
    {
        $connection = Connection::sqlite(':memory:');
        $runner = new MigrationRunner($connection);

        self::assertNull($runner->rollbackLast($this->tmpDir));
    }

    private function writeMigration(string $name, string $contents): void
    {
        file_put_contents("{$this->tmpDir}/{$name}.php", $contents);
    }
}
