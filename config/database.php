<?php
declare(strict_types=1);

return [
    'host' => '127.0.0.1',
    'port' => 5432,
    'dbname' => 'pos_supermercado',
    'user' => 'postgres',
    'password' => 'root',
    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
];
