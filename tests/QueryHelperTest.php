<?php

declare(strict_types=1);

namespace PhpModern\Orm\Tests;

use PhpModern\Orm\Connection;
use PhpModern\Orm\QueryHelper;
use PHPUnit\Framework\TestCase;

final class QueryHelperTest extends TestCase
{
    private QueryHelper $queryHelper;

    protected function setUp(): void
    {
        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec('CREATE TABLE widgets (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
        $connection->pdo()->exec("INSERT INTO widgets (id, name) VALUES (1, 'gear'), (2, 'bolt'), (3, 'nut')");

        $this->queryHelper = new QueryHelper($connection);
    }

    public function test_find_many_without_conditions_returns_every_row(): void
    {
        $rows = $this->queryHelper->findMany('widgets');

        self::assertCount(3, $rows);
    }

    public function test_find_many_with_conditions_filters_rows(): void
    {
        $rows = $this->queryHelper->findMany('widgets', ['name' => 'gear']);

        self::assertCount(1, $rows);
        self::assertSame('gear', $rows[0]['name']);
    }

    public function test_find_many_where_in_matches_any_of_the_given_values(): void
    {
        $rows = $this->queryHelper->findManyWhereIn('widgets', 'id', [1, 3]);

        $names = array_column($rows, 'name');
        sort($names);
        self::assertSame(['gear', 'nut'], $names);
    }

    public function test_find_many_where_in_with_an_empty_list_returns_no_rows_and_no_query(): void
    {
        self::assertSame([], $this->queryHelper->findManyWhereIn('widgets', 'id', []));
    }
}
