<?php

use Cycle\Database\Config;

return [
    'default' => 'default',
    'databases' => ['default' => ['connection' => 'mysql', 'driver' => 'mysql']],
    'drivers' => ['mysql' => new Config\MySQLDriverConfig(
        connection: new Config\MySQL\TcpConnectionConfig(
            database: 'benchmark',
            host: 'mysql',
            port: 23306,
            user: 'bench',
            password: 'bench',
        ),
        queryCache: true,
    )],
];
