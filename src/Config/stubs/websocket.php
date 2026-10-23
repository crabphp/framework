<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // Seconds between pings; 0 switches them off.
    'ping' => 25,

    // The largest message in bytes, at most 256 KiB.
    'max_message' => 64 * 1024,

    // Seconds one send may wait for room before a slow reader is closed.
    'send_timeout' => 5.0,

    // Open or reserved socket handshakes per worker; at most server.max_connections.
    'max_connections' => 256,
];
