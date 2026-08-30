<?php

declare(strict_types=1);

namespace PhpModern\Orm\Tests\Fixtures;

use PDOStatement;

/**
 * Installed via PDO::ATTR_STATEMENT_CLASS to count how many statements
 * PDO::prepare() actually creates — the only reliable way to prove
 * Relations::hasMany()/belongsTo() issue exactly one query, rather than
 * trusting that the implementation doesn't secretly loop.
 */
final class CountingStatement extends PDOStatement
{
    public static int $count = 0;

    protected function __construct()
    {
        self::$count++;
    }
}
