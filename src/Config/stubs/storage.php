<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // The disk used when you do not name one.
    'default' => env('STORAGE_DISK', 'local'),

    // Each disk's settings, by name, with its driver: local, memory, or s3 with the crabphp/s3 package.
    'disks' => [
        // root: the folder, storage/files in the application when null. url: where it is served from.
        'local' => ['driver' => 'local', 'root' => null, 'url' => null],
    ],
];
