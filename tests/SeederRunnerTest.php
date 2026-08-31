<?php

declare(strict_types=1);

namespace PhpModern\Orm\Tests;

use PhpModern\Orm\Connection;
use PhpModern\Orm\SeederRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SeederRunnerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/phpmodern-seeders-test-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->tmpDir);
    }

    public function test_runs_every_seeder_in_filename_order(): void
    {
        $this->writeSeeder('01_widgets', <<<'PHP'
            <?php
            use PhpModern\Orm\Connection;
            use PhpModern\Orm\Seeder;
            return new class implements Seeder {
                public function run(Connection $connection): void {
                    $connection->pdo()->exec("INSERT INTO widgets (name) VALUES ('gear')");
                }
            };
            PHP);

        $this->writeSeeder('02_more_widgets', <<<'PHP'
            <?php
            use PhpModern\Orm\Connection;
            use PhpModern\Orm\Seeder;
            return new class implements Seeder {
                public function run(Connection $connection): void {
                    $connection->pdo()->exec("INSERT INTO widgets (name) VALUES ('bolt')");
                }
            };
            PHP);

        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');

        $ran = (new SeederRunner($connection))->run($this->tmpDir);

        self::assertSame(['01_widgets', '02_more_widgets'], $ran);
        self::assertSame('2', (string) $connection->pdo()->query('SELECT COUNT(*) FROM widgets')->fetchColumn());
    }

    public function test_running_again_re_seeds_rather_than_skipping(): void
    {
        $this->writeSeeder('01_widgets', <<<'PHP'
            <?php
            use PhpModern\Orm\Connection;
            use PhpModern\Orm\Seeder;
            return new class implements Seeder {
                public function run(Connection $connection): void {
                    $connection->pdo()->exec("INSERT INTO widgets (name) VALUES ('gear')");
                }
            };
            PHP);

        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $runner = new SeederRunner($connection);

        $runner->run($this->tmpDir);
        $runner->run($this->tmpDir);

        self::assertSame('2', (string) $connection->pdo()->query('SELECT COUNT(*) FROM widgets')->fetchColumn());
    }

    public function test_a_missing_directory_runs_nothing(): void
    {
        $connection = Connection::sqlite(':memory:');

        self::assertSame([], (new SeederRunner($connection))->run($this->tmpDir . '/does-not-exist'));
    }

    public function test_a_seeder_file_that_does_not_return_a_seeder_is_a_clear_error(): void
    {
        $this->writeSeeder('01_bad', "<?php\nreturn 'not a seeder';\n");

        $connection = Connection::sqlite(':memory:');

        $this->expectException(RuntimeException::class);
        (new SeederRunner($connection))->run($this->tmpDir);
    }

    private function writeSeeder(string $name, string $contents): void
    {
        file_put_contents("{$this->tmpDir}/{$name}.php", $contents);
    }
}
