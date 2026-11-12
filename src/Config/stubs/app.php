<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // Shown above the command list and when the server starts.
    'name' => env('APP_NAME', 'Crab'),

    // Show the details of a failure to the client. Never in production.
    'debug' => env('APP_DEBUG', false),

    // Where the application is reached, such as https://api.example.com; the start of every URL Urls writes for a named route.
    'url' => env('APP_URL'),

    // The secret things are signed with, such as cookie sessions: at least 32 bytes, kept out of
    // version control. php cua key:generate writes one into .env. Changing it ends every cookie session.
    'key' => env('APP_KEY'),

    // The time zone every date is made, stored and read in. UTC has no daylight saving, so no hour
    // is ever written twice; show a person their own time when the date is shown.
    'timezone' => env('APP_TIMEZONE', 'UTC'),
];
