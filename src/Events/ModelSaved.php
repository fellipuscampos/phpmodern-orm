<?php

declare(strict_types=1);

namespace PhpModern\Orm\Events;

/**
 * Dispatched by Model::save() after the row has been written and re-fetched.
 * $wasCreated distinguishes an insert from an update — the same distinction
 * Eloquent's created/updated events split into two classes for, kept here as
 * one event with a flag since the payload (which model, which id) is
 * otherwise identical.
 */
final class ModelSaved
{
    /** @param class-string $model */
    public function __construct(
        public readonly string $model,
        public readonly int|string $id,
        public readonly bool $wasCreated,
    ) {
    }
}
