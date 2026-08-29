<?php

declare(strict_types=1);

namespace PhpModern\Orm;

/**
 * A migration file returns an anonymous class implementing this interface —
 * the same convention Laravel adopted, chosen because it needs no class
 * name/autoload wiring per migration file.
 */
interface Migration
{
    public function up(Connection $connection): void;

    public function down(Connection $connection): void;
}
