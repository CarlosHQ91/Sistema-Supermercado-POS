<?php
declare(strict_types=1);

/**
 * Script de inicialización automatizada de base de datos
 * Ejecución: php database/init_db.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Shared/Database.php';

use App\Shared\Database;

echo "========================================================\n";
echo " POS Supermercado - Inicialización de Base de Datos\n";
echo "========================================================\n\n";

try {
    $pdo = Database::getConnection();
    echo "[1/3] Conexión exitosa a PostgreSQL.\n";

    // 1. Ejecutar Schema
    $schemaFile = __DIR__ . '/schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException("No se encontró el archivo schema.sql en {$schemaFile}");
    }
    echo "[2/3] Ejecutando esquema DDL (schema.sql)...\n";
    $schemaSql = file_get_contents($schemaFile);
    $pdo->exec($schemaSql);
    echo "      -> Tablas, secuencias e índices creados correctamente.\n";

    // 2. Ejecutar Seeds
    $seedsFile = __DIR__ . '/seeds.sql';
    if (file_exists($seedsFile)) {
        echo "[3/3] Insertando datos semilla (seeds.sql)...\n";
        $seedsSql = file_get_contents($seedsFile);
        $pdo->exec($seedsSql);
        echo "      -> Datos maestros y usuarios cargados correctamente.\n";
    } else {
        echo "[3/3] seeds.sql omitido (no encontrado).\n";
    }

    echo "\n✔ Base de datos aprovisionada con éxito.\n";
    echo "========================================================\n";
} catch (Throwable $e) {
    echo "\n✖ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
