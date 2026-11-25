<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // Where the server listens.
    'address' => env('SERVER_ADDRESS', 'tcp://127.0.0.1:8080'),

    // Worker processes. More than one needs PHP's pcntl and posix extensions.
    'workers' => env('SERVER_WORKERS', 1),

    // What `php cua dev` watches, to restart the server when you save.
    'watch' => ['app', 'app.php', 'config', 'routes', '.env'],

    // Static files served from this folder after no application route matches. Set null to disable.
    'public' => 'public',

    // HTTP and upgraded connections per worker; at most 900 on the select loop.
    'max_connections' => 512,

    // A worker is replaced by a fresh one, with no request dropped, after about this many requests
    // or once PHP holds more memory than this for it; 0 turns either off. Only with a supervisor (pcntl).
    'max_requests' => env('SERVER_MAX_REQUESTS', 10_000),
    'max_memory' => env('SERVER_MAX_MEMORY', '128M'),

    // The PHP memory limit of each worker where php.ini sets none (-1), so one request cannot take the machine; 0 leaves it.
    'memory_limit' => env('SERVER_MEMORY_LIMIT', '512M'),

    // The event loop: auto is poll on PHP 8.6 and later, select before; or ev, uv or event, each needing its PHP extension.
    'loop' => 'auto',

    // The largest request body taken, in bytes, and how many seconds a client has to send one. Raise both for uploads.
    'max_body' => 1024 * 1024,
    'body_timeout' => 30,

    // Seconds to finish in-flight requests on stop or reload; connections still open at the limit are cut.
    'drain' => 5.0,

    // Where `php cua start --daemon` writes its process id and everything it prints.
    'pid' => 'storage/crab.pid',
    'log' => 'storage/logs/server.log',
];
