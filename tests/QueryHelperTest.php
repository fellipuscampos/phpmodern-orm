<?php

declare(strict_types=1);

namespace PhpModern\Orm\Tests;

use PhpModern\Orm\Comparison;
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

    public function test_null_condition_matches_is_null_not_equality(): void
    {
        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, deleted_at TEXT)');
        $connection->pdo()->exec("INSERT INTO comments (id, deleted_at) VALUES (1, NULL), (2, '2026-01-01')");
        $helper = new QueryHelper($connection);

        $active = $helper->findMany('comments', ['deleted_at' => null]);
        self::assertCount(1, $active);
        self::assertSame(1, $active[0]['id']);

        $one = $helper->findOneBy('comments', ['deleted_at' => null]);
        self::assertNotNull($one);
        self::assertSame(1, $one['id']);
    }

    public function test_update_can_filter_by_a_null_condition(): void
    {
        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, deleted_at TEXT)');
        $connection->pdo()->exec("INSERT INTO comments (id, deleted_at) VALUES (1, NULL), (2, NULL)");
        $helper = new QueryHelper($connection);

        $affected = $helper->update('comments', ['deleted_at' => '2026-01-01'], ['id' => 1, 'deleted_at' => null]);

        self::assertSame(1, $affected);
        self::assertSame([], $helper->findMany('comments', ['id' => 2, 'deleted_at' => '2026-01-01']));
    }

    public function test_paginate_returns_a_slice_and_the_total_count(): void
    {
        $page1 = $this->queryHelper->paginate('widgets', 1, 2);

        self::assertCount(2, $page1->items);
        self::assertSame(3, $page1->total);
        self::assertSame(1, $page1->page);
        self::assertSame(2, $page1->lastPage());
        self::assertTrue($page1->hasMorePages());

        $page2 = $this->queryHelper->paginate('widgets', 2, 2);

        self::assertCount(1, $page2->items);
        self::assertFalse($page2->hasMorePages());
    }

    public function test_paginate_honours_conditions(): void
    {
        $page = $this->queryHelper->paginate('widgets', 1, 10, ['name' => 'gear']);

        self::assertCount(1, $page->items);
        self::assertSame(1, $page->total);
    }

    public function test_paginate_can_order_results(): void
    {
        $descending = $this->queryHelper->paginate('widgets', 1, 10, [], 'id', 'DESC');

        self::assertSame([3, 2, 1], array_column($descending->items, 'id'));
    }

    public function test_paginate_rejects_an_invalid_sort_direction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->queryHelper->paginate('widgets', 1, 10, [], 'id', 'sideways');
    }

    public function test_find_many_can_order_results(): void
    {
        $descending = $this->queryHelper->findMany('widgets', [], 'id', 'DESC');

        self::assertSame([3, 2, 1], array_column($descending, 'id'));
    }

    public function test_insert_returns_the_new_row_id_and_persists_the_row(): void
    {
        $id = $this->queryHelper->insert('widgets', ['name' => 'sprocket']);

        self::assertSame(4, $id);
        $row = $this->queryHelper->findOneBy('widgets', ['id' => $id]);
        self::assertNotNull($row);
        self::assertSame('sprocket', $row['name']);
    }

    public function test_insert_with_timestamps_sets_created_at_and_updated_at(): void
    {
        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL, created_at TEXT, updated_at TEXT)',
        );
        $helper = new QueryHelper($connection);

        $id = $helper->insert('posts', ['title' => 'hello'], timestamps: true);

        $row = $helper->findOneBy('posts', ['id' => $id]);
        self::assertNotNull($row);
        self::assertNotNull($row['created_at']);
        self::assertSame($row['created_at'], $row['updated_at']);
    }

    public function test_update_with_timestamps_sets_updated_at_only(): void
    {
        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT NOT NULL, created_at TEXT, updated_at TEXT)',
        );
        $helper = new QueryHelper($connection);
        $id = $helper->insert('posts', ['title' => 'hello', 'created_at' => '2020-01-01 00:00:00'], timestamps: false);

        $helper->update('posts', ['title' => 'updated'], ['id' => $id], timestamps: true);

        $row = $helper->findOneBy('posts', ['id' => $id]);
        self::assertNotNull($row);
        self::assertSame('2020-01-01 00:00:00', $row['created_at']);
        self::assertNotSame('2020-01-01 00:00:00', $row['updated_at']);
    }

    public function test_comparison_greater_than_filters_rows(): void
    {
        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec('CREATE TABLE stock (id INTEGER PRIMARY KEY, quantity INTEGER NOT NULL)');
        $connection->pdo()->exec('INSERT INTO stock (quantity) VALUES (3), (10), (25)');
        $helper = new QueryHelper($connection);

        $rows = $helper->findMany('stock', ['quantity' => Comparison::greaterThan(5)]);

        self::assertSame([10, 25], array_column($rows, 'quantity'));
    }

    public function test_comparison_like_filters_rows(): void
    {
        $rows = $this->queryHelper->findMany('widgets', ['name' => Comparison::like('g%')]);

        self::assertSame(['gear'], array_column($rows, 'name'));
    }

    public function test_comparison_not_equal_filters_rows(): void
    {
        $rows = $this->queryHelper->findMany('widgets', ['name' => Comparison::notEqual('gear')], 'id');

        self::assertSame(['bolt', 'nut'], array_column($rows, 'name'));
    }

    public function test_comparison_works_inside_update_conditions(): void
    {
        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec('CREATE TABLE stock (id INTEGER PRIMARY KEY, quantity INTEGER NOT NULL)');
        $connection->pdo()->exec('INSERT INTO stock (quantity) VALUES (3), (10), (25)');
        $helper = new QueryHelper($connection);

        $affected = $helper->update('stock', ['quantity' => 0], ['quantity' => Comparison::lessThanOrEqual(10)]);

        self::assertSame(2, $affected);
        self::assertSame([0, 0, 25], array_column($helper->findMany('stock', [], 'id'), 'quantity'));
    }
}
