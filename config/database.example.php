<?php
declare(strict_types=1);

/**
 * Plantilla de configuración de conexión a PostgreSQL
 * Copiar como config/database.php y ajustar credenciales locales
 */

return [
    'host' => '127.0.0.1',
    'port' => 5432,
    'dbname' => 'pos_supermercado',
    'user' => 'postgres',
    'password' => 'tu_contraseña_aqui',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
];
