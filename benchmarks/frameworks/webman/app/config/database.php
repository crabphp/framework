<?php

return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => 'mysql',
            'port' => 23306,
            'database' => 'benchmark',
            'username' => 'bench',
            'password' => 'bench',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            'options' => [PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_PERSISTENT => true],
            'pool' => [
                'min_connections' => 1,
                'max_connections' => 10,
                'wait_timeout' => 5,
                'idle_timeout' => 600,
                'heartbeat_interval' => 50,
            ],
        ],
    ],
];
