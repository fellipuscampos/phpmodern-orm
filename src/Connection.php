<?php

declare(strict_types=1);

namespace PhpModern\Orm;

use PDO;

final class Connection
{
    private readonly PDO $pdo;

    public function __construct(string $dsn, string $username = '', string $password = '')
    {
        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function sqlite(string $path): self
    {
        return new self("sqlite:{$path}");
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
