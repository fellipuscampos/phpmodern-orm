<?php

declare(strict_types=1);

namespace PhpModern\Orm;

/**
 * A seeder file returns an instance of this interface — the same
 * no-class-name/autoload-wiring-per-file convention as Migration, for
 * repeatable demo/test data instead of hand-typed INSERT statements
 * scattered through bootstrap code.
 */
interface Seeder
{
    public function run(Connection $connection): void;
}
