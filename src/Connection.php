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

    /**
     * Runs $callback inside a transaction, committing on success and rolling
     * back if it throws — so callers never have to remember the try/catch
     * dance to avoid leaving a transaction half-open.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();

            throw $exception;
        }
    }
}
