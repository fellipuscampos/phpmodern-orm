<?php

declare(strict_types=1);

namespace PhpModern\Orm\Tests\Fixtures;

use PhpModern\Orm\Model;

final class Widget extends Model
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly int $quantity,
    ) {
    }

    public static function table(): string
    {
        return 'widgets';
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public static function fromRow(array $row): self
    {
        return new self((int) $row['id'], (string) $row['name'], (int) $row['quantity']);
    }

    public function attributes(): array
    {
        return ['name' => $this->name, 'quantity' => $this->quantity];
    }
}
