<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // Where entries are kept: file, database or array, or redis with the crabphp/redis package.
    'store' => env('CACHE_STORE', 'file'),

    // The file store's folder; storage/cache in the application when null.
    'directory' => null,

    // The database store's table, and the name of the database it is in; the default one when null.
    'table' => 'cache',
    'database' => null,

    // Put before every key, to keep two applications apart in one store.
    'prefix' => '',

    // Seconds an entry lives when you give none; forever when null.
    'ttl' => null,
];
