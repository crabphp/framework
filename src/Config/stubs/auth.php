<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // Positive whole seconds a single-use browser socket ticket lives.
    'ticket_ttl' => 30,
    // CookieAuth: the user's token in a browser cookie.
    'cookie' => [
        // The cookie's name: letters, digits, _, . and -. Null is __Host-auth when secure, which a sibling
        // subdomain cannot shadow, and auth over plain HTTP.
        'name' => null,
        // Seconds the browser keeps it; null keeps it until the browser closes.
        'max_age' => null,
        // Where a page that needs a user sends the browser instead of answering 401, such as '/login'.
        'login' => null,
        // Other origins whose pages may send requests that change something; app.url and the request's own always may.
        'origins' => [],
        // Whether it is sent only over HTTPS; null means yes unless app.url starts with http://.
        'secure' => null,
    ],
];
