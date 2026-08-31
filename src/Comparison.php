<?php

declare(strict_types=1);

namespace PhpModern\Orm;

/**
 * A typed alternative to a magic-string query DSL like `"quantity > 5"`:
 * pass `Comparison::greaterThan(5)` as a condition's value instead of a
 * plain scalar, and buildWhere() emits the matching operator. Consistent
 * with the rest of the framework's "no string standing in for structure"
 * rule (see phpmodern/validation's Rule objects for the same idea).
 */
final class Comparison
{
    private function __construct(
        public readonly string $operator,
        public readonly int|string|bool $value,
    ) {
    }

    public static function greaterThan(int|string $value): self
    {
        return new self('>', $value);
    }

    public static function greaterThanOrEqual(int|string $value): self
    {
        return new self('>=', $value);
    }

    public static function lessThan(int|string $value): self
    {
        return new self('<', $value);
    }

    public static function lessThanOrEqual(int|string $value): self
    {
        return new self('<=', $value);
    }

    public static function notEqual(int|string|bool $value): self
    {
        return new self('!=', $value);
    }

    public static function like(string $pattern): self
    {
        return new self('LIKE', $pattern);
    }
}
