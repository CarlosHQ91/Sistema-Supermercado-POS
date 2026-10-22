<?php
declare(strict_types=1);

namespace App\Shared;

use PDO;
use RuntimeException;

class Database
{
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            $config = require __DIR__ . '/../../config/database.php';
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $config['host'],
                $config['port'],
                $config['dbname']
            );
            try {
                self::$pdo = new PDO($dsn, $config['user'], $config['password'], $config['options']);
            } catch (\PDOException $e) {
                throw new RuntimeException("Error al conectar con la base de datos PostgreSQL: " . $e->getMessage());
            }
        }
        return self::$pdo;
    }

    /**
     * Ejecuta una función dentro de una transacción atómica BEGIN ... COMMIT / ROLLBACK
     */
    public static function transaction(callable $callback)
    {
        $pdo = self::getConnection();
        if ($pdo->inTransaction()) {
            return $callback($pdo);
        }

        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
