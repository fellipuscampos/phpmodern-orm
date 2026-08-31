<?php

declare(strict_types=1);

namespace PhpModern\Orm\Events;

/** Dispatched by Model::delete() after the row has been removed. */
final class ModelDeleted
{
    /** @param class-string $model */
    public function __construct(
        public readonly string $model,
        public readonly int|string $id,
    ) {
    }
}
