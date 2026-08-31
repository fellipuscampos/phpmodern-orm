<?php

declare(strict_types=1);

namespace PhpModern\Orm\Tests\Fixtures;

use PhpModern\Orm\Model;

final class Post extends Model
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $title,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
    ) {
    }

    public static function table(): string
    {
        return 'posts';
    }

    protected static function timestamps(): bool
    {
        return true;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['title'],
            $row['created_at'] === null ? null : (string) $row['created_at'],
            $row['updated_at'] === null ? null : (string) $row['updated_at'],
        );
    }

    public function attributes(): array
    {
        return ['title' => $this->title];
    }
}
