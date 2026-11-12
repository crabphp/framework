<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // Where sessions are kept: file, cookie or memory, or redis with the crabphp/redis package.
    'driver' => env('SESSION_DRIVER', 'file'),

    // Seconds a session lasts without being used.
    'lifetime' => 7200,

    // The file driver's folder; storage/sessions in the application when null.
    'directory' => null,

    // The secret the cookie driver signs its cookie with, at least 32 bytes.
    // Make one with: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    'key' => env('SESSION_KEY'),

    // The cookie's name; "__Host-session" when secure, "session" otherwise.
    'cookie' => null,

    // Whether the cookie is sent only over HTTPS.
    'secure' => true,

    // The chances that writing a session sweeps expired ones: 2 in 100. [0, 1] never does.
    'lottery' => [2, 100],
];
