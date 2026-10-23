<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // Where the server listens.
    'address' => env('SERVER_ADDRESS', 'tcp://127.0.0.1:8080'),

    // Worker processes. More than one needs PHP's pcntl and posix extensions.
    'workers' => env('SERVER_WORKERS', 1),

    // The servers `serve` runs beside HTTP, each switched on or off.
    'with' => [
        'queue' => env('SERVER_QUEUE', false),
        'schedule' => env('SERVER_SCHEDULE', false),
    ],

    // What `php cua serve` watches, to restart the server when you save. False to serve without watching.
    'watch' => ['app', 'app.php', 'config', 'routes', '.env'],

    // HTTP and upgraded connections per worker; at most 900 on the select loop.
    'max_connections' => 512,

    // The event loop: select, or ev, uv or event, each needing its PHP extension.
    'loop' => 'select',

    // The largest request body taken, in bytes, and how many seconds a client has to send one. Raise both for uploads.
    'max_body' => 1024 * 1024,
    'body_timeout' => 30,

    // Seconds to finish in-flight requests on stop or reload; connections still open at the limit are cut.
    'drain' => 5.0,

    // Where `php cua serve --daemon` writes its process id and everything it prints.
    'pid' => 'storage/crab.pid',
    'log' => 'storage/logs/server.log',
];
