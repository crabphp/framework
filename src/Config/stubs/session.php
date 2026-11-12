<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // Where sessions are kept: file, cookie or memory, or redis with the crabphp/redis package.
    // The cookie driver signs its cookie with app.key; php cua key:generate writes one.
    'driver' => env('SESSION_DRIVER', 'file'),

    // Seconds a session lasts without being used.
    'lifetime' => 7200,

    // The file driver's folder; storage/sessions in the application when null.
    'directory' => null,

    // The cookie's name; "__Host-session" when secure, "session" otherwise.
    'cookie' => null,

    // Whether the cookie is sent only over HTTPS.
    'secure' => true,

    // The chances that writing a session sweeps expired ones: 2 in 100. [0, 1] never does.
    'lottery' => [2, 100],
];
