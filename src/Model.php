<?php

declare(strict_types=1);

namespace PhpModern\Orm;

use RuntimeException;

/**
 * A minimal Active Record layer on top of QueryHelper, deliberately without
 * the magic property/attribute-bag approach Eloquent-style ORMs use
 * (`__get`/`__set`, dynamic attribute arrays). A subclass declares its own
 * readonly properties and three small methods (table(), attributes(),
 * fromRow()) — no reflection, no untyped array underneath, consistent with
 * the framework's "no mixed, no magic string DSL" rule everywhere else
 * (Comparison instead of a query DSL, Rule objects instead of a validation
 * DSL, and now this instead of dynamic attribute bags).
 *
 * save() is immutable, matching the rest of the framework's preference for
 * returning a new value over mutating one (Response::withHeader(), Store's
 * reducers): it re-fetches the row after inserting/updating and returns a
 * *new* instance built from it, rather than trying to mutate `readonly`
 * properties like `id`/`created_at` in place.
 */
abstract class Model
{
    private static ?QueryHelper $queryHelper = null;

    /**
     * Called once (e.g. in bootstrap) before any model queries — a single
     * shared QueryHelper for every Model subclass, the same "configure
     * once, use everywhere" shape Gate::define()/CsrfToken use.
     */
    public static function useQueryHelper(QueryHelper $queryHelper): void
    {
        self::$queryHelper = $queryHelper;
    }

    abstract public static function table(): string;

    abstract public function id(): ?int;

    /**
     * @param array<string, mixed> $row
     * @return static
     */
    abstract public static function fromRow(array $row): self;

    /** @return array<string, int|string|bool|null> every column except id */
    abstract public function attributes(): array;

    /**
     * Whether save() should stamp created_at/updated_at automatically —
     * override to true in a model whose table has those columns.
     */
    protected static function timestamps(): bool
    {
        return false;
    }

    /**
     * @return static|null
     */
    public static function find(int $id): ?self
    {
        $row = self::queryHelper()->findOneBy(static::table(), ['id' => $id]);

        return $row === null ? null : static::fromRow($row);
    }

    /**
     * @param array<string, int|string|bool|null|Comparison> $conditions
     * @return list<static>
     */
    public static function where(array $conditions = [], ?string $orderBy = null, string $direction = 'ASC'): array
    {
        $rows = self::queryHelper()->findMany(static::table(), $conditions, $orderBy, $direction);

        return array_map(static fn (array $row): self => static::fromRow($row), $rows);
    }

    /** @return list<static> */
    public static function all(?string $orderBy = null, string $direction = 'ASC'): array
    {
        return static::where([], $orderBy, $direction);
    }

    /**
     * Inserts (if id() is null) or updates this row, then re-fetches it so
     * the returned instance reflects exactly what the database now has —
     * the freshly assigned id on insert, real timestamp values either way.
     *
     * @return static
     */
    public function save(): self
    {
        $helper = self::queryHelper();
        $id = $this->id();

        if ($id === null) {
            $id = $helper->insert(static::table(), $this->attributes(), timestamps: static::timestamps());
        } else {
            $helper->update(static::table(), $this->attributes(), ['id' => $id], timestamps: static::timestamps());
        }

        $row = $helper->findOneBy(static::table(), ['id' => $id]);

        if ($row === null) {
            throw new RuntimeException(sprintf('%s::save() could not re-fetch row %d it just wrote.', static::class, $id));
        }

        return static::fromRow($row);
    }

    public function delete(): void
    {
        $id = $this->id();

        if ($id === null) {
            throw new RuntimeException(sprintf('Cannot delete a %s that was never saved.', static::class));
        }

        self::queryHelper()->delete(static::table(), ['id' => $id]);
    }

    private static function queryHelper(): QueryHelper
    {
        if (self::$queryHelper === null) {
            throw new RuntimeException(
                'Model::useQueryHelper() must be called before any model can query — usually once in bootstrap.',
            );
        }

        return self::$queryHelper;
    }
}
