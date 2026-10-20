<?php

return [
    'default' => [
        'driver' => 'mysql',
        'host' => 'mysql',
        'port' => 23306,
        'database' => 'benchmark',
        'username' => 'bench',
        'password' => 'bench',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'options' => [PDO::ATTR_EMULATE_PREPARES => false],
        'pool' => [
            'min_connections' => 1,
            'max_connections' => 10,
            'connect_timeout' => 10.0,
            'wait_timeout' => 5.0,
            'heartbeat' => -1,
            'max_idle_time' => 600.0,
        ],
    ],
];
