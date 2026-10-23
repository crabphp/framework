<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // local for this process, or redis across processes.
    'carrier' => env('BROADCAST_CARRIER', 'local'),

    // Prefix of Redis channels; a different one for each application sharing Redis.
    'prefix' => 'crab:broadcast:',

    // Joined channels per connection, and recipient writes before yielding to the event loop.
    'max_channels' => 64,
    'batch' => 128,

    // A worker's fan-out limit, and bytes allowed behind one recipient's output.
    'max_pending_bytes' => 1024 * 1024,
    'max_backlog_bytes' => 512 * 1024,
];
