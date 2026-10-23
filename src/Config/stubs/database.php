<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // Each database by a name of your choosing; reach another than the default with $database->connection('name').
    'connections' => [
        'main' => [
            // "sqlite:/path/to/file.sqlite", or "mysql:host=127.0.0.1;dbname=app". The kind of database is read from it.
            'dsn' => env('DB_DSN', 'sqlite:' . dirname(__DIR__) . '/storage/database.sqlite'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),

            // Further PDO attributes.
            'options' => [],

            // Use the nonblocking MySQL driver inside workers.
            'async' => false,

            // The most connections each worker opens, 10, or 1 for SQLite; and seconds to wait for a free one.
            // 'pool' => 10,
            'timeout' => 5.0,
            // Where `php cua make:migration` writes and `php cua migrate` reads: database/migrations for the
            // default database. Another database needs a folder of its own.
            // 'migrations' => dirname(__DIR__) . '/database/migrations',
        ],
    ],

    // The name of the database used when none is named; the first when null.
    'default' => null,

    // Seconds `migrate` waits for another run to finish.
    'migration_lock_timeout' => 60,
];
