<?php

declare(strict_types=1);

namespace PhpModern\Orm\Tests;

use InvalidArgumentException;
use PhpModern\Orm\Connection;
use PhpModern\Orm\QueryHelper;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    public function test_find_one_by_and_update(): void
    {
        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        $connection->pdo()->exec("INSERT INTO orders (id, status) VALUES (1, 'pending')");

        $helper = new QueryHelper($connection);

        $order = $helper->findOneBy('orders', ['id' => 1]);
        self::assertNotNull($order);
        self::assertSame('pending', $order['status']);

        $affected = $helper->update('orders', ['status' => 'shipped'], ['id' => 1]);
        self::assertSame(1, $affected);

        $updated = $helper->findOneBy('orders', ['id' => 1]);
        self::assertNotNull($updated);
        self::assertSame('shipped', $updated['status']);
    }

    public function test_rejects_invalid_identifiers(): void
    {
        $connection = Connection::sqlite(':memory:');
        $helper = new QueryHelper($connection);

        $this->expectException(InvalidArgumentException::class);

        $helper->findOneBy('orders; DROP TABLE orders', ['id' => 1]);
    }

    public function test_transaction_commits_and_returns_the_callback_result(): void
    {
        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');

        $result = $connection->transaction(function () use ($connection) {
            $connection->pdo()->exec("INSERT INTO orders (id, status) VALUES (1, 'pending')");

            return 'done';
        });

        self::assertSame('done', $result);

        $helper = new QueryHelper($connection);
        self::assertNotNull($helper->findOneBy('orders', ['id' => 1]));
    }

    public function test_transaction_rolls_back_on_exception(): void
    {
        $connection = Connection::sqlite(':memory:');
        $connection->pdo()->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');

        try {
            $connection->transaction(function () use ($connection): void {
                $connection->pdo()->exec("INSERT INTO orders (id, status) VALUES (1, 'pending')");

                throw new \RuntimeException('boom');
            });
            self::fail('Expected exception was not thrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        $helper = new QueryHelper($connection);
        self::assertNull($helper->findOneBy('orders', ['id' => 1]));
    }
}
