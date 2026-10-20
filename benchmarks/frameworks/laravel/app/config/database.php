<?php

declare(strict_types=1);

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', 'mysql'),
            'port' => env('DB_PORT', '23306'),
            'database' => env('DB_DATABASE', 'benchmark'),
            'username' => env('DB_USERNAME', 'bench'),
            'password' => env('DB_PASSWORD', 'bench'),
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [PDO::ATTR_PERSISTENT => true],
        ],
    ],
];
